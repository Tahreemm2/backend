<?php
/**
 * =============================================================================
 * FILE: api/admin/schedules.php
 * PURPOSE: Meter Scheduling System (spec 3.2). Auto-generates quarterly
 * inspection schedules for consumers, supports manual override of any entry,
 * and lists schedules filterable by Division / Sub-division / Category (B1-B4).
 * Restricted to supervisory roles (SDO, XEN, SE, ADMIN) — not MT, and not
 * ADMIN-exclusive like the other admin/*.php endpoints. SDO's access is
 * VIEW-ONLY: generation, manual create, override, and delete are restricted
 * to XEN/SE/ADMIN ("SDO should not have access to... Schedule generation";
 * schedules are meant to be automatic, "with Admin involvement if needed").
 * SDO/XEN also get their own sub-division(s)/division enforced server-side
 * on every list/lookup regardless of what division/sub_division filter (if
 * any) they pass — see enforced_scope_sql() in helpers.php.
 *
 * REQUESTS (all require "Authorization: Bearer <token>" for a supervisory role):
 *
 *   GET    /api/admin/schedules.php
 *            ?division=&sub_division=&category=B2&quarter=2026-Q3&status=PENDING
 *            &page=&per_page=                          -> paginated, filtered list
 *          Every row includes auto_reassigned_at/auto_reassigned_from_name
 *          (both NULL if never auto-reassigned) — see
 *          run_auto_reassignment_sweep() in config/helpers.php.
 *   GET    /api/admin/schedules.php?id=5                -> single schedule entry
 *
 *   POST   /api/admin/schedules.php?action=generate      -> auto-generate a quarter's
 *          (XEN/SE/ADMIN only)
 *          Body: { "quarter": "2026-Q3" (optional, defaults to next quarter),
 *                  "division": "...", "sub_division": "...", "category": "B2" (all optional filters) }
 *          Creates one schedule row per matching consumer that doesn't already
 *          have one for that quarter (idempotent — safe to re-run).
 *
 *   POST   /api/admin/schedules.php                      -> manual single create (XEN/SE/ADMIN only)
 *          Body: { "consumer_id", "quarter", "scheduled_date", "category"? }
 *
 *   PUT    /api/admin/schedules.php?id=5                  -> manual override (XEN/SE/ADMIN only)
 *          Body: any of { "scheduled_date", "status", "override_reason", "category" }
 *          Always sets is_manual_override = 1.
 *
 *   DELETE /api/admin/schedules.php?id=5                  -> remove a schedule entry (XEN/SE/ADMIN only)
 * =============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helpers.php';

send_common_headers();

try {
    $pdo = get_db_connection();
} catch (PDOException $e) {
    json_error('Database connection failed. Please try again later.', 500, 'DB_CONNECTION_ERROR');
}

$currentUser = require_authenticated_user($pdo);
require_role($currentUser, SUPERVISORY_ROLES);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = strtolower(trim((string) ($_GET['action'] ?? '')));

const VALID_SCHEDULE_STATUSES = ['PENDING', 'ASSIGNED', 'COMPLETED', 'CANCELLED'];

switch ($method) {

    // -------------------------------------------------------------------
    case 'GET':
        $id = $_GET['id'] ?? null;

        if ($id !== null) {
            $stmt = $pdo->prepare(
                'SELECT s.*, c.reference_number, c.consumer_name, c.meter_id,
                        (SELECT ta.auto_reassigned_at FROM task_assignments ta
                          WHERE ta.schedule_id = s.id ORDER BY ta.id DESC LIMIT 1) AS auto_reassigned_at,
                        (SELECT reassigned_from.full_name FROM task_assignments ta
                          LEFT JOIN users reassigned_from ON reassigned_from.id = ta.auto_reassigned_from_user_id
                          WHERE ta.schedule_id = s.id ORDER BY ta.id DESC LIMIT 1) AS auto_reassigned_from_name
                 FROM schedules s
                 INNER JOIN consumers c ON c.id = s.consumer_id
                 WHERE s.id = :id LIMIT 1'
            );
            $stmt->execute(['id' => (int) $id]);
            $schedule = $stmt->fetch();

            if ($schedule === false) {
                json_error('Schedule entry not found.', 404, 'SCHEDULE_NOT_FOUND');
            }
            if (!is_within_enforced_scope($currentUser, $schedule)) {
                json_error('You do not have permission to view this schedule entry.', 403, 'FORBIDDEN_ROLE');
            }

            json_response(['success' => true, 'data' => $schedule]);
        }

        [$limit, $offset] = get_pagination();

        $conditions = [];
        $params = [];

        $division = trim((string) ($_GET['division'] ?? ''));
        if ($division !== '') {
            $conditions[] = 's.division = :division';
            $params['division'] = $division;
        }

        $subDivision = trim((string) ($_GET['sub_division'] ?? ''));
        if ($subDivision !== '') {
            $conditions[] = 's.sub_division = :sub_division';
            $params['sub_division'] = $subDivision;
        }

        $category = strtoupper(trim((string) ($_GET['category'] ?? '')));
        if ($category !== '') {
            $conditions[] = 's.category = :category';
            $params['category'] = $category;
        }

        $quarter = trim((string) ($_GET['quarter'] ?? ''));
        if ($quarter !== '') {
            $conditions[] = 's.quarter = :quarter';
            $params['quarter'] = $quarter;
        }

        $status = strtoupper(trim((string) ($_GET['status'] ?? '')));
        if ($status !== '') {
            $conditions[] = 's.status = :status';
            $params['status'] = $status;
        }

        // Server-side enforcement — see enforced_scope_sql() in helpers.php.
        [$scopeSql, $scopeParams] = enforced_scope_sql($currentUser, 's.division', 's.sub_division');
        if ($scopeSql !== '') {
            $conditions[] = $scopeSql;
            $params = array_merge($params, $scopeParams);
        }

        $whereSql = empty($conditions) ? '' : ('WHERE ' . implode(' AND ', $conditions));

        $stmt = $pdo->prepare(
            "SELECT s.*, c.reference_number, c.consumer_name, c.meter_id,
                    (SELECT ta.auto_reassigned_at FROM task_assignments ta
                      WHERE ta.schedule_id = s.id ORDER BY ta.id DESC LIMIT 1) AS auto_reassigned_at,
                    (SELECT reassigned_from.full_name FROM task_assignments ta
                      LEFT JOIN users reassigned_from ON reassigned_from.id = ta.auto_reassigned_from_user_id
                      WHERE ta.schedule_id = s.id ORDER BY ta.id DESC LIMIT 1) AS auto_reassigned_from_name
             FROM schedules s
             INNER JOIN consumers c ON c.id = s.consumer_id
             {$whereSql}
             ORDER BY s.scheduled_date ASC
             LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM schedules s {$whereSql}");
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $countStmt->execute();

        json_response([
            'success' => true,
            'data'    => $stmt->fetchAll(),
            'total'   => (int) $countStmt->fetchColumn(),
        ]);
        break;

    // -------------------------------------------------------------------
    case 'POST':
        if ($action === 'generate') {
            // "SDO should not have access to: ... Schedule generation" — an
            // SDO may VIEW their sub-division's schedule but not bulk-create
            // it; generation stays with XEN/SE/ADMIN (schedules are meant to
            // be automatic, "with Admin involvement if needed").
            require_role($currentUser, ['XEN', 'SE', 'ADMIN']);

            $body = get_json_body();

            $quarter = trim((string) ($body['quarter'] ?? ''));
            if ($quarter === '') {
                // Default to NEXT quarter — scheduling is normally done ahead of time.
                $quarter = quarter_string(date('Y-m-d', strtotime('+3 months')));
            } elseif (parse_quarter_string($quarter) === null) {
                json_error('quarter must be in "YYYY-Qn" format, e.g. "2026-Q3".', 422, 'VALIDATION_ERROR');
            }

            $filterDivision    = trim((string) ($body['division'] ?? ''));
            $filterSubDivision = trim((string) ($body['sub_division'] ?? ''));
            $filterCategory    = strtoupper(trim((string) ($body['category'] ?? '')));

            if ($filterCategory !== '' && !in_array($filterCategory, VALID_CATEGORIES, true)) {
                json_error('category must be one of: ' . implode(', ', VALID_CATEGORIES) . '.', 422, 'VALIDATION_ERROR');
            }

            $conditions = [];
            $params = [];

            if ($filterDivision !== '') {
                $conditions[] = 'division = :division';
                $params['division'] = $filterDivision;
            }
            if ($filterSubDivision !== '') {
                $conditions[] = 'sub_division = :sub_division';
                $params['sub_division'] = $filterSubDivision;
            }
            if ($filterCategory !== '') {
                $conditions[] = 'category = :category';
                $params['category'] = $filterCategory;
            }

            $whereSql = empty($conditions) ? '' : ('WHERE ' . implode(' AND ', $conditions));

            $stmt = $pdo->prepare("SELECT id, division, sub_division, category FROM consumers {$whereSql} ORDER BY id ASC");
            $stmt->execute($params);
            $consumers = $stmt->fetchAll();

            if (empty($consumers)) {
                json_response([
                    'success' => true,
                    'message' => 'No consumers matched the given filters — nothing to generate.',
                    'quarter' => $quarter,
                    'created' => 0,
                    'skipped_existing' => 0,
                ]);
            }

            // Skip consumers that already have a schedule row for this quarter
            // (UNIQUE KEY also enforces this, but pre-filtering avoids noisy
            // duplicate-key exceptions and lets us report an accurate count).
            $existingStmt = $pdo->prepare('SELECT consumer_id FROM schedules WHERE quarter = :quarter');
            $existingStmt->execute(['quarter' => $quarter]);
            $existingConsumerIds = array_flip(array_map('intval', $existingStmt->fetchAll(PDO::FETCH_COLUMN)));

            $toCreate = array_values(array_filter(
                $consumers,
                static fn(array $c): bool => !isset($existingConsumerIds[(int) $c['id']])
            ));

            $insert = $pdo->prepare(
                'INSERT INTO schedules
                    (consumer_id, quarter, division, sub_division, category, scheduled_date, status, generated_by_user_id)
                 VALUES
                    (:consumer_id, :quarter, :division, :sub_division, :category, :scheduled_date, \'PENDING\', :generated_by_user_id)'
            );

            $pdo->beginTransaction();
            try {
                $total = count($toCreate);
                foreach ($toCreate as $index => $consumer) {
                    $insert->execute([
                        'consumer_id'          => (int) $consumer['id'],
                        'quarter'              => $quarter,
                        'division'             => $consumer['division'],
                        'sub_division'         => $consumer['sub_division'],
                        'category'             => $consumer['category'],
                        'scheduled_date'       => distribute_schedule_date($quarter, $index, $total),
                        'generated_by_user_id' => $currentUser['id'],
                    ]);
                }
                $pdo->commit();
            } catch (PDOException $e) {
                $pdo->rollBack();
                throw $e;
            }

            json_response([
                'success'          => true,
                'message'          => "Generated {$total} schedule entr" . ($total === 1 ? 'y' : 'ies') . " for {$quarter}.",
                'quarter'          => $quarter,
                'created'          => $total,
                'skipped_existing' => count($consumers) - $total,
            ], 201);
        }

        // ---- Manual single create (no action param) --------------------------
        // Same reasoning as generate above — SDO's schedule access is
        // view-only per spec ("View quarterly inspection schedules... View
        // automatically reassigned inspections", nothing about creating one).
        require_role($currentUser, ['XEN', 'SE', 'ADMIN']);

        $body = get_json_body();

        $consumerId = (int) ($body['consumer_id'] ?? 0);
        $quarter = trim((string) ($body['quarter'] ?? ''));
        $scheduledDate = trim((string) ($body['scheduled_date'] ?? ''));
        $category = strtoupper(trim((string) ($body['category'] ?? '')));

        $errors = [];
        if ($consumerId <= 0) $errors['consumer_id'] = 'consumer_id is required.';
        if ($quarter === '' || parse_quarter_string($quarter) === null) $errors['quarter'] = 'quarter must be in "YYYY-Qn" format.';
        if ($scheduledDate === '' || strtotime($scheduledDate) === false) $errors['scheduled_date'] = 'A valid scheduled_date is required.';
        if ($category !== '' && !in_array($category, VALID_CATEGORIES, true)) $errors['category'] = 'category must be one of: ' . implode(', ', VALID_CATEGORIES) . '.';

        if (!empty($errors)) {
            json_response(['success' => false, 'message' => 'Validation failed.', 'errors' => $errors], 422);
        }

        $consumerStmt = $pdo->prepare('SELECT division, sub_division, category FROM consumers WHERE id = :id LIMIT 1');
        $consumerStmt->execute(['id' => $consumerId]);
        $consumer = $consumerStmt->fetch();

        if ($consumer === false) {
            json_error('No consumer found with that consumer_id.', 404, 'CONSUMER_NOT_FOUND');
        }

        try {
            $insert = $pdo->prepare(
                'INSERT INTO schedules
                    (consumer_id, quarter, division, sub_division, category, scheduled_date, status, generated_by_user_id)
                 VALUES
                    (:consumer_id, :quarter, :division, :sub_division, :category, :scheduled_date, \'PENDING\', :generated_by_user_id)'
            );
            $insert->execute([
                'consumer_id'          => $consumerId,
                'quarter'              => $quarter,
                'division'             => $consumer['division'],
                'sub_division'         => $consumer['sub_division'],
                'category'             => $category !== '' ? $category : $consumer['category'],
                'scheduled_date'       => date('Y-m-d', strtotime($scheduledDate)),
                'generated_by_user_id' => $currentUser['id'],
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                json_error('This consumer already has a schedule entry for that quarter.', 409, 'DUPLICATE_SCHEDULE');
            }
            throw $e;
        }

        json_response(['success' => true, 'message' => 'Schedule entry created.', 'id' => (int) $pdo->lastInsertId()], 201);
        break;

    // -------------------------------------------------------------------
    case 'PUT':
        // SDO's schedule access is view-only per spec — see the generate/POST notes above.
        require_role($currentUser, ['XEN', 'SE', 'ADMIN']);

        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            json_error('Query parameter "id" is required.', 422, 'VALIDATION_ERROR');
        }

        $stmt = $pdo->prepare('SELECT id, division, sub_division FROM schedules WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $scheduleForScopeCheck = $stmt->fetch();
        if ($scheduleForScopeCheck === false) {
            json_error('Schedule entry not found.', 404, 'SCHEDULE_NOT_FOUND');
        }
        if (!is_within_enforced_scope($currentUser, $scheduleForScopeCheck)) {
            json_error('You do not have permission to modify this schedule entry.', 403, 'FORBIDDEN_ROLE');
        }

        $body = get_json_body();
        $fields = ['is_manual_override = 1'];
        $params = ['id' => $id];

        if (isset($body['scheduled_date'])) {
            $raw = trim((string) $body['scheduled_date']);
            if ($raw === '' || strtotime($raw) === false) {
                json_error('scheduled_date must be a valid date.', 422, 'VALIDATION_ERROR');
            }
            $fields[] = 'scheduled_date = :scheduled_date';
            $params['scheduled_date'] = date('Y-m-d', strtotime($raw));
        }

        if (isset($body['status'])) {
            $status = strtoupper(trim((string) $body['status']));
            if (!in_array($status, VALID_SCHEDULE_STATUSES, true)) {
                json_error('status must be one of: ' . implode(', ', VALID_SCHEDULE_STATUSES) . '.', 422, 'VALIDATION_ERROR');
            }
            $fields[] = 'status = :status';
            $params['status'] = $status;
        }

        if (isset($body['category'])) {
            $category = strtoupper(trim((string) $body['category']));
            if ($category !== '' && !in_array($category, VALID_CATEGORIES, true)) {
                json_error('category must be one of: ' . implode(', ', VALID_CATEGORIES) . '.', 422, 'VALIDATION_ERROR');
            }
            $fields[] = 'category = :category';
            $params['category'] = $category !== '' ? $category : null;
        }

        if (isset($body['override_reason'])) {
            $fields[] = 'override_reason = :override_reason';
            $params['override_reason'] = trim((string) $body['override_reason']);
        }

        $fields[] = 'generated_by_user_id = :generated_by_user_id';
        $params['generated_by_user_id'] = $currentUser['id'];

        $sql = 'UPDATE schedules SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $pdo->prepare($sql)->execute($params);

        json_response(['success' => true, 'message' => 'Schedule entry updated (manual override recorded).']);
        break;

    // -------------------------------------------------------------------
    case 'DELETE':
        // SDO's schedule access is view-only per spec — see the generate/POST notes above.
        require_role($currentUser, ['XEN', 'SE', 'ADMIN']);

        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            json_error('Query parameter "id" is required.', 422, 'VALIDATION_ERROR');
        }

        $scopeCheckStmt = $pdo->prepare('SELECT id, division, sub_division FROM schedules WHERE id = :id LIMIT 1');
        $scopeCheckStmt->execute(['id' => $id]);
        $scheduleForScopeCheck = $scopeCheckStmt->fetch();
        if ($scheduleForScopeCheck === false) {
            json_error('Schedule entry not found.', 404, 'SCHEDULE_NOT_FOUND');
        }
        if (!is_within_enforced_scope($currentUser, $scheduleForScopeCheck)) {
            json_error('You do not have permission to delete this schedule entry.', 403, 'FORBIDDEN_ROLE');
        }

        $stmt = $pdo->prepare('DELETE FROM schedules WHERE id = :id');
        $stmt->execute(['id' => $id]);

        if ($stmt->rowCount() === 0) {
            json_error('Schedule entry not found.', 404, 'SCHEDULE_NOT_FOUND');
        }

        json_response(['success' => true, 'message' => 'Schedule entry deleted.']);
        break;

    default:
        json_error('Method not allowed. Use GET, POST, PUT, or DELETE.', 405, 'METHOD_NOT_ALLOWED');
}
