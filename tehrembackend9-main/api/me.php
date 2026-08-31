<?php
/**
 * =============================================================================
 * FILE: api/me.php
 * PURPOSE: Validates the stored bearer token and returns the current user's
 * profile. Intended for the Flutter SessionManager to call on app launch
 * (before showing the dashboard) so an expired/revoked token bounces the
 * user back to LoginScreen instead of showing stale cached data.
 *
 * REQUEST:
 *   GET /api/me.php
 *   Header: Authorization: Bearer <token>
 *
 * RESPONSE (200):
 *   { "success": true, "employee_id": "...", "full_name": "...", ... }
 *   (same shape as the login response, minus "token")
 *
 * RESPONSE (401) if token missing/expired/invalid:
 *   { "success": false, "message": "...", "error_code": "AUTH_..." }
 * =============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

send_common_headers();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    json_error('This endpoint only accepts GET requests.', 405, 'METHOD_NOT_ALLOWED');
}

try {
    $pdo = get_db_connection();
} catch (PDOException $e) {
    json_error('Database connection failed. Please try again later.', 500, 'DB_CONNECTION_ERROR');
}

$currentUser = require_authenticated_user($pdo);

json_response([
    'success' => true,
] + build_user_profile($currentUser));
