<?php
/**
 * =============================================================================
 * FILE: api/admin/consumers.php
 * PURPOSE: Consumer/meter records (the data behind the "Auto-Fetch Data"
 * feature in the Inspection form, and "Consumer/Meter Records" in the SDO
 * spec — "The SDO can view consumer details... view meter details...
 * search... filter by sub-division" but "should not create or delete
 * consumer records unless the client later grants that permission").
 *
 * GET is open to every supervisory role (SDO/XEN/SE/ADMIN), with SDO/XEN
 * enforced server-side to their own sub-division(s)/division — see
 * enforced_scope_sql() in helpers.php. POST/PUT/DELETE remain ADMIN-only.
 *
 * REQUEST (all require "Authorization: Bearer <token>"):
 *
 *   GET    /api/admin/consumers.php                -> paginated list (supervisory roles)
 *          ?search=&division=&sub_division=&category=B2&page=&per_page=
 *   GET    /api/admin/consumers.php?id=3            -> single consumer (supervisory roles)
 *   POST   /api/admin/consumers.php                 -> create consumer (ADMIN only)
 *          { "reference_number","meter_id","consumer_name","consumer_address",
 *            "consumer_account","tariff_category","sanctioned_load",
 *            "division"?,"sub_division"?,"category"? }  ("category" one of B1-B4, used by the Scheduling System)
 *   PUT    /api/admin/consumers.php?id=3             -> update consumer (ADMIN only; any subset of the above fields)
 *   DELETE /api/admin/consumers.php?id=3             -> permanently delete consumer record (ADMIN only)
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

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    require_role($currentUser, SUPERVISORY_ROLES);
} else {
    require_role($currentUser, ['ADMIN']);
}

$requiredFields = [
    'reference_number', 'meter_id', 'consumer_name',
    'consumer_address', 'consumer_account', 'tariff_category', 'sanctioned_load',
];

// Optional — used as Scheduling System filters. Not required so existing
// integrations that don't send them keep working.
$optionalFields = ['division', 'sub_division', 'category'];
$editableFields = array_merge($requiredFields, $optionalFields);

switch ($method) {

    // -------------------------------------------------------------------
    case 'GET':
        $id = $_GET['id'] ?? null;

        if ($id !== null) {
            $stmt = $pdo->prepare('SELECT * FROM consumers WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => (int) $id]);
            $consumer = $stmt->fetch();

            if ($consumer === false) {
                json_error('Consumer record not found.', 404, 'CONSUMER_NOT_FOUND');
            }
            if (!is_within_enforced_scope($currentUser, $consumer)) {
                json_error('You do not have permission to view this consumer record.', 403, 'FORBIDDEN_ROLE');
            }

            json_response(['success' => true, 'data' => $consumer]);
        }

        [$limit, $offset] = get_pagination();
        $search      = trim((string) ($_GET['search'] ?? ''));
        $division    = trim((string) ($_GET['division'] ?? ''));
        $subDivision = trim((string) ($_GET['sub_division'] ?? ''));
        $category    = strtoupper(trim((string) ($_GET['category'] ?? '')));

        $conditions = [];
        $params = [];

        if ($search !== '') {
            $conditions[] = '(reference_number LIKE :search OR consumer_name LIKE :search OR consumer_account LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }
        if ($division !== '') {
            $conditions[] = 'division = :division';
            $params['division'] = $division;
        }
        if ($subDivision !== '') {
            $conditions[] = 'sub_division = :sub_division';
            $params['sub_division'] = $subDivision;
        }
        if ($category !== '') {
            $conditions[] = 'category = :category';
            $params['category'] = $category;
        }

        // Server-side enforcement — see enforced_scope_sql() in helpers.php.
        [$scopeSql, $scopeParams] = enforced_scope_sql($currentUser, 'division', 'sub_division');
        if ($scopeSql !== '') {
            $conditions[] = $scopeSql;
            $params = array_merge($params, $scopeParams);
        }

        $whereSql = empty($conditions) ? '' : ('WHERE ' . implode(' AND ', $conditions));

        $stmt = $pdo->prepare("SELECT * FROM consumers {$whereSql} ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM consumers {$whereSql}");
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
        $body = get_json_body();
        $errors = [];

        foreach ($requiredFields as $field) {
            if (trim((string) ($body[$field] ?? '')) === '') {
                $errors[$field] = ucwords(str_replace('_', ' ', $field)) . ' is required.';
            }
        }

        $category = strtoupper(trim((string) ($body['category'] ?? '')));
        if ($category !== '' && !in_array($category, VALID_CATEGORIES, true)) {
            $errors['category'] = 'category must be one of: ' . implode(', ', VALID_CATEGORIES) . '.';
        }

        if (!empty($errors)) {
            json_response(['success' => false, 'message' => 'Validation failed.', 'errors' => $errors], 422);
        }

        try {
            $insert = $pdo->prepare(
                'INSERT INTO consumers
                    (reference_number, meter_id, consumer_name, consumer_address, division, sub_division,
                     consumer_account, tariff_category, category, sanctioned_load)
                 VALUES
                    (:reference_number, :meter_id, :consumer_name, :consumer_address, :division, :sub_division,
                     :consumer_account, :tariff_category, :category, :sanctioned_load)'
            );
            $insert->execute([
                'reference_number' => strtoupper(trim((string) $body['reference_number'])),
                'meter_id'         => trim((string) $body['meter_id']),
                'consumer_name'    => trim((string) $body['consumer_name']),
                'consumer_address' => trim((string) $body['consumer_address']),
                'division'         => trim((string) ($body['division'] ?? '')) ?: null,
                'sub_division'     => trim((string) ($body['sub_division'] ?? '')) ?: null,
                'consumer_account' => trim((string) $body['consumer_account']),
                'tariff_category'  => trim((string) $body['tariff_category']),
                'category'         => $category !== '' ? $category : null,
                'sanctioned_load'  => trim((string) $body['sanctioned_load']),
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                json_error('A consumer record with that reference_number already exists.', 409, 'DUPLICATE_REFERENCE');
            }
            throw $e;
        }

        json_response(['success' => true, 'message' => 'Consumer record created.', 'id' => (int) $pdo->lastInsertId()], 201);
        break;

    // -------------------------------------------------------------------
    case 'PUT':
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            json_error('Query parameter "id" is required.', 422, 'VALIDATION_ERROR');
        }

        $stmt = $pdo->prepare('SELECT id FROM consumers WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        if ($stmt->fetch() === false) {
            json_error('Consumer record not found.', 404, 'CONSUMER_NOT_FOUND');
        }

        $body = get_json_body();
        $fields = [];
        $params = ['id' => $id];

        foreach ($editableFields as $field) {
            if (isset($body[$field])) {
                $value = trim((string) $body[$field]);

                if ($field === 'reference_number') {
                    $value = strtoupper($value);
                }

                if ($field === 'category') {
                    $value = strtoupper($value);
                    if ($value !== '' && !in_array($value, VALID_CATEGORIES, true)) {
                        json_error('category must be one of: ' . implode(', ', VALID_CATEGORIES) . '.', 422, 'VALIDATION_ERROR');
                    }
                }

                // Optional fields (division/sub_division/category) store NULL
                // when cleared, rather than an empty string.
                if (in_array($field, $optionalFields, true) && $value === '') {
                    $fields[] = "{$field} = NULL";
                    continue;
                }

                $fields[] = "{$field} = :{$field}";
                $params[$field] = $value;
            }
        }

        if (empty($fields)) {
            json_error('No updatable fields were provided.', 422, 'VALIDATION_ERROR');
        }

        try {
            $sql = 'UPDATE consumers SET ' . implode(', ', $fields) . ' WHERE id = :id';
            $pdo->prepare($sql)->execute($params);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                json_error('Another consumer record already uses that reference_number.', 409, 'DUPLICATE_REFERENCE');
            }
            throw $e;
        }

        json_response(['success' => true, 'message' => 'Consumer record updated.']);
        break;

    // -------------------------------------------------------------------
    case 'DELETE':
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            json_error('Query parameter "id" is required.', 422, 'VALIDATION_ERROR');
        }

        $stmt = $pdo->prepare('DELETE FROM consumers WHERE id = :id');
        $stmt->execute(['id' => $id]);

        if ($stmt->rowCount() === 0) {
            json_error('Consumer record not found.', 404, 'CONSUMER_NOT_FOUND');
        }

        json_response(['success' => true, 'message' => 'Consumer record deleted.']);
        break;

    default:
        json_error('Method not allowed. Use GET, POST, PUT, or DELETE.', 405, 'METHOD_NOT_ALLOWED');
}
