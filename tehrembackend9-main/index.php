<?php
/**
 * =============================================================================
 * FILE: index.php
 * PURPOSE: Root entry point. Serves as:
 *   1) Railway's health-check target (must return HTTP 200 fast, no DB call
 *      required so deploys don't fail if the DB plugin is still booting).
 *   2) A simple human-readable landing page confirming the API is live.
 *   3) A machine-readable JSON response when requested (Accept: application/json
 *      or ?format=json), listing available endpoints.
 * =============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/helpers.php';

$acceptsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
    || ($_GET['format'] ?? '') === 'json';

$endpoints = [
    'POST /api/login.php   (action=login|verify_otp|resend_otp in body)',
    'POST /api/logout.php   (revokes the current bearer token)',
    'GET  /api/me.php   (validates token, returns current profile)',
    'POST /api/change_password.php   (any authenticated user changes their OWN password; requires current_password)',
    'GET  /api/data.php?action=form-options',
    'GET  /api/data.php?action=consumer-fetch&ref=REF-2025-00142',
    'POST /api/data.php?action=inspection-submit   (GPS + 2-12 images + offline-sync idempotent via client_uuid)',
    'GET  /api/data.php?action=inspections-list   (search/status/date/division/sub_division/category filters; MT/SDO/XEN scoped server-side)',
    'GET  /api/data.php?action=inspection-detail&id=42   (full record incl. images; same scoping)',
    'GET|POST|PUT|DELETE /api/admin/users.php   (ADMIN only)',
    'GET /api/admin/consumers.php   (supervisory roles, SDO/XEN scoped server-side); POST|PUT|DELETE /api/admin/consumers.php   (ADMIN only)',
    'GET|POST|PUT|DELETE /api/admin/form_options.php   (ADMIN only)',
    'GET /api/admin/schedules.php   (supervisory roles, SDO/XEN scoped server-side, SDO view-only); POST|PUT|DELETE /api/admin/schedules.php   (XEN/SE/ADMIN only; ?action=generate for quarterly auto-scheduling)',
    'GET|POST|PUT|DELETE /api/tasks.php   (field team: own tasks; supervisory roles: assign/reassign/cancel, SDO/XEN scoped server-side)',
    'GET|POST|PUT /api/discrepancies.php   (report + triage theft/damage/tampering/etc. findings; SDO/XEN scoped server-side)',
    'GET|POST /api/approvals.php   (supervisory roles; SDO->XEN->SE review queue; ?action=decide to approve/reject; SDO/XEN scoped server-side)',
    'GET|PUT /api/admin/approval_rules.php   (ADMIN only; configure which levels each category B1-B4 requires)',
    'GET /api/dashboard.php   (supervisory roles; totals, approval pipeline, discrepancy trends, team performance; SDO/XEN scoped server-side)',
    'GET /api/alerts.php   (supervisory roles; overdue-inspection SDO->XEN->SE escalation chain, SDO/XEN scoped server-side); POST ?action=mark-read',
];

if ($acceptsJson) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success'   => true,
        'service'   => 'MEPCO Meter Testing API',
        'status'    => 'ok',
        'timestamp' => date('c'),
        'endpoints' => $endpoints,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>MEPCO Meter Testing API</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #0b1f3a;
            color: #f5f7fa;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .card {
            background: #10294f;
            border: 1px solid #d4af37;
            border-radius: 12px;
            padding: 2.5rem 3rem;
            max-width: 640px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.35);
        }
        h1 { color: #d4af37; margin-top: 0; font-size: 1.5rem; }
        .status { display: inline-flex; align-items: center; gap: 0.5rem; font-size: 0.9rem; color: #7ee787; margin-bottom: 1.25rem; }
        .dot { width: 8px; height: 8px; border-radius: 50%; background: #7ee787; }
        code { background: rgba(255,255,255,0.08); padding: 0.15rem 0.4rem; border-radius: 4px; font-size: 0.85rem; }
        ul { line-height: 1.9; padding-left: 1.2rem; }
        footer { margin-top: 1.5rem; font-size: 0.75rem; opacity: 0.6; }
    </style>
</head>
<body>
    <div class="card">
        <div class="status"><span class="dot"></span> API is running</div>
        <h1>MEPCO LT/HT TOU Meter Testing — Backend API</h1>
        <p>This is a vanilla PHP + MySQL REST API. Available endpoints:</p>
        <ul>
            <?php foreach ($endpoints as $endpoint): ?>
                <li><code><?php echo htmlspecialchars($endpoint, ENT_QUOTES); ?></code></li>
            <?php endforeach; ?>
        </ul>
        <footer>Request this URL with <code>Accept: application/json</code> for a machine-readable response.</footer>
    </div>
</body>
</html>
