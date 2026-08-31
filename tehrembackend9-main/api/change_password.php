<?php
/**
 * =============================================================================
 * FILE: api/change_password.php
 * PURPOSE: Lets the currently authenticated user change their OWN password.
 * Distinct from PUT /api/admin/users.php, which is ADMIN-only and lets an
 * admin reset ANY user's password without knowing the old one — this
 * endpoint requires the caller to prove they know their current password,
 * and only ever touches the caller's own row. Any authenticated role (MT,
 * SDO, XEN, SE, ADMIN) may call this.
 *
 * REQUEST:
 *   POST /api/change_password.php
 *   Header: Authorization: Bearer <token>
 *   Body: { "current_password": "...", "new_password": "..." }
 *
 * RESPONSE (200):
 *   { "success": true, "message": "Password changed successfully." }
 *
 * RESPONSE (401) if current_password doesn't match:
 *   { "success": false, "message": "...", "error_code": "INVALID_CURRENT_PASSWORD" }
 *
 * RESPONSE (422) on validation failure (missing fields, new_password < 8 chars,
 *   new_password identical to current_password):
 *   { "success": false, "message": "...", "error_code": "VALIDATION_ERROR", "errors": { ... } }
 *
 * NOTE: Per-role password policy (expiry, complexity) is not defined by the
 * SRS beyond "min 8 characters" (mirrors the admin/users.php rule) — revisit
 * if the client specifies more later.
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

$currentUser = require_authenticated_user($pdo);
$body        = get_json_body();

$currentPassword = (string) ($body['current_password'] ?? '');
$newPassword     = (string) ($body['new_password'] ?? '');

$errors = [];
if ($currentPassword === '') {
    $errors['current_password'] = 'Current password is required.';
}
if ($newPassword === '') {
    $errors['new_password'] = 'New password is required.';
} elseif (strlen($newPassword) < 8) {
    $errors['new_password'] = 'New password must be at least 8 characters.';
}
if (!empty($errors)) {
    json_response(['success' => false, 'message' => 'Please correct the highlighted fields.', 'errors' => $errors], 422);
}

if (!password_verify($currentPassword, (string) $currentUser['password_hash'])) {
    json_error('Current password is incorrect.', 401, 'INVALID_CURRENT_PASSWORD');
}

if (password_verify($newPassword, (string) $currentUser['password_hash'])) {
    json_response([
        'success' => false,
        'message' => 'New password must be different from your current password.',
        'errors'  => ['new_password' => 'New password must be different from your current password.'],
    ], 422);
}

$stmt = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
$stmt->execute([
    'password_hash' => password_hash($newPassword, PASSWORD_BCRYPT),
    'id'             => (int) $currentUser['id'],
]);

// Log the caller out of every other device by invalidating all their
// other active tokens, keeping only the one used for this request —
// standard practice after a password change.
$currentToken = get_bearer_token();
if ($currentToken !== null && $currentToken !== '') {
    $revokeStmt = $pdo->prepare('DELETE FROM auth_tokens WHERE user_id = :user_id AND token != :token');
    $revokeStmt->execute([
        'user_id' => (int) $currentUser['id'],
        'token'   => $currentToken,
    ]);
}

json_response([
    'success' => true,
    'message' => 'Password changed successfully.',
]);
