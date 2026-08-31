<?php
/**
 * =============================================================================
 * FILE: api/alerts.php
 * PURPOSE: Three kinds of notification, split by audience:
 *
 *   1) ESCALATION (supervisory: SDO/XEN/SE/ADMIN) — "if an inspection is not
 *      completed within one month, an alert/report is sent to the SDO. If it
 *      remains unresolved, it is escalated to the XEN and then the SE."
 *      Level-gated: each role only ever sees the level that names them
 *      (SDO=1, XEN=2, SE=3). ADMIN sees every level, unfiltered.
 *
 *   2) DISCREPANCY (supervisory: SDO/XEN/SE/ADMIN) — "new discrepancy
 *      notifications." NOT level-gated — a newly-reported discrepancy is
 *      relevant to every supervisory role whose scope covers it, not a
 *      graduated hand-off. Created directly by POST /api/discrepancies.php
 *      at report time (an instant event, unlike escalation which has no
 *      natural "creation" moment).
 *
 *   3) INSPECTION_DECISION (M&T only) — the feedback half of the Approval
 *      Workflow (3.10): when SDO/XEN/SE approve, forward, or reject a
 *      submitted inspection, the submitting M&T gets one of these. Targeted
 *      at exactly one person via recipient_user_id rather than
 *      division/sub-division scoped like the two types above — an M&T
 *      calling this endpoint only ever sees their OWN rows, and never sees
 *      ESCALATION/DISCREPANCY rows (those aren't relevant to field work).
 *      Created directly by POST /api/approvals.php?action=decide.
 *
 * DESIGN NOTE — no background worker in this deployment (plain PHP on a
 * single Railway web dyno, per nixpacks.toml — no queue/cron process), so
 * ESCALATION state is computed on-demand rather than by a scheduled job:
 * every GET (from a supervisory caller) recomputes across ALL overdue
 * schedules (not just the caller's own scope, so e.g. an SE's level-3 alerts
 * get created even if triggered by an SDO opening their own Alerts screen
 * first) and idempotently upserts into `notifications` via its
 * (schedule_id, escalation_level) unique key — safe to call as often as any
 * supervisory user opens this screen. DISCREPANCY and INSPECTION_DECISION
 * rows need no such recompute — they're inserted once, at the moment they
 * happen, and just read back here.
 *
 * Escalation thresholds (spec confirms only the first: "within one month" —
 * the 60/90-day follow-on thresholds below are this implementation's own
 * reasonable assumption for "remains unresolved"; confirm with the client
 * if a different cadence is wanted):
 *   >= 30 days overdue, still not COMPLETED/CANCELLED -> level 1 alert (SDO)
 *   >= 60 days overdue -> level 2 escalation (XEN)
 *   >= 90 days overdue -> level 3 escalation (SE)
 * ESCALATION/DISCREPANCY are scoped to the caller's own division/sub-division
 * via enforced_scope_sql() (SE/ADMIN unrestricted — same known circle-column
 * gap as every other supervisory endpoint). INSPECTION_DECISION needs no such
 * scoping — recipient_user_id already narrows it to exactly one person.
 *
 * REQUEST (any authenticated role — M&T included, unlike every other
 * endpoint in this file's original scope):
 *   GET /api/alerts.php?unread_only=1&type=ESCALATION|DISCREPANCY
 *     -> Supervisory roles: { "success": true, "data": [ {...}, ... ], "total": N }
 *     -> M&T: same shape, but always INSPECTION_DECISION rows for that user
 *        regardless of the type= filter (there's nothing else for them to see).
 *
 *   POST /api/alerts.php?action=mark-read
 *     Body: { "id": 42 }
 *     -> { "success": true, "message": "Marked as read." }
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
// No require_role() gate here — M&T callers get their own INSPECTION_DECISION
// rows (see below); every other role must still be supervisory, enforced at
// each branch instead of up front.
$isFieldWorker = $currentUser['role_code'] === 'MT';
if (!$isFieldWorker) {
    require_role($currentUser, SUPERVISORY_ROLES);
}

/** Which escalation_level a role is the recipient of. ADMIN sees all (unfiltered) — not looked up here. */
const ROLE_TO_LEVEL = ['SDO' => 1, 'XEN' => 2, 'SE' => 3];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = trim((string) ($_GET['action'] ?? ''));

// =============================================================================
// POST ?action=mark-read
// =============================================================================
if ($method === 'POST' && $action === 'mark-read') {
    $body = get_json_body();
    $id = (int) ($body['id'] ?? 0);
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'Validation failed.', 'errors' => ['id' => 'id is required.']], 422);
    }

    // A caller may only mark its OWN alerts read — e.g. a XEN can't silence
    // an SDO's level-1 ESCALATION alert, and an SDO in Sub-Division A can't
    // touch one scoped to Sub-Division B. DISCREPANCY notifications aren't
    // level-gated (any supervisory role in scope can mark one read), but
    // are still scope-gated the same way. An M&T can only ever touch their
    // own INSPECTION_DECISION rows (recipient_user_id already IS the scope).
    $conditions = ['id = :id'];
    $params = ['id' => $id];

    $roleCode = (string) $currentUser['role_code'];
    if ($isFieldWorker) {
        $conditions[] = "type = 'INSPECTION_DECISION'";
        $conditions[] = 'recipient_user_id = :recipient_id';
        $params['recipient_id'] = (int) $currentUser['id'];
    } elseif ($roleCode !== 'ADMIN') {
        $conditions[] = "(type = 'DISCREPANCY' OR (type = 'ESCALATION' AND escalation_level = :level))";
        $params['level'] = ROLE_TO_LEVEL[$roleCode] ?? 0;

        [$scopeSql, $scopeParams] = enforced_scope_sql($currentUser, 'division', 'sub_division');
        if ($scopeSql !== '') {
            $conditions[] = $scopeSql;
            $params = array_merge($params, $scopeParams);
        }
    }

    $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE ' . implode(' AND ', $conditions));
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        json_error('No matching alert found, or it is outside your scope.', 404, 'NOT_FOUND');
    }

    json_response(['success' => true, 'message' => 'Marked as read.']);
}

if ($method !== 'GET') {
    json_error('This action only accepts GET requests.', 405, 'METHOD_NOT_ALLOWED');
}

// =============================================================================
// STEP 1 — recompute (supervisory only): find every overdue, unfinished
// schedule and upsert the notification row for every threshold it has now
// crossed. Irrelevant to an M&T caller (they only ever see their own
// INSPECTION_DECISION rows, which are written elsewhere at decision time),
// so skip it entirely for them rather than doing pointless work.
// =============================================================================
const ESCALATION_THRESHOLDS = [1 => 30, 2 => 60, 3 => 90];
const ESCALATION_ROLE_LABELS = [1 => 'SDO', 2 => 'XEN', 3 => 'SE'];

if (!$isFieldWorker) {
    $overdueStmt = $pdo->query(
        "SELECT s.id, s.division, s.sub_division, s.scheduled_date,
                DATEDIFF(CURDATE(), s.scheduled_date) AS days_overdue,
                c.reference_number, c.consumer_name
         FROM schedules s
         INNER JOIN consumers c ON c.id = s.consumer_id
         WHERE s.status NOT IN ('COMPLETED', 'CANCELLED')
           AND s.scheduled_date < CURDATE()"
    );
    $overdueSchedules = $overdueStmt->fetchAll();

    $upsertStmt = $pdo->prepare(
        'INSERT INTO notifications (schedule_id, escalation_level, division, sub_division, days_overdue, message)
         VALUES (:schedule_id, :level, :division, :sub_division, :days_overdue, :message)
         ON DUPLICATE KEY UPDATE
            days_overdue = VALUES(days_overdue),
            message      = VALUES(message)'
    );

    foreach ($overdueSchedules as $schedule) {
        $daysOverdue = (int) $schedule['days_overdue'];

        foreach (ESCALATION_THRESHOLDS as $level => $threshold) {
            if ($daysOverdue < $threshold) {
                continue;
            }

            $roleLabel = ESCALATION_ROLE_LABELS[$level];
            $message = $level === 1
                ? "Inspection for {$schedule['reference_number']} ({$schedule['consumer_name']}) has not been completed within one month ({$daysOverdue} days overdue)."
                : "Escalated to {$roleLabel}: inspection for {$schedule['reference_number']} ({$schedule['consumer_name']}) remains incomplete after {$daysOverdue} days.";

            $upsertStmt->execute([
                'schedule_id'  => (int) $schedule['id'],
                'level'        => $level,
                'division'     => $schedule['division'],
                'sub_division' => $schedule['sub_division'],
                'days_overdue' => $daysOverdue,
                'message'      => $message,
            ]);
        }
    }
}

// =============================================================================
// STEP 2 — list:
//   M&T: always their own INSPECTION_DECISION rows, full stop — never
//        ESCALATION/DISCREPANCY (not relevant to field work), and no
//        division/sub-division scoping needed since recipient_user_id
//        already narrows it to exactly this one person.
//   Supervisory: ESCALATION rows are level-gated (each role sees only its
//        own level); DISCREPANCY rows are visible to every supervisory role
//        whose scope covers them, regardless of level. Both are always
//        scope-gated.
// =============================================================================
$conditions = [];
$params = [];

$roleCode = (string) $currentUser['role_code'];
if ($isFieldWorker) {
    $conditions[] = "n.type = 'INSPECTION_DECISION'";
    $conditions[] = 'n.recipient_user_id = :recipient_id';
    $params['recipient_id'] = (int) $currentUser['id'];
} elseif ($roleCode !== 'ADMIN') {
    // ADMIN sees everything unfiltered (oversight). Every other supervisory
    // role sees: DISCREPANCY rows in their scope (any level), PLUS
    // ESCALATION rows at exactly their own level, in their scope.
    $conditions[] = "(n.type = 'DISCREPANCY' OR (n.type = 'ESCALATION' AND n.escalation_level = :level))";
    $params['level'] = ROLE_TO_LEVEL[$roleCode] ?? 0;

    [$scopeSql, $scopeParams] = enforced_scope_sql($currentUser, 'n.division', 'n.sub_division');
    if ($scopeSql !== '') {
        $conditions[] = $scopeSql;
        $params = array_merge($params, $scopeParams);
    }
} else {
    // ADMIN's general feed stays ESCALATION/DISCREPANCY only — INSPECTION_DECISION
    // rows are per-M&T inboxes, not an oversight feed, so exclude them here too.
    $conditions[] = "n.type != 'INSPECTION_DECISION'";
}

$typeFilter = strtoupper(trim((string) ($_GET['type'] ?? '')));
if (!$isFieldWorker && in_array($typeFilter, ['ESCALATION', 'DISCREPANCY'], true)) {
    $conditions[] = 'n.type = :type_filter';
    $params['type_filter'] = $typeFilter;
}

if (($_GET['unread_only'] ?? '') === '1') {
    $conditions[] = 'n.is_read = 0';
}

$whereSql = empty($conditions) ? '' : ('WHERE ' . implode(' AND ', $conditions));

$stmt = $pdo->prepare(
    "SELECT n.id, n.type, n.schedule_id, n.discrepancy_id, n.inspection_id, n.recipient_user_id,
            n.escalation_level, n.division, n.sub_division,
            n.days_overdue, n.message, n.is_read, n.created_at,
            s.scheduled_date, s.status AS schedule_status,
            d.type AS discrepancy_type, d.severity AS discrepancy_severity, d.status AS discrepancy_status,
            i.overall_status AS inspection_overall_status,
            COALESCE(sc.reference_number, dc.reference_number, i.reference_number) AS reference_number,
            COALESCE(sc.meter_id, dc.meter_id, i.meter_id) AS meter_id,
            COALESCE(sc.consumer_name, dc.consumer_name, ic.consumer_name) AS consumer_name
     FROM notifications n
     LEFT JOIN schedules s      ON s.id = n.schedule_id
     LEFT JOIN consumers sc     ON sc.id = s.consumer_id
     LEFT JOIN discrepancies d  ON d.id = n.discrepancy_id
     LEFT JOIN consumers dc     ON dc.id = d.consumer_id
     LEFT JOIN inspections i    ON i.id = n.inspection_id
     LEFT JOIN consumers ic     ON ic.reference_number = i.reference_number
     {$whereSql}
     ORDER BY n.is_read ASC, n.created_at DESC"
);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->execute();
$rows = $stmt->fetchAll();

json_response([
    'success' => true,
    'data'    => $rows,
    'total'   => count($rows),
]);
