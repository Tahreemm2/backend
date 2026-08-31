<?php
/**
 * =============================================================================
 * FILE: api/logout.php
 * PURPOSE: Revokes the caller's bearer token server-side. The Flutter app's
 * LogoutRequested handler currently only clears local state -- call this
 * endpoint first so the token can no longer be replayed if the device is
 * lost or the local storage isn't wiped.
 *
 * REQUEST:
 *   POST /api/logout.php
 *   Header: Authorization: Bearer <token>
 *
 * RESPONSE (200):
 *   { "success": true, "message": "Logged out successfully." }
 * =============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

send_common_headers();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('This endpoint only accepts POST requests.', 405, 'METHOD_NOT_ALLOWED');
}

try {
    $pdo = get_db_connection();
} catch (PDOException $e) {
    json_error('Database connection failed. Please try again later.', 500, 'DB_CONNECTION_ERROR');
}

// Confirms the token is valid before revoking it (also gives a clean 401
// instead of silently succeeding on an already-invalid/garbage token).
require_authenticated_user($pdo);

$token = get_bearer_token();
revoke_token($pdo, (string) $token);

json_response([
    'success' => true,
    'message' => 'Logged out successfully.',
]);
