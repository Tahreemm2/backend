<?php
/**
 * =============================================================================
 * FILE: api/admin/approval_rules.php
 * PURPOSE: Configures which supervisory levels (SDO / XEN / SE) each meter
 * category's Approval Workflow (spec 3.10) must pass through — "Workflow
 * depends on meter category". Exactly 4 rows always exist (one per
 * VALID_CATEGORIES, seeded by schema.sql / migrations/003_approval_workflow.sql)
 * so this endpoint only ever reads or updates a row — there's no create/delete.
 *
 * REQUESTS (all require "Authorization: Bearer <token>" for an ADMIN user):
 *
 *   GET /api/admin/approval_rules.php                 -> all 4 category rules
 *   GET /api/admin/approval_rules.php?category=B2     -> single category's rule
 *   PUT /api/admin/approval_rules.php?category=B2      -> update a category's chain
 *       Body: any of { "requires_sdo": bool, "requires_xen": bool, "requires_se": bool }
 *       All three false means that category is auto-approved on submission
 *       (no manual review at all) — allowed, since some deployments may not
 *       want review for low-risk categories.
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

switch ($method) {

    // -------------------------------------------------------------------
    case 'GET':
        $category = $_GET['category'] ?? null;

        if ($category !== null) {
            $category = strtoupper(trim((string) $category));
            if (!in_array($category, VALID_CATEGORIES, true)) {
                json_error('category must be one of: ' . implode(', ', VALID_CATEGORIES) . '.', 422, 'VALIDATION_ERROR');
            }

            $stmt = $pdo->prepare('SELECT * FROM approval_workflow_rules WHERE category = :category LIMIT 1');
            $stmt->execute(['category' => $category]);
            $rule = $stmt->fetch();

            if ($rule === false) {
                json_error('No approval rule found for that category.', 404, 'OPTION_NOT_FOUND');
            }

            json_response(['success' => true, 'data' => $rule]);
        }

        $stmt = $pdo->prepare("SELECT * FROM approval_workflow_rules ORDER BY FIELD(category,'B1','B2','B3','B4')");
        $stmt->execute();

        json_response(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    // -------------------------------------------------------------------
    case 'PUT':
        $category = strtoupper(trim((string) ($_GET['category'] ?? '')));
        if (!in_array($category, VALID_CATEGORIES, true)) {
            json_error('Query parameter "category" is required and must be one of: ' . implode(', ', VALID_CATEGORIES) . '.', 422, 'VALIDATION_ERROR');
        }

        $body = get_json_body();
        $fields = [];
        $params = ['category' => $category];

        if (isset($body['requires_sdo'])) { $fields[] = 'requires_sdo = :requires_sdo'; $params['requires_sdo'] = ((bool) $body['requires_sdo']) ? 1 : 0; }
        if (isset($body['requires_xen'])) { $fields[] = 'requires_xen = :requires_xen'; $params['requires_xen'] = ((bool) $body['requires_xen']) ? 1 : 0; }
        if (isset($body['requires_se']))  { $fields[] = 'requires_se = :requires_se';   $params['requires_se']  = ((bool) $body['requires_se'])  ? 1 : 0; }

        if (empty($fields)) {
            json_error('No updatable fields were provided. Expected any of: requires_sdo, requires_xen, requires_se.', 422, 'VALIDATION_ERROR');
        }

        $sql = 'UPDATE approval_workflow_rules SET ' . implode(', ', $fields) . ' WHERE category = :category';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            // Either no row for that category (shouldn't happen — seeded for all
            // four) or the values submitted matched what was already stored.
            $checkStmt = $pdo->prepare('SELECT 1 FROM approval_workflow_rules WHERE category = :category LIMIT 1');
            $checkStmt->execute(['category' => $category]);
            if ($checkStmt->fetchColumn() === false) {
                json_error('No approval rule found for that category.', 404, 'OPTION_NOT_FOUND');
            }
        }

        json_response(['success' => true, 'message' => 'Approval workflow rule updated.']);
        break;

    default:
        json_error('Method not allowed. Use GET or PUT.', 405, 'METHOD_NOT_ALLOWED');
}
