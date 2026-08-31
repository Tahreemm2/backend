<?php
/**
 * =============================================================================
 * FILE: api/login.php
 * PURPOSE: Authentication endpoint for the MEPCO Meter Testing app.
 *
 * Mirrors lib/features/auth/bloc/auth_bloc.dart exactly:
 *   - LoginSubmitted     -> action=login
 *   - OtpSubmitted       -> action=verify_otp
 *   - OtpResendRequested -> action=resend_otp
 *
 * REQUEST (all actions are POST, JSON body):
 *
 *   1) Login
 *      POST /api/login.php
 *      { "action": "login", "username": "g.mustafa", "password": "test1234" }
 *
 *      Response (returning user, no OTP needed) — HTTP 200:
 *      {
 *        "success": true,
 *        "requires_otp": false,
 *        "employee_id": "EMP-1042", "full_name": "Ghulam Mustafa",
 *        "username": "g.mustafa", "role_code": "MT", "scope_code": "SUB_DIVISION",
 *        "scope_name": "Multan North Sub-Division",
 *        "token": "....", "is_first_login": false, "contact_masked": "03**-***-4892"
 *      }
 *
 *      Response (first-time login, OTP required) — HTTP 200:
 *      {
 *        "success": true, "requires_otp": true,
 *        "temp_token": "....", "cooldown_seconds": 60,
 *        "employee_id": "EMP-9999", "full_name": "System Administrator",
 *        "username": "admin", "role_code": "ADMIN", "scope_code": "NATIONAL",
 *        "scope_name": "National (All Regions)", "contact_masked": "03**-***-0001"
 *      }
 *
 *   2) Verify OTP
 *      POST /api/login.php
 *      { "action": "verify_otp", "temp_token": "....", "otp_code": "123456" }
 *      -> Response: full UserModel payload (same shape as non-OTP login), HTTP 200.
 *
 *   3) Resend OTP
 *      POST /api/login.php
 *      { "action": "resend_otp", "temp_token": "...." }
 *      -> Response: { "success": true, "message": "...", "cooldown_seconds": 60 }
 * =============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

send_common_headers();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('This endpoint only accepts POST requests.', 405, 'METHOD_NOT_ALLOWED');
}

$body   = get_json_body();
$action = strtolower(trim((string) ($body['action'] ?? 'login')));

try {
    $pdo = get_db_connection();
} catch (PDOException $e) {
    json_error('Database connection failed. Please try again later.', 500, 'DB_CONNECTION_ERROR');
}

$otpTtlMinutes = (int) env_get('OTP_TTL_MINUTES', '5');
$tokenTtlHours = (int) env_get('AUTH_TOKEN_TTL_HOURS', '168');

switch ($action) {

    // -------------------------------------------------------------------
    // ACTION: login
    // -------------------------------------------------------------------
    case 'login':
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        if ($username === '' || $password === '') {
            json_error('Username and password are required.', 422, 'VALIDATION_ERROR');
        }

        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = :username AND is_active = 1 LIMIT 1');
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        if ($user === false || !password_verify($password, $user['password_hash'])) {
            json_error('Invalid credentials. Please check your username and password.', 401, 'INVALID_CREDENTIALS');
        }

        if ((int) $user['is_first_login'] === 1) {
            // First-time login -> issue OTP + short-lived temp token, do NOT issue a full auth token yet.
            $otpCode   = generate_otp_code();
            $tempToken = generate_secure_token();

            $update = $pdo->prepare(
                'UPDATE users
                 SET otp_code = :otp_code,
                     otp_expires_at = :otp_expires_at,
                     otp_temp_token = :temp_token,
                     otp_temp_expires_at = :temp_expires_at
                 WHERE id = :id'
            );
            $update->execute([
                'otp_code'       => $otpCode,
                'otp_expires_at' => date('Y-m-d H:i:s', time() + ($otpTtlMinutes * 60)),
                'temp_token'     => $tempToken,
                'temp_expires_at'=> date('Y-m-d H:i:s', time() + ($otpTtlMinutes * 60)),
                'id'             => $user['id'],
            ]);

            // NOTE: In production, dispatch $otpCode via SMS gateway here instead
            // of trusting the client. It is intentionally NOT returned in the
            // JSON response below.

            json_response([
                'success'          => true,
                'requires_otp'     => true,
                'temp_token'       => $tempToken,
                'cooldown_seconds' => 60,
                'employee_id'      => $user['employee_id'],
                'full_name'        => $user['full_name'],
                'username'         => $user['username'],
                'role_code'        => $user['role_code'],
                'scope_code'       => $user['scope_code'],
                'scope_name'       => $user['scope_name'],
                'contact_masked'   => mask_contact_number($user['contact_number']),
            ]);
        }

        // Returning user -> issue a full auth token immediately.
        $token = generate_secure_token();
        $insertToken = $pdo->prepare(
            'INSERT INTO auth_tokens (user_id, token, expires_at) VALUES (:user_id, :token, :expires_at)'
        );
        $insertToken->execute([
            'user_id'    => $user['id'],
            'token'      => $token,
            'expires_at' => date('Y-m-d H:i:s', time() + ($tokenTtlHours * 3600)),
        ]);

        json_response([
            'success'      => true,
            'requires_otp' => false,
        ] + build_user_payload($user, $token));
        break;

    // -------------------------------------------------------------------
    // ACTION: verify_otp
    // -------------------------------------------------------------------
    case 'verify_otp':
        $tempToken = trim((string) ($body['temp_token'] ?? ''));
        $otpCode   = trim((string) ($body['otp_code'] ?? ''));

        if ($tempToken === '' || $otpCode === '') {
            json_error('temp_token and otp_code are required.', 422, 'VALIDATION_ERROR');
        }

        $stmt = $pdo->prepare('SELECT * FROM users WHERE otp_temp_token = :temp_token LIMIT 1');
        $stmt->execute(['temp_token' => $tempToken]);
        $user = $stmt->fetch();

        if ($user === false) {
            json_error('Invalid or expired session. Please log in again.', 401, 'OTP_SESSION_INVALID');
        }

        if ($user['otp_temp_expires_at'] === null || strtotime((string) $user['otp_temp_expires_at']) < time()) {
            json_error('This OTP session has expired. Please log in again.', 401, 'OTP_SESSION_EXPIRED');
        }

        if (!hash_equals((string) $user['otp_code'], $otpCode)) {
            json_error('Incorrect PIN. Please check and try again.', 401, 'OTP_INVALID');
        }

        // OTP correct -> clear OTP state, flip is_first_login off, issue real auth token.
        $clear = $pdo->prepare(
            'UPDATE users
             SET is_first_login = 0, otp_code = NULL, otp_expires_at = NULL,
                 otp_temp_token = NULL, otp_temp_expires_at = NULL
             WHERE id = :id'
        );
        $clear->execute(['id' => $user['id']]);
        $user['is_first_login'] = 0;

        $token = generate_secure_token();
        $insertToken = $pdo->prepare(
            'INSERT INTO auth_tokens (user_id, token, expires_at) VALUES (:user_id, :token, :expires_at)'
        );
        $insertToken->execute([
            'user_id'    => $user['id'],
            'token'      => $token,
            'expires_at' => date('Y-m-d H:i:s', time() + ($tokenTtlHours * 3600)),
        ]);

        json_response([
            'success'      => true,
            'requires_otp' => false,
        ] + build_user_payload($user, $token));
        break;

    // -------------------------------------------------------------------
    // ACTION: resend_otp
    // -------------------------------------------------------------------
    case 'resend_otp':
        $tempToken = trim((string) ($body['temp_token'] ?? ''));

        if ($tempToken === '') {
            json_error('temp_token is required.', 422, 'VALIDATION_ERROR');
        }

        $stmt = $pdo->prepare('SELECT * FROM users WHERE otp_temp_token = :temp_token LIMIT 1');
        $stmt->execute(['temp_token' => $tempToken]);
        $user = $stmt->fetch();

        if ($user === false) {
            json_error('Invalid or expired session. Please log in again.', 401, 'OTP_SESSION_INVALID');
        }

        $otpCode = generate_otp_code();
        $update = $pdo->prepare(
            'UPDATE users
             SET otp_code = :otp_code, otp_expires_at = :otp_expires_at, otp_temp_expires_at = :temp_expires_at
             WHERE id = :id'
        );
        $update->execute([
            'otp_code'        => $otpCode,
            'otp_expires_at'  => date('Y-m-d H:i:s', time() + ($otpTtlMinutes * 60)),
            'temp_expires_at' => date('Y-m-d H:i:s', time() + ($otpTtlMinutes * 60)),
            'id'              => $user['id'],
        ]);

        // NOTE: dispatch $otpCode via SMS gateway here in production.

        json_response([
            'success'          => true,
            'message'          => 'A new OTP has been sent.',
            'cooldown_seconds' => 60,
        ]);
        break;

    default:
        json_error("Unknown action '{$action}'. Expected one of: login, verify_otp, resend_otp.", 400, 'UNKNOWN_ACTION');
}
