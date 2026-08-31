<?php
/**
 * =============================================================================
 * FILE: api/tasks.php
 * PURPOSE: Task Assignment (spec 3.3). Supervisors (SDO/XEN/SE/ADMIN) assign
 * consumer/meter inspections to field team members (typically role MT); field
 * teams view their own assigned/pending/completed tasks. Unlike api/admin/*.php,
 * this endpoint is NOT admin-exclusive — access is role-scoped per method.
 *
 * REQUESTS (all require "Authorization: Bearer <token>"):
 *
 *   GET    /api/tasks.php?status=&assigned_to=&division=&sub_division=&page=&per_page=
 *          -> MT: always scoped to their own tasks (assigned_to is ignored/forced).
 *          -> Supervisory roles: full list, optionally filtered.
 *   GET    /api/tasks.php?id=7                        -> single task (MT: own only)
 *
 *   POST   /api/tasks.php                              -> assign a task (supervisory roles only)
 *          Body: { "consumer_id" or "schedule_id", "assigned_to_user_id", "due_date"?, "notes"? }
 *          If schedule_id is given, its schedule row is marked ASSIGNED.
 *
 *   PUT    /api/tasks.php?id=7                          -> update a task
 *          - The assignee may set { "status": "IN_PROGRESS" } on their own task.
 *          - Supervisory roles may set any of { "status","assigned_to_user_id","due_date","notes" }.
 *
 *   DELETE /api/tasks.php?id=7                          -> cancel a task (supervisory roles only)
 *          Soft-cancel (status = CANCELLED) rather than a hard delete, to
 *          preserve the assignment history/audit trail.
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

const VALID_TASK_STATUSES = ['PENDING', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'];

const TASK_SELECT_SQL = '
    SELECT
        t.id, t.schedule_id, t.consumer_id, t.assigned_to_user_id, t.assigned_by_user_id,
        t.auto_reassigned_at, t.auto_reassigned_from_user_id,
        t.status, t.due_date, t.notes, t.inspection_id, t.created_at, t.updated_at,
        c.reference_number, c.consumer_name, c.meter_id, c.division, c.sub_division,
        assignee.full_name AS assigned_to_name,
        assigner.full_name AS assigned_by_name,
        reassigned_from.full_name AS auto_reassigned_from_name,
        i.overall_status AS inspection_overall_status
    FROM task_assignments t
    INNER JOIN consumers c    ON c.id = t.consumer_id
    INNER JOIN users assignee ON assignee.id = t.assigned_to_user_id
    INNER JOIN users assigner ON assigner.id = t.assigned_by_user_id
    LEFT JOIN  users reassigned_from ON reassigned_from.id = t.auto_reassigned_from_user_id
    LEFT JOIN  inspections i  ON i.id = t.inspection_id
';

switch ($method) {

    // -------------------------------------------------------------------
    case 'GET':
        $id = $_GET['id'] ?? null;

        if ($id !== null) {
            $stmt = $pdo->prepare(TASK_SELECT_SQL . ' WHERE t.id = :id LIMIT 1');
            $stmt->execute(['id' => (int) $id]);
            $task = $stmt->fetch();

            if ($task === false) {
                json_error('Task not found.', 404, 'TASK_NOT_FOUND');
            }

            if (!$isSupervisor && (int) $task['assigned_to_user_id'] !== (int) $currentUser['id']) {
                json_error('You do not have permission to view this task.', 403, 'FORBIDDEN_ROLE');
            }
            if ($isSupervisor && !is_within_enforced_scope($currentUser, $task)) {
                json_error('You do not have permission to view this task.', 403, 'FORBIDDEN_ROLE');
            }

            json_response(['success' => true, 'data' => $task]);
        }

        [$limit, $offset] = get_pagination();

        // Opportunistic sweep — see run_auto_reassignment_sweep() in
        // helpers.php for why this runs here instead of a cron job. Only
        // meaningful for supervisory roles (who assign/reassign work); an
        // MT's own task list doesn't need to trigger it.
        if ($isSupervisor) {
            run_auto_reassignment_sweep($pdo);
        }

        $conditions = [];
        $params = [];

        if ($isSupervisor) {
            $assignedTo = (int) ($_GET['assigned_to'] ?? 0);
            if ($assignedTo > 0) {
                $conditions[] = 't.assigned_to_user_id = :assigned_to';
                $params['assigned_to'] = $assignedTo;
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

            // Server-side enforcement — an SDO/XEN sees only their own
            // sub-division(s)/division no matter what (or nothing) the
            // client requests above. SE/ADMIN are unrestricted.
            [$scopeSql, $scopeParams] = enforced_scope_sql($currentUser, 'c.division', 'c.sub_division');
            if ($scopeSql !== '') {
                $conditions[] = $scopeSql;
                $params = array_merge($params, $scopeParams);
            }
        } else {
            // Field team members only ever see their own tasks — no override.
            $conditions[] = 't.assigned_to_user_id = :assigned_to';
            $params['assigned_to'] = (int) $currentUser['id'];
        }

        $status = strtoupper(trim((string) ($_GET['status'] ?? '')));
        if ($status !== '') {
            if (!in_array($status, VALID_TASK_STATUSES, true)) {
                json_error('status must be one of: ' . implode(', ', VALID_TASK_STATUSES) . '.', 422, 'VALIDATION_ERROR');
            }
            $conditions[] = 't.status = :status';
            $params['status'] = $status;
        }

        $whereSql = empty($conditions) ? '' : ('WHERE ' . implode(' AND ', $conditions));

        $stmt = $pdo->prepare(
            TASK_SELECT_SQL . " {$whereSql} ORDER BY (t.due_date IS NULL), t.due_date ASC, t.created_at DESC LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM task_assignments t INNER JOIN consumers c ON c.id = t.consumer_id {$whereSql}");
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
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
        if (!$isSupervisor) {
            json_error('Only supervisory roles (SDO/XEN/SE/ADMIN) can assign tasks.', 403, 'FORBIDDEN_ROLE');
        }

        $body = get_json_body();

        $scheduleId = (int) ($body['schedule_id'] ?? 0);
        $consumerId = (int) ($body['consumer_id'] ?? 0);
        $assignedToUserId = (int) ($body['assigned_to_user_id'] ?? 0);
        $dueDateRaw = trim((string) ($body['due_date'] ?? ''));
        $notes = trim((string) ($body['notes'] ?? ''));

        $errors = [];
        if ($assignedToUserId <= 0) $errors['assigned_to_user_id'] = 'assigned_to_user_id is required.';
        if ($consumerId <= 0 && $scheduleId <= 0) $errors['consumer_id'] = 'Either consumer_id or schedule_id is required.';
        if ($dueDateRaw !== '' && strtotime($dueDateRaw) === false) $errors['due_date'] = 'due_date must be a valid date.';

        if (!empty($errors)) {
            json_response(['success' => false, 'message' => 'Validation failed.', 'errors' => $errors], 422);
        }

        // If a schedule_id was given, derive (and validate) the consumer from it.
        if ($scheduleId > 0) {
            $scheduleStmt = $pdo->prepare(
                'SELECT s.id, s.consumer_id, s.status, c.division, c.sub_division
                 FROM schedules s INNER JOIN consumers c ON c.id = s.consumer_id
                 WHERE s.id = :id LIMIT 1'
            );
            $scheduleStmt->execute(['id' => $scheduleId]);
            $schedule = $scheduleStmt->fetch();

            if ($schedule === false) {
                json_error('No schedule entry found with that schedule_id.', 404, 'SCHEDULE_NOT_FOUND');
            }
            if (!is_within_enforced_scope($currentUser, $schedule)) {
                json_error('You do not have permission to assign work for this schedule entry.', 403, 'FORBIDDEN_ROLE');
            }
            $consumerId = (int) $schedule['consumer_id'];
        } else {
            $consumerStmt = $pdo->prepare('SELECT id, division, sub_division FROM consumers WHERE id = :id LIMIT 1');
            $consumerStmt->execute(['id' => $consumerId]);
            $consumerRow = $consumerStmt->fetch();
            if ($consumerRow === false) {
                json_error('No consumer found with that consumer_id.', 404, 'CONSUMER_NOT_FOUND');
            }
            if (!is_within_enforced_scope($currentUser, $consumerRow)) {
                json_error('You do not have permission to assign work for this consumer.', 403, 'FORBIDDEN_ROLE');
            }
        }

        $assigneeStmt = $pdo->prepare('SELECT id, is_active, scope_code, scope_name FROM users WHERE id = :id LIMIT 1');
        $assigneeStmt->execute(['id' => $assignedToUserId]);
        $assignee = $assigneeStmt->fetch();

        if ($assignee === false) {
            json_error('No user found with that assigned_to_user_id.', 404, 'USER_NOT_FOUND');
        }
        if ((int) $assignee['is_active'] === 0) {
            json_error('That user account is deactivated and cannot receive new tasks.', 422, 'USER_INACTIVE');
        }
        // Scoped to SDO specifically (not XEN) — validating "is this worker's
        // sub-division inside my division" would need a division<->sub-division
        // mapping table that doesn't exist yet, so XEN/SE/ADMIN assignment
        // isn't second-guessed here; the consumer/schedule scope check above
        // already covers the "what" being assigned for every role.
        if ($currentUser['scope_code'] === 'SUB_DIVISION'
            && !is_within_enforced_scope($currentUser, ['division' => null, 'sub_division' => $assignee['scope_name']])
        ) {
            json_error('You can only assign work to field team members in your own sub-division.', 403, 'FORBIDDEN_ROLE');
        }

        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare(
                'INSERT INTO task_assignments
                    (schedule_id, consumer_id, assigned_to_user_id, assigned_by_user_id, due_date, notes)
                 VALUES
                    (:schedule_id, :consumer_id, :assigned_to_user_id, :assigned_by_user_id, :due_date, :notes)'
            );
            $insert->execute([
                'schedule_id'         => $scheduleId > 0 ? $scheduleId : null,
                'consumer_id'         => $consumerId,
                'assigned_to_user_id' => $assignedToUserId,
                'assigned_by_user_id' => $currentUser['id'],
                'due_date'            => $dueDateRaw !== '' ? date('Y-m-d', strtotime($dueDateRaw)) : null,
                'notes'               => $notes !== '' ? $notes : null,
            ]);
            $taskId = (int) $pdo->lastInsertId();

            if ($scheduleId > 0) {
                $pdo->prepare('UPDATE schedules SET status = \'ASSIGNED\' WHERE id = :id')->execute(['id' => $scheduleId]);
            }

            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            throw $e;
        }

        json_response(['success' => true, 'message' => 'Task assigned.', 'id' => $taskId], 201);
        break;

    // -------------------------------------------------------------------
    case 'PUT':
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            json_error('Query parameter "id" is required.', 422, 'VALIDATION_ERROR');
        }

        $stmt = $pdo->prepare(
            'SELECT t.*, c.division, c.sub_division FROM task_assignments t
             INNER JOIN consumers c ON c.id = t.consumer_id
             WHERE t.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $task = $stmt->fetch();

        if ($task === false) {
            json_error('Task not found.', 404, 'TASK_NOT_FOUND');
        }

        $isOwnTask = (int) $task['assigned_to_user_id'] === (int) $currentUser['id'];
        if (!$isSupervisor && !$isOwnTask) {
            json_error('You do not have permission to update this task.', 403, 'FORBIDDEN_ROLE');
        }
        if ($isSupervisor && !is_within_enforced_scope($currentUser, $task)) {
            json_error('You do not have permission to update this task.', 403, 'FORBIDDEN_ROLE');
        }

        $body = get_json_body();
        $fields = [];
        $params = ['id' => $id];

        if (isset($body['status'])) {
            $status = strtoupper(trim((string) $body['status']));
            if (!in_array($status, VALID_TASK_STATUSES, true)) {
                json_error('status must be one of: ' . implode(', ', VALID_TASK_STATUSES) . '.', 422, 'VALIDATION_ERROR');
            }
            // Field team members may only move their own task to IN_PROGRESS —
            // completion is normally driven by inspection submission, and
            // cancellation/reopening is a supervisory decision.
            if (!$isSupervisor && $status !== 'IN_PROGRESS') {
                json_error('You can only mark your task as IN_PROGRESS. Contact a supervisor for other status changes.', 403, 'FORBIDDEN_ROLE');
            }
            $fields[] = 'status = :status';
            $params['status'] = $status;
        }

        if (isset($body['assigned_to_user_id'])) {
            if (!$isSupervisor) {
                json_error('Only supervisory roles can reassign a task.', 403, 'FORBIDDEN_ROLE');
            }
            $newAssignee = (int) $body['assigned_to_user_id'];
            $assigneeStmt = $pdo->prepare('SELECT id FROM users WHERE id = :id AND is_active = 1 LIMIT 1');
            $assigneeStmt->execute(['id' => $newAssignee]);
            if ($assigneeStmt->fetch() === false) {
                json_error('No active user found with that assigned_to_user_id.', 404, 'USER_NOT_FOUND');
            }
            $fields[] = 'assigned_to_user_id = :assigned_to_user_id';
            $params['assigned_to_user_id'] = $newAssignee;
        }

        if (isset($body['due_date'])) {
            if (!$isSupervisor) {
                json_error('Only supervisory roles can change the due date.', 403, 'FORBIDDEN_ROLE');
            }
            $dueDateRaw = trim((string) $body['due_date']);
            if ($dueDateRaw !== '' && strtotime($dueDateRaw) === false) {
                json_error('due_date must be a valid date.', 422, 'VALIDATION_ERROR');
            }
            $fields[] = 'due_date = :due_date';
            $params['due_date'] = $dueDateRaw !== '' ? date('Y-m-d', strtotime($dueDateRaw)) : null;
        }

        if (isset($body['notes'])) {
            if (!$isSupervisor) {
                json_error('Only supervisory roles can edit task notes.', 403, 'FORBIDDEN_ROLE');
            }
            $notes = trim((string) $body['notes']);
            $fields[] = 'notes = :notes';
            $params['notes'] = $notes !== '' ? $notes : null;
        }

        if (empty($fields)) {
            json_error('No updatable fields were provided.', 422, 'VALIDATION_ERROR');
        }

        $sql = 'UPDATE task_assignments SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $pdo->prepare($sql)->execute($params);

        json_response(['success' => true, 'message' => 'Task updated.']);
        break;

    // -------------------------------------------------------------------
    case 'DELETE':
        if (!$isSupervisor) {
            json_error('Only supervisory roles can cancel a task.', 403, 'FORBIDDEN_ROLE');
        }

        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            json_error('Query parameter "id" is required.', 422, 'VALIDATION_ERROR');
        }

        $scopeCheckStmt = $pdo->prepare(
            'SELECT t.id, c.division, c.sub_division FROM task_assignments t
             INNER JOIN consumers c ON c.id = t.consumer_id
             WHERE t.id = :id LIMIT 1'
        );
        $scopeCheckStmt->execute(['id' => $id]);
        $taskForScopeCheck = $scopeCheckStmt->fetch();

        if ($taskForScopeCheck === false) {
            json_error('Task not found.', 404, 'TASK_NOT_FOUND');
        }
        if (!is_within_enforced_scope($currentUser, $taskForScopeCheck)) {
            json_error('You do not have permission to cancel this task.', 403, 'FORBIDDEN_ROLE');
        }

        $stmt = $pdo->prepare('UPDATE task_assignments SET status = \'CANCELLED\' WHERE id = :id');
        $stmt->execute(['id' => $id]);

        if ($stmt->rowCount() === 0) {
            json_error('Task not found.', 404, 'TASK_NOT_FOUND');
        }

        json_response(['success' => true, 'message' => 'Task cancelled.']);
        break;

    default:
        json_error('Method not allowed. Use GET, POST, PUT, or DELETE.', 405, 'METHOD_NOT_ALLOWED');
}
