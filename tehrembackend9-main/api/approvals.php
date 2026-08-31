<?php
/**
 * =============================================================================
 * FILE: api/approvals.php
 * PURPOSE: Approval Workflow (spec 3.10). After a field team member (M&T)
 * submits an inspection (api/data.php?action=inspection-submit — "Level 1:
 * Submit"), it moves through a sequential chain of supervisory sign-offs:
 * SDO -> XEN -> SE. Which levels a given inspection must pass through is
 * configurable per meter category (approval_workflow_rules — see
 * api/admin/approval_rules.php), since "workflow depends on meter category".
 *
 * At any moment an inspection has ONE current_approval_level (1=SDO, 2=XEN,
 * 3=SE, or 0 once fully decided) and an overall_status
 * (PENDING_APPROVAL / APPROVED / REJECTED). Every decision is additionally
 * recorded, one row per level, in inspection_approvals — an immutable audit
 * trail independent of the current-state columns.
 *
 * REQUESTS (all require "Authorization: Bearer <token>"; supervisory roles
 * SDO/XEN/SE/ADMIN only — field team members do not review their own work):
 *
 *   GET  /api/approvals.php?status=PENDING&division=&sub_division=&category=&page=&per_page=
 *        -> SDO/XEN/SE: defaults (status=PENDING) to their own review queue —
 *           inspections currently sitting at their level. status=APPROVED or
 *           REJECTED instead shows inspections THEY previously decided.
 *        -> ADMIN: status filters the global inspection pool directly,
 *           unscoped to any one level.
 *   GET  /api/approvals.php?id=42
 *        -> single inspection with full readings, images, and its complete
 *           decision history. Any supervisory role may view (read-only);
 *           only the role matching current_approval_level may decide it.
 *
 *   POST /api/approvals.php?action=decide
 *        Body: { "inspection_id": 42, "decision": "APPROVE"|"REJECT", "remarks"? }
 *        -> Records the decision at the inspection's CURRENT level. On
 *           APPROVE, advances current_approval_level to the next level
 *           required by the consumer's category, or finalizes overall_status
 *           = APPROVED if none remain. On REJECT, finalizes overall_status =
 *           REJECTED immediately (the chain stops; no further levels decide).
 *        -> 403 FORBIDDEN_ROLE if the caller's role doesn't match the level
 *           currently pending (ADMIN may decide on behalf of any level).
 *        -> 409 if the inspection is already APPROVED/REJECTED.
 * =============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

send_common_headers();

try {
    $pdo = get_db_connection();
} catch (PDOException $e) {
    json_error('Database connection failed. Please try again later.', 500, 'DB_CONNECTION_ERROR');
}

$currentUser = require_authenticated_user($pdo);
require_role($currentUser, SUPERVISORY_ROLES);
$isAdmin = $currentUser['role_code'] === 'ADMIN';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

const VALID_APPROVAL_STATUS_FILTERS = ['PENDING', 'APPROVED', 'REJECTED'];
const VALID_APPROVAL_DECISIONS      = ['APPROVE', 'REJECT'];

const INSPECTION_SELECT_SQL = '
    SELECT
        i.id, i.reference_number, i.meter_id, i.consumer_account, i.inspection_datetime,
        i.gps_latitude, i.gps_longitude, i.gps_accuracy_meters,
        i.kwh, i.kvarh, i.mdi, i.load_details,
        i.tou_peak, i.tou_off_peak, i.tou_day, i.tou_night,
        i.seal_condition_code, i.ctpt_box_status_code, i.task_id,
        i.overall_status, i.current_approval_level, i.created_at,
        c.consumer_name, c.division, c.sub_division, c.category,
        submitter.full_name AS submitted_by_name
    FROM inspections i
    LEFT JOIN consumers c        ON c.reference_number = i.reference_number
    INNER JOIN users submitter   ON submitter.id = i.submitted_by_user_id
';

/**
 * Loads the ordered decision history for one inspection.
 *
 * @param PDO $pdo
 * @param int $inspectionId
 * @return array<int, array<string, mixed>>
 */
function load_approval_history(PDO $pdo, int $inspectionId): array
{
    $stmt = $pdo->prepare(
        'SELECT a.level, a.role_code, a.action, a.remarks, a.created_at,
                approver.full_name AS approver_name
         FROM inspection_approvals a
         INNER JOIN users approver ON approver.id = a.approver_user_id
         WHERE a.inspection_id = :inspection_id
         ORDER BY a.level ASC'
    );
    $stmt->execute(['inspection_id' => $inspectionId]);
    return $stmt->fetchAll();
}

switch ($method) {

    // -------------------------------------------------------------------
    case 'GET':
        $id = $_GET['id'] ?? null;

        if ($id !== null) {
            $stmt = $pdo->prepare(INSPECTION_SELECT_SQL . ' WHERE i.id = :id LIMIT 1');
            $stmt->execute(['id' => (int) $id]);
            $inspection = $stmt->fetch();

            if ($inspection === false) {
                json_error('Inspection not found.', 404, 'INSPECTION_NOT_FOUND');
            }
            if (!$isAdmin && !is_within_enforced_scope($currentUser, $inspection)) {
                json_error('You do not have permission to view this inspection.', 403, 'FORBIDDEN_ROLE');
            }

            $inspection = cast_decimal_fields($inspection, INSPECTION_DECIMAL_FIELDS);
            $inspection['pending_role'] = approval_role_for_level((int) $inspection['current_approval_level']);
            $inspection['history'] = load_approval_history($pdo, (int) $id);

            json_response(['success' => true, 'data' => $inspection]);
        }

        [$limit, $offset] = get_pagination();

        $conditions = [];
        $params = [];

        $status = strtoupper(trim((string) ($_GET['status'] ?? 'PENDING')));
        if (!in_array($status, VALID_APPROVAL_STATUS_FILTERS, true)) {
            json_error('status must be one of: ' . implode(', ', VALID_APPROVAL_STATUS_FILTERS) . '.', 422, 'VALIDATION_ERROR');
        }

        if ($status === 'PENDING') {
            $conditions[] = "i.overall_status = 'PENDING_APPROVAL'";
            if (!$isAdmin) {
                // SDO/XEN/SE only ever see inspections sitting at THEIR level.
                $myLevel = array_search($currentUser['role_code'], APPROVAL_LEVEL_ROLES, true);
                $conditions[] = 'i.current_approval_level = :my_level';
                $params['my_level'] = (int) $myLevel;
            }
        } else {
            // APPROVED / REJECTED
            $conditions[] = 'i.overall_status = :overall_status';
            $params['overall_status'] = $status;

            if (!$isAdmin) {
                // Non-admins only see the history of inspections THEY personally decided.
                $conditions[] = 'EXISTS (
                    SELECT 1 FROM inspection_approvals a
                    WHERE a.inspection_id = i.id AND a.approver_user_id = :approver_id AND a.action = :approver_action
                )';
                $params['approver_id'] = (int) $currentUser['id'];
                $params['approver_action'] = $status === 'APPROVED' ? 'APPROVED' : 'REJECTED';
            }
        }

        $division = trim((string) ($_GET['division'] ?? ''));
        if ($division !== '') {
            $conditions[] = 'c.division = :division';
            $params['division'] = $division;
        }

        $subDivision = trim((string) ($_GET['sub_division'] ?? ''));
        if ($subDivision !== '') {
            $conditions[] = 'c.sub_division = :sub_division';
            $params['sub_division'] = $subDivision;
        }

        $category = strtoupper(trim((string) ($_GET['category'] ?? '')));
        if ($category !== '') {
            if (!in_array($category, VALID_CATEGORIES, true)) {
                json_error('category must be one of: ' . implode(', ', VALID_CATEGORIES) . '.', 422, 'VALIDATION_ERROR');
            }
            $conditions[] = 'c.category = :category';
            $params['category'] = $category;
        }

        // Server-side enforcement — an SDO/XEN sees only their own
        // sub-division(s)/division regardless of the division/sub_division
        // filters above (which may only narrow further within that).
        [$scopeSql, $scopeParams] = enforced_scope_sql($currentUser, 'c.division', 'c.sub_division');
        if ($scopeSql !== '') {
            $conditions[] = $scopeSql;
            $params = array_merge($params, $scopeParams);
        }

        $whereSql = empty($conditions) ? '' : ('WHERE ' . implode(' AND ', $conditions));

        $stmt = $pdo->prepare(
            INSPECTION_SELECT_SQL . " {$whereSql} ORDER BY i.created_at ASC LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $countStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM inspections i LEFT JOIN consumers c ON c.reference_number = i.reference_number {$whereSql}"
        );
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $countStmt->execute();

        json_response([
            'success' => true,
            'data'    => array_map(
                static fn (array $row): array => cast_decimal_fields($row, INSPECTION_DECIMAL_FIELDS),
                $stmt->fetchAll()
            ),
            'total'   => (int) $countStmt->fetchColumn(),
        ]);
        break;

    // -------------------------------------------------------------------
    case 'POST':
        $action = strtolower(trim((string) ($_GET['action'] ?? '')));
        if ($action !== 'decide') {
            json_error("Unknown action '{$action}'. Expected: decide.", 400, 'UNKNOWN_ACTION');
        }

        $body = get_json_body();
        $inspectionId = (int) ($body['inspection_id'] ?? 0);
        $decision = strtoupper(trim((string) ($body['decision'] ?? '')));
        $remarks = trim((string) ($body['remarks'] ?? ''));

        $errors = [];
        if ($inspectionId <= 0) $errors['inspection_id'] = 'inspection_id is required.';
        if (!in_array($decision, VALID_APPROVAL_DECISIONS, true)) {
            $errors['decision'] = 'decision must be one of: ' . implode(', ', VALID_APPROVAL_DECISIONS) . '.';
        }
        if (!empty($errors)) {
            json_response(['success' => false, 'message' => 'Validation failed.', 'errors' => $errors], 422);
        }

        $stmt = $pdo->prepare(
            'SELECT i.id, i.overall_status, i.current_approval_level, i.task_id, i.submitted_by_user_id,
                    i.reference_number, c.consumer_name, c.category, c.division, c.sub_division
             FROM inspections i
             LEFT JOIN consumers c ON c.reference_number = i.reference_number
             WHERE i.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $inspectionId]);
        $inspection = $stmt->fetch();

        if ($inspection === false) {
            json_error('Inspection not found.', 404, 'INSPECTION_NOT_FOUND');
        }
        if (!$isAdmin && !is_within_enforced_scope($currentUser, $inspection)) {
            json_error('You do not have permission to decide this inspection.', 403, 'FORBIDDEN_ROLE');
        }

        if ($inspection['overall_status'] !== 'PENDING_APPROVAL') {
            json_error(
                "This inspection was already {$inspection['overall_status']} and cannot be decided again.",
                409,
                'INSPECTION_ALREADY_FINALIZED'
            );
        }

        $currentLevel = (int) $inspection['current_approval_level'];
        $requiredRole = approval_role_for_level($currentLevel);

        if ($requiredRole === null) {
            // Defensive: PENDING_APPROVAL should never carry level 0.
            json_error('This inspection has no pending approval level.', 409, 'INSPECTION_ALREADY_FINALIZED');
        }

        if (!$isAdmin && $currentUser['role_code'] !== $requiredRole) {
            json_error(
                "This inspection is currently awaiting {$requiredRole} approval, not {$currentUser['role_code']}.",
                403,
                'FORBIDDEN_ROLE'
            );
        }

        $pdo->beginTransaction();
        $taskReopened = false;
        try {
            $insertDecision = $pdo->prepare(
                'INSERT INTO inspection_approvals (inspection_id, level, role_code, action, approver_user_id, remarks)
                 VALUES (:inspection_id, :level, :role_code, :action, :approver_user_id, :remarks)'
            );
            $insertDecision->execute([
                'inspection_id'    => $inspectionId,
                'level'            => $currentLevel,
                'role_code'        => $requiredRole,
                'action'           => $decision === 'APPROVE' ? 'APPROVED' : 'REJECTED',
                'approver_user_id' => $currentUser['id'],
                'remarks'          => $remarks !== '' ? $remarks : null,
            ]);

            if ($decision === 'REJECT') {
                $newOverallStatus = 'REJECTED';
                $newLevel = $currentLevel; // preserved for audit — "rejected at level N"

                // Re-open the linked task (if any) so the M&T who submitted it
                // sees "Start Inspection" again instead of a dead-ended
                // COMPLETED task — inspection-submit refuses to attach a new
                // inspection to a COMPLETED task, so without this a rejected
                // submission could never be re-inspected.
                // Matched via task_assignments.inspection_id (set by the
                // submit flow itself) rather than inspections.task_id, so
                // this still works even if that column was left unset by
                // older/seeded data. Excludes CANCELLED only — a task a
                // supervisor deliberately cancelled stays cancelled.
                $reopenStmt = $pdo->prepare(
                    "UPDATE task_assignments SET status = 'PENDING'
                     WHERE inspection_id = :inspection_id AND status != 'CANCELLED'"
                );
                $reopenStmt->execute(['inspection_id' => $inspectionId]);
                $taskReopened = $reopenStmt->rowCount() > 0;
            } else {
                $requiredLevels = get_approval_required_levels($pdo, $inspection['category'] !== null ? (string) $inspection['category'] : null);
                $newLevel = next_approval_level($requiredLevels, $currentLevel);
                $newOverallStatus = $newLevel === 0 ? 'APPROVED' : 'PENDING_APPROVAL';
            }

            $pdo->prepare(
                'UPDATE inspections SET overall_status = :overall_status, current_approval_level = :level WHERE id = :id'
            )->execute([
                'overall_status' => $newOverallStatus,
                'level'          => $newLevel,
                'id'             => $inspectionId,
            ]);

            // Notify the submitting M&T of this decision — the feedback half
            // of the workflow (they otherwise only find out by reopening the
            // app and re-checking the status badge). Targeted at exactly one
            // person via recipient_user_id, so it isn't role/scope-gated the
            // way ESCALATION/DISCREPANCY rows are (see api/alerts.php).
            if ($inspection['submitted_by_user_id'] !== null) {
                $refLabel = ((string) $inspection['reference_number']) !== ''
                    ? (string) $inspection['reference_number']
                    : "inspection #{$inspectionId}";
                $consumerLabel = $inspection['consumer_name'] !== null ? " ({$inspection['consumer_name']})" : '';

                if ($decision === 'REJECT') {
                    $message = "Your inspection for {$refLabel}{$consumerLabel} was rejected by {$requiredRole}.";
                    if ($remarks !== '') {
                        $message .= " Remarks: {$remarks}";
                    }
                    $message .= ' You can inspect this meter again.';
                } elseif ($newOverallStatus === 'APPROVED') {
                    $message = "Your inspection for {$refLabel}{$consumerLabel} has been fully approved.";
                } else {
                    $nextRole = approval_role_for_level($newLevel);
                    $message = "Your inspection for {$refLabel}{$consumerLabel} was approved by {$requiredRole}"
                        . ($nextRole !== null ? " and forwarded to {$nextRole} for further approval." : '.');
                }

                $pdo->prepare(
                    'INSERT INTO notifications (type, inspection_id, recipient_user_id, message)
                     VALUES (\'INSPECTION_DECISION\', :inspection_id, :recipient_user_id, :message)'
                )->execute([
                    'inspection_id'     => $inspectionId,
                    'recipient_user_id' => (int) $inspection['submitted_by_user_id'],
                    'message'           => $message,
                ]);
            }

            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            if ($e->getCode() === '23000') {
                // Race: someone else decided this exact level between our SELECT and INSERT.
                json_error('This approval level was already decided by another reviewer.', 409, 'INSPECTION_ALREADY_FINALIZED');
            }
            throw $e;
        }

        json_response([
            'success'                => true,
            'message'                => $decision === 'APPROVE'
                ? ($newOverallStatus === 'APPROVED' ? 'Inspection fully approved.' : 'Approved — forwarded to the next level.')
                : 'Inspection rejected.',
            'overall_status'         => $newOverallStatus,
            'current_approval_level' => $newLevel,
            'task_reopened'          => $taskReopened,
        ]);
        break;

    default:
        json_error('Method not allowed. Use GET or POST.', 405, 'METHOD_NOT_ALLOWED');
}
