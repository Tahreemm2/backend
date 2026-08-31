<?php
/**
 * =============================================================================
 * FILE: api/discrepancies.php
 * PURPOSE: Discrepancy Reporting (spec 3.8). Field teams report issues found
 * during (or independent of) an inspection — theft, slowness, damage,
 * tampering, or abnormal readings — each with a description, severity level,
 * and optional photo evidence. Supervisory roles triage/resolve them.
 *
 * REQUESTS (all require "Authorization: Bearer <token>"):
 *
 *   GET    /api/discrepancies.php?status=&type=&severity=&division=&sub_division=&category=&assigned_to=&inspection_id=&page=&per_page=
 *          -> inspection_id: narrows to discrepancies linked to one inspection
 *             (used by the Inspection Reports detail view). Still subject to
 *             the same scope/ownership rules as every other filter here.
 *          -> MT: always scoped to discrepancies they personally reported.
 *          -> SDO/XEN: server-side enforced to their own sub-division(s)/division
 *             (division/sub_division params may narrow further within that,
 *             but can't widen it — see enforced_scope_sql() in helpers.php).
 *          -> SE/ADMIN: full list, optionally filtered.
 *          Every row includes assigned_to_user_id/assigned_to_name (the "Assigned
 *          M&T worker" per SRS) — distinct from reported_by_name/resolved_by_name.
 *   GET    /api/discrepancies.php?id=9                 -> single record (MT: own only)
 *
 *   POST   /api/discrepancies.php                       -> report a discrepancy
 *          Body: {
 *            "reference_number"? or "consumer_id"?,      (identifies the consumer; at least one recommended)
 *            "inspection_id"?,                           (link to the visit that found it, if any)
 *            "type": "THEFT"|"SLOWNESS"|"DAMAGE"|"TAMPERING"|"ABNORMAL_READING",
 *            "description": "...",
 *            "severity": "LOW"|"MEDIUM"|"HIGH"|"CRITICAL",
 *            "photo_evidence_base64"?: "data:image/jpeg;base64,...."
 *          }
 *          Also creates a DISCREPANCY-type row in `notifications`, visible to
 *          every supervisory role in scope (not level-gated like escalation) —
 *          see GET /api/alerts.php.
 *
 *   PUT    /api/discrepancies.php?id=9                   -> triage (supervisory roles only)
 *          Body: any of { "status": "UNDER_REVIEW"|"RESOLVED"|"DISMISSED", "resolution_notes",
 *                          "assigned_to_user_id" }        (must be an active MT user; SDO limited to own sub-division)
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
$isSupervisor = in_array($currentUser['role_code'], SUPERVISORY_ROLES, true);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

const VALID_DISCREPANCY_TYPES     = ['THEFT', 'SLOWNESS', 'DAMAGE', 'TAMPERING', 'ABNORMAL_READING'];
const VALID_DISCREPANCY_SEVERITY  = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];
const VALID_DISCREPANCY_STATUSES  = ['OPEN', 'UNDER_REVIEW', 'RESOLVED', 'DISMISSED'];

const DISCREPANCY_SELECT_SQL = '
    SELECT
        d.id, d.inspection_id, d.consumer_id, d.type, d.description, d.severity,
        d.photo_evidence_path, d.status, d.assigned_to_user_id, d.resolution_notes, d.created_at, d.updated_at,
        c.reference_number, c.consumer_name, c.division, c.sub_division, c.category,
        reporter.full_name AS reported_by_name,
        assignee.full_name AS assigned_to_name,
        resolver.full_name AS resolved_by_name
    FROM discrepancies d
    LEFT JOIN consumers c     ON c.id = d.consumer_id
    INNER JOIN users reporter ON reporter.id = d.reported_by_user_id
    LEFT JOIN users assignee  ON assignee.id = d.assigned_to_user_id
    LEFT JOIN users resolver  ON resolver.id = d.resolved_by_user_id
';

/**
 * Shapes one discrepancy row for JSON output, resolving photo_evidence_path
 * to a full public URL.
 */
function format_discrepancy(array $row): array
{
    $row['photo_evidence_url'] = build_upload_url($row['photo_evidence_path']);
    unset($row['photo_evidence_path']);
    return $row;
}

switch ($method) {

    // -------------------------------------------------------------------
    case 'GET':
        $id = $_GET['id'] ?? null;

        if ($id !== null) {
            $stmt = $pdo->prepare(DISCREPANCY_SELECT_SQL . ' WHERE d.id = :id LIMIT 1');
            $stmt->execute(['id' => (int) $id]);
            $discrepancy = $stmt->fetch();

            if ($discrepancy === false) {
                json_error('Discrepancy record not found.', 404, 'DISCREPANCY_NOT_FOUND');
            }

            // reported_by_user_id isn't in the SELECT list above, so re-check via a light lookup.
            $ownerStmt = $pdo->prepare('SELECT reported_by_user_id FROM discrepancies WHERE id = :id LIMIT 1');
            $ownerStmt->execute(['id' => (int) $id]);
            $ownerId = (int) $ownerStmt->fetchColumn();

            if (!$isSupervisor && $ownerId !== (int) $currentUser['id']) {
                json_error('You do not have permission to view this record.', 403, 'FORBIDDEN_ROLE');
            }
            if ($isSupervisor && !is_within_enforced_scope($currentUser, $discrepancy)) {
                json_error('You do not have permission to view this record.', 403, 'FORBIDDEN_ROLE');
            }

            json_response(['success' => true, 'data' => format_discrepancy($discrepancy)]);
        }

        [$limit, $offset] = get_pagination();

        $conditions = [];
        $params = [];

        if (!$isSupervisor) {
            $conditions[] = 'd.reported_by_user_id = :reported_by';
            $params['reported_by'] = (int) $currentUser['id'];
        } else {
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

            // Server-side enforcement — see enforced_scope_sql() in helpers.php.
            [$scopeSql, $scopeParams] = enforced_scope_sql($currentUser, 'c.division', 'c.sub_division');
            if ($scopeSql !== '') {
                $conditions[] = $scopeSql;
                $params = array_merge($params, $scopeParams);
            }
        }

        $assignedTo = (int) ($_GET['assigned_to'] ?? 0);
        if ($assignedTo > 0) {
            $conditions[] = 'd.assigned_to_user_id = :assigned_to';
            $params['assigned_to'] = $assignedTo;
        }

        // Used by the Inspection Reports detail view to show only the
        // discrepancies linked to one specific inspection. Applied on top of
        // (not instead of) the scope/ownership conditions above.
        $inspectionIdFilter = (int) ($_GET['inspection_id'] ?? 0);
        if ($inspectionIdFilter > 0) {
            $conditions[] = 'd.inspection_id = :inspection_id_filter';
            $params['inspection_id_filter'] = $inspectionIdFilter;
        }

        $category = strtoupper(trim((string) ($_GET['category'] ?? '')));
        if ($category !== '') {
            if (!in_array($category, VALID_CATEGORIES, true)) {
                json_error('category must be one of: ' . implode(', ', VALID_CATEGORIES) . '.', 422, 'VALIDATION_ERROR');
            }
            $conditions[] = 'c.category = :category';
            $params['category'] = $category;
        }

        $status = strtoupper(trim((string) ($_GET['status'] ?? '')));
        if ($status !== '') {
            if (!in_array($status, VALID_DISCREPANCY_STATUSES, true)) {
                json_error('status must be one of: ' . implode(', ', VALID_DISCREPANCY_STATUSES) . '.', 422, 'VALIDATION_ERROR');
            }
            $conditions[] = 'd.status = :status';
            $params['status'] = $status;
        }

        $type = strtoupper(trim((string) ($_GET['type'] ?? '')));
        if ($type !== '') {
            if (!in_array($type, VALID_DISCREPANCY_TYPES, true)) {
                json_error('type must be one of: ' . implode(', ', VALID_DISCREPANCY_TYPES) . '.', 422, 'VALIDATION_ERROR');
            }
            $conditions[] = 'd.type = :type';
            $params['type'] = $type;
        }

        $severity = strtoupper(trim((string) ($_GET['severity'] ?? '')));
        if ($severity !== '') {
            if (!in_array($severity, VALID_DISCREPANCY_SEVERITY, true)) {
                json_error('severity must be one of: ' . implode(', ', VALID_DISCREPANCY_SEVERITY) . '.', 422, 'VALIDATION_ERROR');
            }
            $conditions[] = 'd.severity = :severity';
            $params['severity'] = $severity;
        }

        $whereSql = empty($conditions) ? '' : ('WHERE ' . implode(' AND ', $conditions));

        $stmt = $pdo->prepare(
            DISCREPANCY_SELECT_SQL . " {$whereSql} ORDER BY FIELD(d.severity,'CRITICAL','HIGH','MEDIUM','LOW'), d.created_at DESC LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $countStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM discrepancies d LEFT JOIN consumers c ON c.id = d.consumer_id {$whereSql}"
        );
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $countStmt->execute();

        json_response([
            'success' => true,
            'data'    => array_map('format_discrepancy', $stmt->fetchAll()),
            'total'   => (int) $countStmt->fetchColumn(),
        ]);
        break;

    // -------------------------------------------------------------------
    case 'POST':
        $body = get_json_body();

        $type        = strtoupper(trim((string) ($body['type'] ?? '')));
        $description = trim((string) ($body['description'] ?? ''));
        $severity    = strtoupper(trim((string) ($body['severity'] ?? '')));
        $inspectionId = (int) ($body['inspection_id'] ?? 0);
        $consumerId   = (int) ($body['consumer_id'] ?? 0);
        $referenceNumber = trim((string) ($body['reference_number'] ?? ''));

        $errors = [];
        if (!in_array($type, VALID_DISCREPANCY_TYPES, true)) {
            $errors['type'] = 'type must be one of: ' . implode(', ', VALID_DISCREPANCY_TYPES) . '.';
        }
        if ($description === '') {
            $errors['description'] = 'description is required.';
        }
        if (!in_array($severity, VALID_DISCREPANCY_SEVERITY, true)) {
            $errors['severity'] = 'severity must be one of: ' . implode(', ', VALID_DISCREPANCY_SEVERITY) . '.';
        }

        if (!empty($errors)) {
            json_response(['success' => false, 'message' => 'Validation failed.', 'errors' => $errors], 422);
        }

        // Resolve consumer_id from reference_number if given, and/or from the
        // linked inspection if consumer_id wasn't provided directly.
        if ($consumerId <= 0 && $referenceNumber !== '') {
            $consumerStmt = $pdo->prepare('SELECT id FROM consumers WHERE reference_number = :ref LIMIT 1');
            $consumerStmt->execute(['ref' => strtoupper($referenceNumber)]);
            $consumerRow = $consumerStmt->fetch();
            if ($consumerRow === false) {
                json_error('No consumer found for that reference_number.', 404, 'CONSUMER_NOT_FOUND');
            }
            $consumerId = (int) $consumerRow['id'];
        }

        if ($inspectionId > 0) {
            $inspectionStmt = $pdo->prepare('SELECT id, consumer_account FROM inspections WHERE id = :id LIMIT 1');
            $inspectionStmt->execute(['id' => $inspectionId]);
            $inspectionRow = $inspectionStmt->fetch();
            if ($inspectionRow === false) {
                json_error('No inspection found with that inspection_id.', 404, 'INSPECTION_NOT_FOUND');
            }
        }

        $photoPath = null;
        $photoBase64 = trim((string) ($body['photo_evidence_base64'] ?? ''));
        if ($photoBase64 !== '') {
            try {
                $photoPath = store_base64_image($photoBase64, 'discrepancies');
            } catch (RuntimeException $e) {
                json_error($e->getMessage(), 422, 'IMAGE_VALIDATION_ERROR');
            }
        }

        $insert = $pdo->prepare(
            'INSERT INTO discrepancies
                (inspection_id, consumer_id, type, description, severity, photo_evidence_path, reported_by_user_id)
             VALUES
                (:inspection_id, :consumer_id, :type, :description, :severity, :photo_evidence_path, :reported_by_user_id)'
        );
        $insert->execute([
            'inspection_id'        => $inspectionId > 0 ? $inspectionId : null,
            'consumer_id'          => $consumerId > 0 ? $consumerId : null,
            'type'                 => $type,
            'description'          => $description,
            'severity'             => $severity,
            'photo_evidence_path'  => $photoPath,
            'reported_by_user_id'  => $currentUser['id'],
        ]);
        $discrepancyId = (int) $pdo->lastInsertId();

        // "New discrepancy notifications" (SRS Alerts & Notifications) — one
        // notification row, visible to every supervisory role whose scope
        // covers this consumer (not level-gated like the escalation chain in
        // api/alerts.php — a new discrepancy is relevant to SDO/XEN/SE alike,
        // not a graduated hand-off). Best-effort: a consumer-less report
        // (no reference_number/consumer_id given) can't be scoped, so it's
        // skipped rather than shown to everyone unscoped.
        if ($consumerId > 0) {
            $consumerScopeStmt = $pdo->prepare('SELECT division, sub_division, consumer_name, reference_number FROM consumers WHERE id = :id LIMIT 1');
            $consumerScopeStmt->execute(['id' => $consumerId]);
            $consumerScope = $consumerScopeStmt->fetch();

            if ($consumerScope !== false) {
                $notifyStmt = $pdo->prepare(
                    "INSERT INTO notifications (type, discrepancy_id, division, sub_division, message)
                     VALUES ('DISCREPANCY', :discrepancy_id, :division, :sub_division, :message)"
                );
                $notifyStmt->execute([
                    'discrepancy_id' => $discrepancyId,
                    'division'       => $consumerScope['division'],
                    'sub_division'   => $consumerScope['sub_division'],
                    'message'        => sprintf(
                        'New %s discrepancy reported for %s (%s): %s',
                        strtolower(str_replace('_', ' ', $severity)),
                        $consumerScope['reference_number'],
                        $consumerScope['consumer_name'],
                        ucwords(strtolower(str_replace('_', ' ', $type)))
                    ),
                ]);
            }
        }

        json_response([
            'success' => true,
            'message' => 'Discrepancy reported.',
            'id'      => $discrepancyId,
        ], 201);
        break;

    // -------------------------------------------------------------------
    case 'PUT':
        if (!$isSupervisor) {
            json_error('Only supervisory roles can triage a discrepancy report.', 403, 'FORBIDDEN_ROLE');
        }

        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            json_error('Query parameter "id" is required.', 422, 'VALIDATION_ERROR');
        }

        $stmt = $pdo->prepare(
            'SELECT d.id, c.division, c.sub_division FROM discrepancies d
             LEFT JOIN consumers c ON c.id = d.consumer_id
             WHERE d.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $discrepancyForScopeCheck = $stmt->fetch();
        if ($discrepancyForScopeCheck === false) {
            json_error('Discrepancy record not found.', 404, 'DISCREPANCY_NOT_FOUND');
        }
        if (!is_within_enforced_scope($currentUser, $discrepancyForScopeCheck)) {
            json_error('You do not have permission to triage this record.', 403, 'FORBIDDEN_ROLE');
        }

        $body = get_json_body();
        $fields = [];
        $params = ['id' => $id];

        if (isset($body['status'])) {
            $status = strtoupper(trim((string) $body['status']));
            if (!in_array($status, VALID_DISCREPANCY_STATUSES, true)) {
                json_error('status must be one of: ' . implode(', ', VALID_DISCREPANCY_STATUSES) . '.', 422, 'VALIDATION_ERROR');
            }
            $fields[] = 'status = :status';
            $params['status'] = $status;

            if (in_array($status, ['RESOLVED', 'DISMISSED'], true)) {
                $fields[] = 'resolved_by_user_id = :resolved_by_user_id';
                $params['resolved_by_user_id'] = $currentUser['id'];
            }
        }

        if (isset($body['resolution_notes'])) {
            $fields[] = 'resolution_notes = :resolution_notes';
            $params['resolution_notes'] = trim((string) $body['resolution_notes']) ?: null;
        }

        if (isset($body['assigned_to_user_id'])) {
            $newAssigneeId = (int) $body['assigned_to_user_id'];
            $assigneeStmt = $pdo->prepare('SELECT id, role_code, scope_name, is_active FROM users WHERE id = :id LIMIT 1');
            $assigneeStmt->execute(['id' => $newAssigneeId]);
            $assignee = $assigneeStmt->fetch();

            if ($assignee === false) {
                json_error('No user found with that assigned_to_user_id.', 404, 'USER_NOT_FOUND');
            }
            if ((int) $assignee['is_active'] === 0) {
                json_error('That user account is deactivated and cannot be assigned a discrepancy.', 422, 'USER_INACTIVE');
            }
            if ($assignee['role_code'] !== 'MT') {
                json_error('A discrepancy can only be assigned to an M&T (field) worker.', 422, 'VALIDATION_ERROR');
            }
            // Same reasoning as the equivalent check in api/tasks.php — an
            // SDO may only assign within their own sub-division; XEN/SE/
            // ADMIN aren't second-guessed further here.
            if ($currentUser['scope_code'] === 'SUB_DIVISION'
                && !is_within_enforced_scope($currentUser, ['division' => null, 'sub_division' => $assignee['scope_name']])
            ) {
                json_error('You can only assign a discrepancy to an M&T worker in your own sub-division.', 403, 'FORBIDDEN_ROLE');
            }

            $fields[] = 'assigned_to_user_id = :assigned_to_user_id';
            $params['assigned_to_user_id'] = $newAssigneeId;
        }

        if (empty($fields)) {
            json_error('No updatable fields were provided.', 422, 'VALIDATION_ERROR');
        }

        $sql = 'UPDATE discrepancies SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $pdo->prepare($sql)->execute($params);

        json_response(['success' => true, 'message' => 'Discrepancy record updated.']);
        break;

    default:
        json_error('Method not allowed. Use GET, POST, or PUT.', 405, 'METHOD_NOT_ALLOWED');
}
