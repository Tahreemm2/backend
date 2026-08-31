<?php
/**
 * =============================================================================
 * FILE: api/admin/form_options.php
 * PURPOSE: Full CRUD for the dropdown options behind FormOptionsConfig
 * (Seal Condition / CT-PT-BT Box Status). ADMIN role only. Lets an admin
 * add/edit/retire options without a mobile app release, exactly as
 * inspection_config.dart's doc-comment describes.
 *
 * REQUEST (all require "Authorization: Bearer <token>" for an ADMIN user):
 *
 *   GET    /api/admin/form_options.php                     -> list all (both dropdowns)
 *   GET    /api/admin/form_options.php?dropdown_key=SEAL_CONDITION -> list one dropdown
 *   GET    /api/admin/form_options.php?id=2                 -> single option
 *   POST   /api/admin/form_options.php                      -> create option
 *          { "dropdown_key": "SEAL_CONDITION"|"CTPT_BOX", "code","label","description","sort_order" }
 *   PUT    /api/admin/form_options.php?id=2                  -> update option
 *          { "label","description","sort_order","is_active" }
 *   DELETE /api/admin/form_options.php?id=2                  -> permanently delete option
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
$validDropdownKeys = ['SEAL_CONDITION', 'CTPT_BOX'];

switch ($method) {

    // -------------------------------------------------------------------
    case 'GET':
        $id = $_GET['id'] ?? null;

        if ($id !== null) {
            $stmt = $pdo->prepare('SELECT * FROM form_options WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => (int) $id]);
            $option = $stmt->fetch();

            if ($option === false) {
                json_error('Form option not found.', 404, 'OPTION_NOT_FOUND');
            }

            json_response(['success' => true, 'data' => $option]);
        }

        $dropdownKey = $_GET['dropdown_key'] ?? null;

        if ($dropdownKey !== null) {
            if (!in_array($dropdownKey, $validDropdownKeys, true)) {
                json_error('dropdown_key must be one of: ' . implode(', ', $validDropdownKeys), 422, 'VALIDATION_ERROR');
            }
            $stmt = $pdo->prepare('SELECT * FROM form_options WHERE dropdown_key = :key ORDER BY sort_order ASC');
            $stmt->execute(['key' => $dropdownKey]);
        } else {
            $stmt = $pdo->prepare('SELECT * FROM form_options ORDER BY dropdown_key, sort_order ASC');
            $stmt->execute();
        }

        json_response(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    // -------------------------------------------------------------------
    case 'POST':
        $body = get_json_body();

        $dropdownKey = trim((string) ($body['dropdown_key'] ?? ''));
        $code        = trim((string) ($body['code'] ?? ''));
        $label       = trim((string) ($body['label'] ?? ''));
        $description = trim((string) ($body['description'] ?? ''));
        $sortOrder   = (int) ($body['sort_order'] ?? 0);

        $errors = [];
        if (!in_array($dropdownKey, $validDropdownKeys, true)) $errors['dropdown_key'] = 'dropdown_key must be one of: ' . implode(', ', $validDropdownKeys);
        if ($code === '') $errors['code'] = 'Code is required.';
        if ($label === '') $errors['label'] = 'Label is required.';

        if (!empty($errors)) {
            json_response(['success' => false, 'message' => 'Validation failed.', 'errors' => $errors], 422);
        }

        try {
            $insert = $pdo->prepare(
                'INSERT INTO form_options (dropdown_key, code, label, description, sort_order)
                 VALUES (:dropdown_key, :code, :label, :description, :sort_order)'
            );
            $insert->execute([
                'dropdown_key' => $dropdownKey,
                'code'         => strtoupper($code),
                'label'        => $label,
                'description'  => $description !== '' ? $description : null,
                'sort_order'   => $sortOrder,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                json_error('That code already exists for this dropdown.', 409, 'DUPLICATE_OPTION');
            }
            throw $e;
        }

        json_response(['success' => true, 'message' => 'Form option created.', 'id' => (int) $pdo->lastInsertId()], 201);
        break;

    // -------------------------------------------------------------------
    case 'PUT':
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            json_error('Query parameter "id" is required.', 422, 'VALIDATION_ERROR');
        }

        $stmt = $pdo->prepare('SELECT id FROM form_options WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        if ($stmt->fetch() === false) {
            json_error('Form option not found.', 404, 'OPTION_NOT_FOUND');
        }

        $body = get_json_body();
        $fields = [];
        $params = ['id' => $id];

        if (isset($body['label'])) { $fields[] = 'label = :label'; $params['label'] = trim((string) $body['label']); }
        if (isset($body['description'])) { $fields[] = 'description = :description'; $params['description'] = trim((string) $body['description']); }
        if (isset($body['sort_order'])) { $fields[] = 'sort_order = :sort_order'; $params['sort_order'] = (int) $body['sort_order']; }
        if (isset($body['is_active'])) { $fields[] = 'is_active = :is_active'; $params['is_active'] = ((bool) $body['is_active']) ? 1 : 0; }

        if (empty($fields)) {
            json_error('No updatable fields were provided.', 422, 'VALIDATION_ERROR');
        }

        $sql = 'UPDATE form_options SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $pdo->prepare($sql)->execute($params);

        json_response(['success' => true, 'message' => 'Form option updated.']);
        break;

    // -------------------------------------------------------------------
    case 'DELETE':
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            json_error('Query parameter "id" is required.', 422, 'VALIDATION_ERROR');
        }

        $stmt = $pdo->prepare('DELETE FROM form_options WHERE id = :id');
        $stmt->execute(['id' => $id]);

        if ($stmt->rowCount() === 0) {
            json_error('Form option not found.', 404, 'OPTION_NOT_FOUND');
        }

        json_response(['success' => true, 'message' => 'Form option deleted.']);
        break;

    default:
        json_error('Method not allowed. Use GET, POST, PUT, or DELETE.', 405, 'METHOD_NOT_ALLOWED');
}
