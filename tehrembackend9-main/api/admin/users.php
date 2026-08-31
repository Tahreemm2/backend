<?php
/**
 * =============================================================================
 * FILE: api/admin/users.php
 * PURPOSE: Full CRUD for employee accounts. ADMIN role only.
 *
 * REQUEST (all require "Authorization: Bearer <token>" for an ADMIN user):
 *
 *   GET    /api/admin/users.php                 -> paginated list
 *   GET    /api/admin/users.php?id=5             -> single user
 *   POST   /api/admin/users.php                  -> create user
 *          { "employee_id","full_name","username","password",
 *            "role_code","scope_code","scope_name","contact_number" }
 *   PUT    /api/admin/users.php?id=5              -> update user
 *          { "full_name","role_code","scope_code","scope_name",
 *            "contact_number","is_active","password" (optional, resets it) }
 *   DELETE /api/admin/users.php?id=5              -> deactivate user (soft delete;
 *          hard-deleting is avoided because inspections reference submitted_by_user_id)
 *
 * Passwords and OTP fields are never included in any response.
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
require_role($currentUser, ['ADMIN']);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

const PUBLIC_USER_COLUMNS = 'id, employee_id, full_name, username, role_code, scope_code, scope_name, contact_number, is_first_login, is_active, created_at, updated_at';

$validRoles  = ['MT', 'SDO', 'XEN', 'SE', 'ADMIN'];
$validScopes = ['SUB_DIVISION', 'DIVISION', 'CIRCLE', 'REGION', 'NATIONAL'];

switch ($method) {

    // -------------------------------------------------------------------
    case 'GET':
        $id = $_GET['id'] ?? null;

        if ($id !== null) {
            $stmt = $pdo->prepare('SELECT ' . PUBLIC_USER_COLUMNS . ' FROM users WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => (int) $id]);
            $user = $stmt->fetch();

            if ($user === false) {
                json_error('User not found.', 404, 'USER_NOT_FOUND');
            }

            json_response(['success' => true, 'data' => $user]);
        }

        [$limit, $offset] = get_pagination();

        $stmt = $pdo->prepare(
            "SELECT " . PUBLIC_USER_COLUMNS . "
             FROM users
             ORDER BY created_at DESC
             LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $total = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

        json_response([
            'success' => true,
            'data'    => $stmt->fetchAll(),
            'total'   => $total,
        ]);
        break;

    // -------------------------------------------------------------------
    case 'POST':
        $body = get_json_body();

        $employeeId     = trim((string) ($body['employee_id'] ?? ''));
        $fullName       = trim((string) ($body['full_name'] ?? ''));
        $username       = trim((string) ($body['username'] ?? ''));
        $password       = (string) ($body['password'] ?? '');
        $roleCode       = trim((string) ($body['role_code'] ?? ''));
        $scopeCode      = trim((string) ($body['scope_code'] ?? ''));
        $scopeName      = trim((string) ($body['scope_name'] ?? ''));
        $contactNumber  = trim((string) ($body['contact_number'] ?? ''));
        $isFirstLogin   = (bool) ($body['is_first_login'] ?? true);

        $errors = [];
        if ($employeeId === '') $errors['employee_id'] = 'Employee ID is required.';
        if ($fullName === '') $errors['full_name'] = 'Full name is required.';
        if ($username === '') $errors['username'] = 'Username is required.';
        if (strlen($password) < 8) $errors['password'] = 'Password must be at least 8 characters.';
        if (!in_array($roleCode, $validRoles, true)) $errors['role_code'] = 'role_code must be one of: ' . implode(', ', $validRoles);
        if (!in_array($scopeCode, $validScopes, true)) $errors['scope_code'] = 'scope_code must be one of: ' . implode(', ', $validScopes);
        if ($scopeName === '') $errors['scope_name'] = 'Scope name is required.';

        if (!empty($errors)) {
            json_response(['success' => false, 'message' => 'Validation failed.', 'errors' => $errors], 422);
        }

        try {
            $insert = $pdo->prepare(
                'INSERT INTO users
                    (employee_id, full_name, username, password_hash, role_code, scope_code, scope_name, contact_number, is_first_login)
                 VALUES
                    (:employee_id, :full_name, :username, :password_hash, :role_code, :scope_code, :scope_name, :contact_number, :is_first_login)'
            );
            $insert->execute([
                'employee_id'    => $employeeId,
                'full_name'      => $fullName,
                'username'       => $username,
                'password_hash'  => password_hash($password, PASSWORD_BCRYPT),
                'role_code'      => $roleCode,
                'scope_code'     => $scopeCode,
                'scope_name'     => $scopeName,
                'contact_number' => $contactNumber !== '' ? $contactNumber : null,
                'is_first_login' => $isFirstLogin ? 1 : 0,
            ]);
        } catch (PDOException $e) {
            // 23000 = integrity constraint violation (duplicate employee_id/username)
            if ($e->getCode() === '23000') {
                json_error('An account with that employee_id or username already exists.', 409, 'DUPLICATE_USER');
            }
            throw $e;
        }

        json_response(['success' => true, 'message' => 'User created.', 'id' => (int) $pdo->lastInsertId()], 201);
        break;

    // -------------------------------------------------------------------
    case 'PUT':
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            json_error('Query parameter "id" is required.', 422, 'VALIDATION_ERROR');
        }

        $stmt = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        if ($stmt->fetch() === false) {
            json_error('User not found.', 404, 'USER_NOT_FOUND');
        }

        $body = get_json_body();
        $fields = [];
        $params = ['id' => $id];

        if (isset($body['full_name'])) { $fields[] = 'full_name = :full_name'; $params['full_name'] = trim((string) $body['full_name']); }
        if (isset($body['role_code'])) {
            if (!in_array($body['role_code'], $validRoles, true)) json_error('role_code must be one of: ' . implode(', ', $validRoles), 422, 'VALIDATION_ERROR');
            $fields[] = 'role_code = :role_code'; $params['role_code'] = $body['role_code'];
        }
        if (isset($body['scope_code'])) {
            if (!in_array($body['scope_code'], $validScopes, true)) json_error('scope_code must be one of: ' . implode(', ', $validScopes), 422, 'VALIDATION_ERROR');
            $fields[] = 'scope_code = :scope_code'; $params['scope_code'] = $body['scope_code'];
        }
        if (isset($body['scope_name'])) { $fields[] = 'scope_name = :scope_name'; $params['scope_name'] = trim((string) $body['scope_name']); }
        if (isset($body['contact_number'])) { $fields[] = 'contact_number = :contact_number'; $params['contact_number'] = trim((string) $body['contact_number']); }
        if (isset($body['is_active'])) { $fields[] = 'is_active = :is_active'; $params['is_active'] = ((bool) $body['is_active']) ? 1 : 0; }
        if (isset($body['password']) && $body['password'] !== '') {
            if (strlen((string) $body['password']) < 8) json_error('Password must be at least 8 characters.', 422, 'VALIDATION_ERROR');
            $fields[] = 'password_hash = :password_hash';
            $params['password_hash'] = password_hash((string) $body['password'], PASSWORD_BCRYPT);
        }

        if (empty($fields)) {
            json_error('No updatable fields were provided.', 422, 'VALIDATION_ERROR');
        }

        $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $pdo->prepare($sql)->execute($params);

        json_response(['success' => true, 'message' => 'User updated.']);
        break;

    // -------------------------------------------------------------------
    case 'DELETE':
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            json_error('Query parameter "id" is required.', 422, 'VALIDATION_ERROR');
        }

        if ($id === (int) $currentUser['id']) {
            json_error('You cannot deactivate your own account.', 400, 'CANNOT_DEACTIVATE_SELF');
        }

        $stmt = $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = :id');
        $stmt->execute(['id' => $id]);

        if ($stmt->rowCount() === 0) {
            json_error('User not found.', 404, 'USER_NOT_FOUND');
        }

        // Revoke any active sessions for the deactivated user.
        $pdo->prepare('DELETE FROM auth_tokens WHERE user_id = :id')->execute(['id' => $id]);

        json_response(['success' => true, 'message' => 'User deactivated.']);
        break;

    default:
        json_error('Method not allowed. Use GET, POST, PUT, or DELETE.', 405, 'METHOD_NOT_ALLOWED');
}
