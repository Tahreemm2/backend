<?php
/**
 * =============================================================================
 * FILE: api/dashboard.php
 * PURPOSE: Dashboard & Analytics (spec 3.11). A single read-only aggregation
 * endpoint for supervisory roles (SDO/XEN/SE/ADMIN) covering:
 *   - Total meters tested / pending inspections (summary + completion rate)
 *   - Approval pipeline breakdown (spec 3.10 tie-in — how many inspections
 *     are sitting at each SDO/XEN/SE level right now)
 *   - Discrepancy trends (by type, by severity, by month)
 *   - Team performance tracking (per field-team-member assigned/completed
 *     task counts and average time-to-complete)
 *
 * REQUEST (requires "Authorization: Bearer <token>" for a supervisory role):
 *
 *   GET /api/dashboard.php?quarter=2026-Q3&division=&sub_division=&category=
 *       All filters optional. quarter defaults to the current quarter.
 *       division/sub_division/category scope every section consistently.
 *       SDO/XEN additionally get their own sub-division(s)/division enforced
 *       server-side regardless of what's passed above — see
 *       enforced_scope_sql() in helpers.php.
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

// Opportunistic sweep (see run_auto_reassignment_sweep() in helpers.php) —
// runs here too since Dashboard is typically the first screen a supervisor
// opens each session, not just when they specifically open Tasks.
run_auto_reassignment_sweep($pdo);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
    json_error('Method not allowed. Use GET.', 405, 'METHOD_NOT_ALLOWED');
}

// ---- Filters ---------------------------------------------------------------
$quarter = trim((string) ($_GET['quarter'] ?? ''));
if ($quarter === '') {
    $quarter = quarter_string();
} elseif (parse_quarter_string($quarter) === null) {
    json_error('quarter must be in "YYYY-Qn" format, e.g. "2026-Q3".', 422, 'VALIDATION_ERROR');
}
[$rangeStart, $rangeEnd] = quarter_date_range($quarter);

$division = trim((string) ($_GET['division'] ?? ''));
$subDivision = trim((string) ($_GET['sub_division'] ?? ''));
$category = strtoupper(trim((string) ($_GET['category'] ?? '')));
if ($category !== '' && !in_array($category, VALID_CATEGORIES, true)) {
    json_error('category must be one of: ' . implode(', ', VALID_CATEGORIES) . '.', 422, 'VALIDATION_ERROR');
}

/**
 * Builds a "WHERE ..." fragment + bind params for the given SQL aliases,
 * reused across every query below so every section is scoped identically.
 * Also folds in server-side enforcement of the caller's own division/
 * sub-division(s) (see enforced_scope_sql() in helpers.php) — the
 * division/sub_division/category query params above may only narrow
 * further within that, never widen it.
 *
 * @param string $divisionCol e.g. "s.division" or "c.division"
 * @param string $subDivisionCol
 * @param string $categoryCol
 * @return array{0:string,1:array<string,string>}
 */
function scope_where(string $divisionCol, string $subDivisionCol, string $categoryCol): array
{
    global $division, $subDivision, $category, $currentUser;

    $conditions = [];
    $params = [];

    if ($division !== '') {
        $conditions[] = "{$divisionCol} = :division";
        $params['division'] = $division;
    }
    if ($subDivision !== '') {
        $conditions[] = "{$subDivisionCol} = :sub_division";
        $params['sub_division'] = $subDivision;
    }
    if ($category !== '') {
        $conditions[] = "{$categoryCol} = :category";
        $params['category'] = $category;
    }

    [$scopeSql, $scopeParams] = enforced_scope_sql($currentUser, $divisionCol, $subDivisionCol);
    if ($scopeSql !== '') {
        $conditions[] = $scopeSql;
        $params = array_merge($params, $scopeParams);
    }

    return [$conditions, $params];
}

/** Runs a scalar COUNT query with the given extra WHERE fragment + params appended to a base one. */
function scalar_count(PDO $pdo, string $sql, array $params): int
{
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->execute();
    return (int) $stmt->fetchColumn();
}

// =============================================================================
// 1) SUMMARY — total meters tested, pending inspections, completion rate
//    (Scheduling System is the source of truth for "how many were due this
//    quarter"; inspections table is the source of truth for "how many were
//    actually tested", since ad-hoc/unscheduled visits can happen too.)
// =============================================================================
[$scheduleConditions, $scheduleParams] = scope_where('s.division', 's.sub_division', 's.category');
$scheduleConditions[] = 's.quarter = :quarter';
$scheduleParams['quarter'] = $quarter;
$scheduleWhere = 'WHERE ' . implode(' AND ', $scheduleConditions);

$totalScheduled = scalar_count($pdo, "SELECT COUNT(*) FROM schedules s {$scheduleWhere}", $scheduleParams);
$completedScheduled = scalar_count(
    $pdo,
    "SELECT COUNT(*) FROM schedules s {$scheduleWhere} AND s.status = 'COMPLETED'",
    $scheduleParams
);
$pendingInspections = scalar_count(
    $pdo,
    "SELECT COUNT(*) FROM schedules s {$scheduleWhere} AND s.status IN ('PENDING','ASSIGNED')",
    $scheduleParams
);

[$inspectionConditions, $inspectionParams] = scope_where('c.division', 'c.sub_division', 'c.category');
$inspectionConditions[] = 'i.inspection_datetime BETWEEN :range_start AND :range_end';
$inspectionParams['range_start'] = $rangeStart . ' 00:00:00';
$inspectionParams['range_end']   = $rangeEnd . ' 23:59:59';
$inspectionWhere = 'WHERE ' . implode(' AND ', $inspectionConditions);
$inspectionBaseSql = "SELECT COUNT(*) FROM inspections i LEFT JOIN consumers c ON c.reference_number = i.reference_number {$inspectionWhere}";

$totalMetersTested = scalar_count($pdo, $inspectionBaseSql, $inspectionParams);

$summary = [
    'total_scheduled'      => $totalScheduled,
    'total_meters_tested'  => $totalMetersTested,
    'pending_inspections'  => $pendingInspections,
    'completed_schedules'  => $completedScheduled,
    'completion_rate_pct'  => $totalScheduled > 0 ? round(($completedScheduled / $totalScheduled) * 100, 1) : null,
];

// =============================================================================
// 2) APPROVAL PIPELINE (3.10 tie-in) — how many inspections are sitting at
//    each review level right now, within the same quarter/scope.
// =============================================================================
$approvalPipeline = [
    'pending_sdo' => scalar_count($pdo, $inspectionBaseSql . " AND i.overall_status = 'PENDING_APPROVAL' AND i.current_approval_level = 1", $inspectionParams),
    'pending_xen' => scalar_count($pdo, $inspectionBaseSql . " AND i.overall_status = 'PENDING_APPROVAL' AND i.current_approval_level = 2", $inspectionParams),
    'pending_se'  => scalar_count($pdo, $inspectionBaseSql . " AND i.overall_status = 'PENDING_APPROVAL' AND i.current_approval_level = 3", $inspectionParams),
    'approved'    => scalar_count($pdo, $inspectionBaseSql . " AND i.overall_status = 'APPROVED'", $inspectionParams),
    'rejected'    => scalar_count($pdo, $inspectionBaseSql . " AND i.overall_status = 'REJECTED'", $inspectionParams),
];

// =============================================================================
// 3) DISCREPANCY TRENDS — by type, by severity, by month within the quarter
// =============================================================================
[$discrepancyConditions, $discrepancyParams] = scope_where('c.division', 'c.sub_division', 'c.category');
$discrepancyConditions[] = 'd.created_at BETWEEN :range_start AND :range_end';
$discrepancyParams['range_start'] = $rangeStart . ' 00:00:00';
$discrepancyParams['range_end']   = $rangeEnd . ' 23:59:59';
$discrepancyWhere = 'WHERE ' . implode(' AND ', $discrepancyConditions);
$discrepancyJoin = 'FROM discrepancies d LEFT JOIN consumers c ON c.id = d.consumer_id';

function grouped_counts(PDO $pdo, string $joinAndWhere, array $params, string $groupCol, string $groupAlias, ?string $orderClause = null): array
{
    $order = $orderClause ?? "COUNT(*) DESC";
    $stmt = $pdo->prepare("SELECT {$groupCol} AS {$groupAlias}, COUNT(*) AS count {$joinAndWhere} GROUP BY {$groupCol} ORDER BY {$order}");
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->execute();
    return $stmt->fetchAll();
}

$discrepancyTrends = [
    'by_type'     => grouped_counts($pdo, "{$discrepancyJoin} {$discrepancyWhere}", $discrepancyParams, 'd.type', 'type'),
    'by_severity' => grouped_counts($pdo, "{$discrepancyJoin} {$discrepancyWhere}", $discrepancyParams, 'd.severity', 'severity', "FIELD(severity,'CRITICAL','HIGH','MEDIUM','LOW')"),
    'by_month'    => grouped_counts($pdo, "{$discrepancyJoin} {$discrepancyWhere}", $discrepancyParams, "DATE_FORMAT(d.created_at, '%Y-%m')", 'month', 'month ASC'),
    'total_open'  => scalar_count($pdo, "SELECT COUNT(*) {$discrepancyJoin} {$discrepancyWhere} AND d.status = 'OPEN'", $discrepancyParams),
];

// =============================================================================
// 4) TEAM PERFORMANCE — per field-team-member (role MT) task throughput
//
// IMPORTANT: the worker LIST itself must be every active M&T account under
// the caller's scope, regardless of whether they've been assigned any tasks
// yet — this is what the SDO "Worker Monitoring" screen (spec item 4) reuses
// this section for, and it needs to show newly-onboarded/idle workers too,
// not just ones with throughput. So the base of the query is `users`
// (scoped by the WORKER's own u.scope_name, via enforced_scope() — the same
// rule the frontend's ScopeDefaults uses), and task_assignments is brought
// in as a LEFT JOIN against a pre-filtered subquery, so a worker with zero
// matching tasks still gets a row with all counts at 0 instead of vanishing.
// (The previous version used INNER JOIN task_assignments/consumers, which
// silently dropped any worker who had no task_assignments row at all, or
// none created within the current quarter's date range — that's the bug
// behind "SDO worker list shows no workers" for SDOs whose team hasn't been
// assigned anything yet this quarter.)
// =============================================================================
[$teamScopeDivision, $teamScopeSubDivisions] = enforced_scope($currentUser);

$workerConditions = ["u.role_code = 'MT'", 'u.is_active = 1'];
$workerParams = [];

// Enforce the caller's own scope against the WORKER's home scope_name.
if ($teamScopeDivision !== null) {
    $workerConditions[] = 'u.scope_name = :__worker_scope_division';
    $workerParams['__worker_scope_division'] = $teamScopeDivision;
} elseif (!empty($teamScopeSubDivisions)) {
    $subPlaceholders = [];
    foreach ($teamScopeSubDivisions as $i => $sd) {
        $key = "__worker_scope_sd_{$i}";
        $subPlaceholders[] = ":{$key}";
        $workerParams[$key] = $sd;
    }
    $workerConditions[] = 'u.scope_name IN (' . implode(', ', $subPlaceholders) . ')';
}
// Optional explicit ?division=/?sub_division= query filters narrow further,
// same as every other section on this page (scope_where() above).
if ($division !== '') {
    $workerConditions[] = 'u.scope_name = :worker_division_filter';
    $workerParams['worker_division_filter'] = $division;
}
if ($subDivision !== '') {
    $workerConditions[] = 'u.scope_name = :worker_sub_division_filter';
    $workerParams['worker_sub_division_filter'] = $subDivision;
}
$workerWhere = 'WHERE ' . implode(' AND ', $workerConditions);

// Pre-filtered task set: quarter range + optional ?category= filter, joined
// to consumers here (not in the outer query) so the filter narrows which
// TASKS count without narrowing which WORKERS appear.
$taskConditions = ['t.created_at BETWEEN :range_start AND :range_end'];
$taskParams = [
    'range_start' => $rangeStart . ' 00:00:00',
    'range_end'   => $rangeEnd . ' 23:59:59',
];
if ($category !== '') {
    $taskConditions[] = 'c.category = :team_category_filter';
    $taskParams['team_category_filter'] = $category;
}
$taskWhere = 'WHERE ' . implode(' AND ', $taskConditions);

$teamStmt = $pdo->prepare(
    "SELECT
        u.id AS user_id, u.full_name, u.scope_name,
        COUNT(t.id) AS assigned_count,
        SUM(CASE WHEN t.status = 'COMPLETED' THEN 1 ELSE 0 END) AS completed_count,
        ROUND(AVG(
            CASE WHEN t.status = 'COMPLETED' AND insp.created_at IS NOT NULL
                 THEN TIMESTAMPDIFF(HOUR, t.created_at, insp.created_at)
            END
        ), 1) AS avg_completion_hours
     FROM users u
     LEFT JOIN (
         SELECT t.*
         FROM task_assignments t
         INNER JOIN consumers c ON c.id = t.consumer_id
         {$taskWhere}
     ) t ON t.assigned_to_user_id = u.id
     LEFT JOIN inspections insp ON insp.id = t.inspection_id
     {$workerWhere}
     GROUP BY u.id, u.full_name, u.scope_name
     ORDER BY completed_count DESC, assigned_count DESC"
);
foreach (array_merge($taskParams, $workerParams) as $key => $value) {
    $teamStmt->bindValue($key, $value, PDO::PARAM_STR);
}
$teamStmt->execute();
$teamRows = $teamStmt->fetchAll();

$teamPerformance = array_map(static function (array $row): array {
    $assigned = (int) $row['assigned_count'];
    $completed = (int) $row['completed_count'];
    return [
        'user_id'              => (int) $row['user_id'],
        'full_name'            => $row['full_name'],
        'scope_name'           => $row['scope_name'],
        'assigned_count'       => $assigned,
        'completed_count'      => $completed,
        'completion_rate_pct'  => $assigned > 0 ? round(($completed / $assigned) * 100, 1) : null,
        'avg_completion_hours' => $row['avg_completion_hours'] !== null ? (float) $row['avg_completion_hours'] : null,
    ];
}, $teamRows);

// =============================================================================
json_response([
    'success'            => true,
    'quarter'            => $quarter,
    'filters'            => [
        'division'     => $division !== '' ? $division : null,
        'sub_division' => $subDivision !== '' ? $subDivision : null,
        'category'     => $category !== '' ? $category : null,
    ],
    'summary'            => $summary,
    'approval_pipeline'  => $approvalPipeline,
    'discrepancy_trends' => $discrepancyTrends,
    'team_performance'   => $teamPerformance,
]);
