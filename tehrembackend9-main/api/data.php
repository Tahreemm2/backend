<?php
/**
 * =============================================================================
 * FILE: api/data.php
 * PURPOSE: Protected data endpoint for the MEPCO Meter Testing app.
 * Every route below requires: "Authorization: Bearer <token>" (issued by
 * api/login.php). Routes are selected via the ?action= query parameter.
 *
 * Mirrors lib/features/inspection/ exactly:
 *
 *   1) Form dropdown options (FormOptionsConfig.fromJson)
 *      GET /api/data.php?action=form-options
 *      -> { "success": true, "seal_conditions": [...], "ctpt_box_statuses": [...] }
 *
 *   2) Consumer auto-fetch (ConsumerFetchResult)
 *      GET /api/data.php?action=consumer-fetch&ref=REF-2025-00142
 *      -> { "success": true, "meter_id": "...", "consumer_name": "...", ... }
 *      -> 404 { "success": false, "message": "..." } if not found
 * *   3) Submit inspection (InspectionSubmissionPayload.toJson shape)
 *      POST /api/data.php?action=inspection-submit
 *      Field roles only (M&T) — supervisory roles (SDO/XEN/SE/ADMIN) get
 *      403 FORBIDDEN_ROLE. They monitor, assign, and approve inspections
 *      but don't perform them, per the SRS.
 *      Body: {
 *        "client_uuid"?,                                          (offline sync idempotency key — recommended)
 *        "reference_number", "meter_id", "consumer_account", "inspection_datetime",
 *        "readings": {"kwh","kvarh","mdi"},
 *        "tou_readings": {"peak","off_peak","day","night"},
 *        "infrastructure": {"seal_condition","ctpt_box_status"},
 *        "gps": {"latitude","longitude","accuracy_meters"?},      (mandatory — auto-captured by the app)
 *        "images": [                                              (2-12 required, each geo-tagged & timestamped)
 *          {"type":"METER"|"SEAL"|"INSTALLATION"|"LOAD","data_base64":"...","latitude":..,"longitude":..,"captured_at":"..."}
 *        ],
 *        "task_id"?,                                               (auto-completes the linked task + schedule)
 *        "load_details"
 *      }
 *      -> { "success": true, "message": "...", "id": 1, "image_urls": [...],
 *           "overall_status": "PENDING_APPROVAL"|"APPROVED", "current_approval_level": 1 }
 *      -> 409 DUPLICATE_INSPECTION if the same visit was already recorded and no client_uuid was given.
 *      -> 200 { "duplicate": true, ... } (idempotent no-op) if client_uuid matches a prior submission.
 *      Note: on success this also enrolls the inspection in the Approval Workflow
 *      (3.10) — see api/approvals.php. The required review chain (SDO/XEN/SE)
 *      is looked up from the consumer's category via approval_workflow_rules;
 *      a category configured to skip all levels is APPROVED immediately.
 *
 *   4) List recent inspections (sample fetch/CRUD, default action)
 *      GET /api/data.php  OR  GET /api/data.php?action=inspections-list
 *        ?search=&status=&division=&sub_division=&category=&date_from=&date_to=&page=&per_page=
 *      -> { "success": true, "data": [ {...}, {...} ], "total": N }
 *      -> MT: always scoped to inspections they personally submitted.
 *      -> SDO/XEN: server-side enforced to their own sub-division(s)/division
 *         (division/sub_division params may only narrow further within
 *         that — see enforced_scope_sql() in config/helpers.php).
 *      -> SE/ADMIN: full list, optionally filtered.
 *      -> "status" filters by Approval Workflow outcome: PENDING_APPROVAL,
 *         APPROVED, or REJECTED.
 *      -> "search" matches meter_id, reference_number, consumer_account, or consumer_name.
 *      (Backward-compatible: a bare "?limit=" param from older clients still
 *      works and maps to per_page.)
 *
 *   5) Single inspection detail — every reading, GPS, and uploaded images
 *      GET /api/data.php?action=inspection-detail&id=42
 *      -> { "success": true, "data": { ...all inspection fields, "images": [...] } }
 *      -> Same scoping rules as inspections-list, plus the record's own submitter.
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

// Every route on this file is protected.
$currentUser = require_authenticated_user($pdo);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = strtolower(trim((string) ($_GET['action'] ?? 'inspections-list')));

switch ($action) {

    // -------------------------------------------------------------------
    // GET ?action=form-options
    // -------------------------------------------------------------------
    case 'form-options':
        if ($method !== 'GET') {
            json_error('This action only accepts GET requests.', 405, 'METHOD_NOT_ALLOWED');
        }

        $stmt = $pdo->prepare(
            'SELECT dropdown_key, code, label, description
             FROM form_options
             WHERE is_active = 1
             ORDER BY dropdown_key, sort_order ASC'
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $sealConditions  = [];
        $ctPtBoxStatuses = [];

        foreach ($rows as $row) {
            $option = ['code' => $row['code'], 'label' => $row['label']];

            if ($row['dropdown_key'] === 'SEAL_CONDITION') {
                $sealConditions[] = $option;
            } elseif ($row['dropdown_key'] === 'CTPT_BOX') {
                $ctPtBoxStatuses[] = $option;
            }
        }

        json_response([
            'success'           => true,
            'seal_conditions'   => $sealConditions,
            'ctpt_box_statuses' => $ctPtBoxStatuses,
        ]);
        break;

    // -------------------------------------------------------------------
    // GET ?action=consumer-fetch&ref=REF-2025-00142
    // -------------------------------------------------------------------
    case 'consumer-fetch':
        if ($method !== 'GET') {
            json_error('This action only accepts GET requests.', 405, 'METHOD_NOT_ALLOWED');
        }

        $ref = trim((string) ($_GET['ref'] ?? ''));

        if ($ref === '') {
            json_error('Query parameter "ref" is required.', 422, 'VALIDATION_ERROR');
        }

        $stmt = $pdo->prepare('SELECT * FROM consumers WHERE reference_number = :ref LIMIT 1');
        $stmt->execute(['ref' => strtoupper($ref)]);
        $consumer = $stmt->fetch();

        if ($consumer === false) {
            json_error('No record found for this reference number.', 404, 'CONSUMER_NOT_FOUND');
        }

        json_response([
            'success'          => true,
            'meter_id'         => $consumer['meter_id'],
            'consumer_name'    => $consumer['consumer_name'],
            'consumer_address' => $consumer['consumer_address'],
            'consumer_account' => $consumer['consumer_account'],
            'tariff_category'  => $consumer['tariff_category'],
            'sanctioned_load'  => $consumer['sanctioned_load'],
        ]);
        break;

    // -------------------------------------------------------------------
    // POST ?action=inspection-submit
    // -------------------------------------------------------------------
    case 'inspection-submit':
        if ($method !== 'POST') {
            json_error('This action only accepts POST requests.', 405, 'METHOD_NOT_ALLOWED');
        }

        // Field inspection is M&T's job per the SRS — supervisory roles
        // (SDO/XEN/SE/ADMIN) monitor, assign, and approve inspections but
        // don't perform them. Enforced here so this can't be bypassed by
        // calling the API directly even though the app's UI already hides
        // the "Meter Test" entry point from these roles.
        if (in_array($currentUser['role_code'], SUPERVISORY_ROLES, true)) {
            json_error('Supervisory roles do not submit inspections directly — assign a task to a field team member instead.', 403, 'FORBIDDEN_ROLE');
        }

        $body = get_json_body();

        // ---- Offline sync / conflict resolution -------------------------------
        // If the client already synced this exact record before (e.g. it was
        // queued offline and the sync retried after a flaky connection), a
        // client_uuid match means "nothing to do" — return the original
        // record instead of erroring or inserting a duplicate.
        $clientUuid = trim((string) ($body['client_uuid'] ?? ''));
        if ($clientUuid !== '') {
            $existingStmt = $pdo->prepare('SELECT id FROM inspections WHERE client_uuid = :uuid LIMIT 1');
            $existingStmt->execute(['uuid' => $clientUuid]);
            $existingId = $existingStmt->fetchColumn();

            if ($existingId !== false) {
                json_response([
                    'success'   => true,
                    'message'   => 'Inspection already synced.',
                    'id'        => (int) $existingId,
                    'duplicate' => true,
                ]);
            }
        }

        $referenceNumber   = trim((string) ($body['reference_number'] ?? ''));
        $meterId           = trim((string) ($body['meter_id'] ?? ''));
        $consumerAccount   = trim((string) ($body['consumer_account'] ?? ''));
        $inspectionDateRaw = trim((string) ($body['inspection_datetime'] ?? ''));
        $loadDetails       = trim((string) ($body['load_details'] ?? ''));
        $taskId            = (int) ($body['task_id'] ?? 0);

        $readings       = is_array($body['readings'] ?? null) ? $body['readings'] : [];
        $touReadings    = is_array($body['tou_readings'] ?? null) ? $body['tou_readings'] : [];
        $infrastructure = is_array($body['infrastructure'] ?? null) ? $body['infrastructure'] : [];
        $gps            = is_array($body['gps'] ?? null) ? $body['gps'] : [];
        $images         = is_array($body['images'] ?? null) ? $body['images'] : [];

        $kwh   = $readings['kwh']   ?? null;
        $kvarh = $readings['kvarh'] ?? null;
        $mdi   = $readings['mdi']   ?? null;

        $touPeak    = $touReadings['peak']     ?? null;
        $touOffPeak = $touReadings['off_peak'] ?? null;
        $touDay     = $touReadings['day']      ?? null;
        $touNight   = $touReadings['night']    ?? null;

        $sealConditionCode = trim((string) ($infrastructure['seal_condition']  ?? ''));
        $ctPtBoxStatusCode = trim((string) ($infrastructure['ctpt_box_status'] ?? ''));

        $gpsLatitude  = $gps['latitude']  ?? null;
        $gpsLongitude = $gps['longitude'] ?? null;
        $gpsAccuracy  = $gps['accuracy_meters'] ?? null;

        $errors = [];
        if ($referenceNumber === '') $errors['reference_number'] = 'Reference number is required.';
        if ($meterId === '') $errors['meter_id'] = 'Meter ID is required.';
        if ($consumerAccount === '') $errors['consumer_account'] = 'Consumer account is required.';
        if ($inspectionDateRaw === '' || strtotime($inspectionDateRaw) === false) $errors['inspection_datetime'] = 'A valid ISO 8601 inspection_datetime is required.';
        if ($kwh === null || !is_numeric($kwh)) $errors['kwh'] = 'KWH reading is required and must be numeric.';
        if ($kvarh === null || !is_numeric($kvarh)) $errors['kvarh'] = 'KVARH reading is required and must be numeric.';
        if ($mdi === null || !is_numeric($mdi)) $errors['mdi'] = 'MDI reading is required and must be numeric.';
        if ($sealConditionCode === '') $errors['seal_condition'] = 'Seal condition is required.';
        if ($ctPtBoxStatusCode === '') $errors['ctpt_box_status'] = 'CT/PT box status is required.';

        // ---- GPS (mandatory field — auto-captured by the app) ------------------
        if ($gpsLatitude === null || $gpsLongitude === null) {
            $errors['gps'] = 'GPS coordinates (latitude & longitude) are required.';
        } elseif (!is_valid_gps_coordinate($gpsLatitude, $gpsLongitude)) {
            $errors['gps'] = 'GPS coordinates are out of valid range.';
        } elseif (!is_acceptable_gps_accuracy($gpsAccuracy)) {
            $errors['gps'] = 'GPS accuracy is too low for a reliable inspection record. Please retry with a stronger signal.';
        }

        // ---- Images (2-12 required, geo-tagged & timestamped) ------------------
        $imageCount = count($images);
        if ($imageCount < 2 || $imageCount > 12) {
            $errors['images'] = "Between 2 and 12 images are required (received {$imageCount}).";
        } else {
            foreach ($images as $index => $image) {
                if (!is_array($image)) {
                    $errors["images.{$index}"] = 'Each image must be an object with type/data_base64/latitude/longitude/captured_at.';
                    continue;
                }
                $imageType = strtoupper(trim((string) ($image['type'] ?? '')));
                if (!in_array($imageType, ['METER', 'SEAL', 'INSTALLATION', 'LOAD'], true)) {
                    $errors["images.{$index}.type"] = 'type must be one of: METER, SEAL, INSTALLATION, LOAD.';
                }
                if (trim((string) ($image['data_base64'] ?? '')) === '') {
                    $errors["images.{$index}.data_base64"] = 'Image data is required.';
                }
                if (!is_valid_gps_coordinate($image['latitude'] ?? null, $image['longitude'] ?? null)) {
                    $errors["images.{$index}.location"] = 'Each image must be geo-tagged with valid latitude/longitude.';
                }
                if (trim((string) ($image['captured_at'] ?? '')) === '' || strtotime((string) $image['captured_at']) === false) {
                    $errors["images.{$index}.captured_at"] = 'Each image must include a valid captured_at timestamp.';
                }
            }
        }

        // ---- Approval Workflow (3.10) — resolve required review chain ----------
        // Looked up now (read-only, no validation errors possible) so it's ready
        // to use once we get to the INSERT below; the chain depends on the
        // consumer's billing category, not on anything the client can override.
        $consumerCategoryStmt = $pdo->prepare('SELECT category FROM consumers WHERE reference_number = :ref LIMIT 1');
        $consumerCategoryStmt->execute(['ref' => $referenceNumber]);
        $consumerCategory = $consumerCategoryStmt->fetchColumn();
        $consumerCategory = $consumerCategory !== false ? (string) $consumerCategory : null;

        $requiredApprovalLevels = get_approval_required_levels($pdo, $consumerCategory);
        $initialApprovalLevel   = first_approval_level($requiredApprovalLevels);
        $initialOverallStatus   = $initialApprovalLevel === 0 ? 'APPROVED' : 'PENDING_APPROVAL';

        // ---- Task linkage (Task Assignment) -------------------------------------
        // BUGFIX: the mobile client's inspection form is reached via a
        // standalone "Auto-Fetch Data" reference-number lookup, not from the
        // task list, so it never actually sends task_id even when the visit
        // is fulfilling an assigned task — meaning the auto-completion below
        // silently never ran for real submissions. When task_id isn't given
        // explicitly, fall back to auto-detecting the caller's own open task
        // for this consumer, so the linked task + schedule still get marked
        // COMPLETED without requiring a frontend change first.
        $task = null;
        if ($taskId > 0) {
            $taskStmt = $pdo->prepare('SELECT id, schedule_id, status, assigned_to_user_id FROM task_assignments WHERE id = :id LIMIT 1');
            $taskStmt->execute(['id' => $taskId]);
            $task = $taskStmt->fetch();

            if ($task === false) {
                $errors['task_id'] = 'No task found with that task_id.';
            } elseif ((int) $task['assigned_to_user_id'] !== (int) $currentUser['id']) {
                $errors['task_id'] = 'This task is not assigned to you.';
            } elseif ($task['status'] === 'COMPLETED') {
                $errors['task_id'] = 'This task has already been completed.';
            } elseif ($task['status'] === 'CANCELLED') {
                $errors['task_id'] = 'This task was cancelled.';
            }
        } else {
            $autoTaskStmt = $pdo->prepare(
                'SELECT t.id, t.schedule_id, t.status, t.assigned_to_user_id
                 FROM task_assignments t
                 INNER JOIN consumers c ON c.id = t.consumer_id
                 WHERE c.reference_number = :ref
                   AND t.assigned_to_user_id = :user_id
                   AND t.status IN (\'PENDING\', \'IN_PROGRESS\')
                 ORDER BY t.created_at DESC
                 LIMIT 1'
            );
            $autoTaskStmt->execute([
                'ref'     => $referenceNumber,
                'user_id' => $currentUser['id'],
            ]);
            $autoTask = $autoTaskStmt->fetch();
            if ($autoTask !== false) {
                $task = $autoTask;
                $taskId = (int) $autoTask['id'];
            }
        }

        // ---- Duplicate entry prevention (no client_uuid supplied) --------------
        // Without an idempotency key, fall back to a natural-key check so the
        // same visit can't be recorded twice by an accidental double-submit.
        if (empty($errors) && $clientUuid === '') {
            $dupStmt = $pdo->prepare(
                'SELECT id FROM inspections
                 WHERE reference_number = :reference_number
                   AND consumer_account = :consumer_account
                   AND inspection_datetime = :inspection_datetime
                 LIMIT 1'
            );
            $dupStmt->execute([
                'reference_number'    => $referenceNumber,
                'consumer_account'    => $consumerAccount,
                'inspection_datetime' => date('Y-m-d H:i:s', strtotime($inspectionDateRaw)),
            ]);
            if ($dupStmt->fetchColumn() !== false) {
                json_error(
                    'An inspection for this reference number, consumer, and date/time already exists. Include a client_uuid to safely retry offline-queued submissions.',
                    409,
                    'DUPLICATE_INSPECTION'
                );
            }
        }

        if (!empty($errors)) {
            json_response([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $errors,
            ], 422);
        }

        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare(
                'INSERT INTO inspections
                    (client_uuid, reference_number, meter_id, consumer_account, inspection_datetime,
                     gps_latitude, gps_longitude, gps_accuracy_meters,
                     kwh, kvarh, mdi, load_details,
                     tou_peak, tou_off_peak, tou_day, tou_night,
                     seal_condition_code, ctpt_box_status_code, task_id,
                     overall_status, current_approval_level, submitted_by_user_id)
                 VALUES
                    (:client_uuid, :reference_number, :meter_id, :consumer_account, :inspection_datetime,
                     :gps_latitude, :gps_longitude, :gps_accuracy_meters,
                     :kwh, :kvarh, :mdi, :load_details,
                     :tou_peak, :tou_off_peak, :tou_day, :tou_night,
                     :seal_condition_code, :ctpt_box_status_code, :task_id,
                     :overall_status, :current_approval_level, :submitted_by_user_id)'
            );

            $insert->execute([
                'client_uuid'             => $clientUuid !== '' ? $clientUuid : null,
                'reference_number'        => $referenceNumber,
                'meter_id'                => $meterId,
                'consumer_account'        => $consumerAccount,
                'inspection_datetime'     => date('Y-m-d H:i:s', strtotime($inspectionDateRaw)),
                'gps_latitude'            => (float) $gpsLatitude,
                'gps_longitude'           => (float) $gpsLongitude,
                'gps_accuracy_meters'     => $gpsAccuracy !== null && $gpsAccuracy !== '' ? (float) $gpsAccuracy : null,
                'kwh'                     => (float) $kwh,
                'kvarh'                   => (float) $kvarh,
                'mdi'                     => (float) $mdi,
                'load_details'            => $loadDetails,
                'tou_peak'                => $touPeak    !== null && $touPeak    !== '' ? (float) $touPeak    : null,
                'tou_off_peak'            => $touOffPeak !== null && $touOffPeak !== '' ? (float) $touOffPeak : null,
                'tou_day'                 => $touDay     !== null && $touDay     !== '' ? (float) $touDay     : null,
                'tou_night'               => $touNight   !== null && $touNight   !== '' ? (float) $touNight   : null,
                'seal_condition_code'     => $sealConditionCode,
                'ctpt_box_status_code'    => $ctPtBoxStatusCode,
                'task_id'                 => $taskId > 0 ? $taskId : null,
                'overall_status'          => $initialOverallStatus,
                'current_approval_level'  => $initialApprovalLevel,
                'submitted_by_user_id'    => $currentUser['id'],
            ]);

            $inspectionId = (int) $pdo->lastInsertId();

            // ---- Store images -------------------------------------------------
            $imageInsert = $pdo->prepare(
                'INSERT INTO inspection_images (inspection_id, image_type, file_path, gps_latitude, gps_longitude, captured_at)
                 VALUES (:inspection_id, :image_type, :file_path, :gps_latitude, :gps_longitude, :captured_at)'
            );
            $imageUrls = [];
            foreach ($images as $image) {
                $relativePath = store_base64_image((string) $image['data_base64'], "inspections/{$inspectionId}");
                $imageInsert->execute([
                    'inspection_id' => $inspectionId,
                    'image_type'    => strtoupper(trim((string) $image['type'])),
                    'file_path'     => $relativePath,
                    'gps_latitude'  => (float) $image['latitude'],
                    'gps_longitude' => (float) $image['longitude'],
                    'captured_at'   => date('Y-m-d H:i:s', strtotime((string) $image['captured_at'])),
                ]);
                $imageUrls[] = build_upload_url($relativePath);
            }

            // ---- Auto-complete the linked task (and its schedule, if any) -----
            if ($task !== null) {
                $pdo->prepare(
                    'UPDATE task_assignments SET status = \'COMPLETED\', inspection_id = :inspection_id WHERE id = :id'
                )->execute(['inspection_id' => $inspectionId, 'id' => (int) $task['id']]);

                if ($task['schedule_id'] !== null) {
                    $pdo->prepare(
                        'UPDATE schedules SET status = \'COMPLETED\' WHERE id = :id'
                    )->execute(['id' => (int) $task['schedule_id']]);
                }
            }

            $pdo->commit();
        } catch (RuntimeException $e) {
            // Thrown by store_base64_image() on bad/oversized image data.
            $pdo->rollBack();
            json_error($e->getMessage(), 422, 'IMAGE_VALIDATION_ERROR');
        } catch (PDOException $e) {
            $pdo->rollBack();
            throw $e;
        }

        json_response([
            'success'                => true,
            'message'                => 'Inspection submitted successfully.',
            'id'                     => $inspectionId,
            'image_urls'             => $imageUrls,
            'overall_status'         => $initialOverallStatus,
            'current_approval_level' => $initialApprovalLevel,
        ], 201);
        break;

    // -------------------------------------------------------------------
    // GET ?action=inspections-list (default)
    // -------------------------------------------------------------------
    case 'inspections-list':
        if ($method !== 'GET') {
            json_error('This action only accepts GET requests.', 405, 'METHOD_NOT_ALLOWED');
        }

        // Backward-compat: the old "?limit=" param (no scoping/filters) maps
        // to per_page when the newer page/per_page params aren't supplied.
        if (isset($_GET['limit']) && !isset($_GET['per_page'])) {
            $_GET['per_page'] = $_GET['limit'];
        }
        [$limit, $offset] = get_pagination();

        $isSupervisor = in_array($currentUser['role_code'], SUPERVISORY_ROLES, true);

        $conditions = [];
        $params = [];

        if (!$isSupervisor) {
            // M&T — "cannot see reports for the whole division or circle",
            // so this always scopes to their own submissions, no override.
            $conditions[] = 'i.submitted_by_user_id = :submitted_by';
            $params['submitted_by'] = (int) $currentUser['id'];
        } else {
            $division = trim((string) ($_GET['division'] ?? ''));
            if ($division !== '') {
                $conditions[] = 'c.division = :division';
                $params['division'] = $division;
            }

            $subDivision = trim((string) ($_GET['sub_division'] ?? ''));
            if ($subDivision !== '') {
                $conditions[] = 'c.sub_division = :sub_division';
                $params['sub_division'] = $subDivision;
            }

            // Server-side enforcement — an SDO/XEN sees only their own
            // sub-division(s)/division regardless of the filters above
            // (which may only narrow further within that). SE/ADMIN
            // unrestricted (no circle column yet — see enforced_scope()).
            [$scopeSql, $scopeParams] = enforced_scope_sql($currentUser, 'c.division', 'c.sub_division');
            if ($scopeSql !== '') {
                $conditions[] = $scopeSql;
                $params = array_merge($params, $scopeParams);
            }
        }

        $category = strtoupper(trim((string) ($_GET['category'] ?? '')));
        if ($category !== '') {
            if (!in_array($category, VALID_CATEGORIES, true)) {
                json_error('category must be one of: ' . implode(', ', VALID_CATEGORIES) . '.', 422, 'VALIDATION_ERROR');
            }
            $conditions[] = 'c.category = :category';
            $params['category'] = $category;
        }

        // "status" here means the Approval Workflow outcome (3.10) — the
        // closest thing this table has to an inspection status.
        $status = strtoupper(trim((string) ($_GET['status'] ?? '')));
        if ($status !== '') {
            if (!in_array($status, ['PENDING_APPROVAL', 'APPROVED', 'REJECTED'], true)) {
                json_error('status must be one of: PENDING_APPROVAL, APPROVED, REJECTED.', 422, 'VALIDATION_ERROR');
            }
            $conditions[] = 'i.overall_status = :status';
            $params['status'] = $status;
        }

        // Search by meter number, reference number, or consumer name.
        $search = trim((string) ($_GET['search'] ?? ''));
        if ($search !== '') {
            $conditions[] = '(i.meter_id LIKE :search OR i.reference_number LIKE :search OR i.consumer_account LIKE :search OR c.consumer_name LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $dateFrom = trim((string) ($_GET['date_from'] ?? ''));
        if ($dateFrom !== '') {
            if (strtotime($dateFrom) === false) {
                json_error('date_from must be a valid date.', 422, 'VALIDATION_ERROR');
            }
            $conditions[] = 'i.inspection_datetime >= :date_from';
            $params['date_from'] = date('Y-m-d 00:00:00', strtotime($dateFrom));
        }

        $dateTo = trim((string) ($_GET['date_to'] ?? ''));
        if ($dateTo !== '') {
            if (strtotime($dateTo) === false) {
                json_error('date_to must be a valid date.', 422, 'VALIDATION_ERROR');
            }
            $conditions[] = 'i.inspection_datetime <= :date_to';
            $params['date_to'] = date('Y-m-d 23:59:59', strtotime($dateTo));
        }

        $whereSql = empty($conditions) ? '' : ('WHERE ' . implode(' AND ', $conditions));
        $joinSql  = 'FROM inspections i
                      INNER JOIN users u ON u.id = i.submitted_by_user_id
                      LEFT JOIN consumers c ON c.reference_number = i.reference_number';

        $stmt = $pdo->prepare(
            "SELECT i.id, i.reference_number, i.meter_id, i.consumer_account, i.inspection_datetime,
                    i.gps_latitude, i.gps_longitude, i.gps_accuracy_meters,
                    i.kwh, i.kvarh, i.mdi, i.load_details,
                    i.tou_peak, i.tou_off_peak, i.tou_day, i.tou_night,
                    i.seal_condition_code, i.ctpt_box_status_code, i.task_id,
                    i.overall_status, i.current_approval_level,
                    c.consumer_name, c.division, c.sub_division, c.category,
                    (SELECT COUNT(*) FROM inspection_images img WHERE img.inspection_id = i.id) AS image_count,
                    u.full_name AS submitted_by, i.created_at
             {$joinSql}
             {$whereSql}
             ORDER BY i.created_at DESC
             LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $countStmt = $pdo->prepare("SELECT COUNT(*) {$joinSql} {$whereSql}");
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $countStmt->execute();

        json_response([
            'success' => true,
            'data'    => array_map(
                static fn (array $row): array => cast_decimal_fields($row, INSPECTION_DECIMAL_FIELDS),
                $stmt->fetchAll()
            ),
            'total'   => (int) $countStmt->fetchColumn(),
        ]);
        break;

    // -------------------------------------------------------------------
    // GET ?action=inspection-detail&id=42
    // Full single-record view: every reading, GPS, and the uploaded image
    // list (spec: "Open inspection details... View uploaded images... View
    // GPS location"). Scoped the same way as inspections-list.
    // -------------------------------------------------------------------
    case 'inspection-detail':
        if ($method !== 'GET') {
            json_error('This action only accepts GET requests.', 405, 'METHOD_NOT_ALLOWED');
        }

        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            json_error('Query parameter "id" is required.', 422, 'VALIDATION_ERROR');
        }

        $stmt = $pdo->prepare(
            'SELECT i.*, c.consumer_name, c.consumer_address, c.division, c.sub_division, c.category,
                    c.tariff_category, c.sanctioned_load,
                    u.full_name AS submitted_by
             FROM inspections i
             LEFT JOIN consumers c ON c.reference_number = i.reference_number
             INNER JOIN users u    ON u.id = i.submitted_by_user_id
             WHERE i.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $inspection = $stmt->fetch();

        if ($inspection === false) {
            json_error('Inspection not found.', 404, 'INSPECTION_NOT_FOUND');
        }

        $isSupervisor = in_array($currentUser['role_code'], SUPERVISORY_ROLES, true);
        $isOwner = (int) $inspection['submitted_by_user_id'] === (int) $currentUser['id'];

        if (!$isSupervisor && !$isOwner) {
            json_error('You do not have permission to view this inspection.', 403, 'FORBIDDEN_ROLE');
        }
        if ($isSupervisor && !$isOwner && !is_within_enforced_scope($currentUser, $inspection)) {
            json_error('You do not have permission to view this inspection.', 403, 'FORBIDDEN_ROLE');
        }

        $inspection = cast_decimal_fields($inspection, INSPECTION_DECIMAL_FIELDS);

        $imagesStmt = $pdo->prepare(
            'SELECT image_type, file_path, gps_latitude, gps_longitude, captured_at
             FROM inspection_images WHERE inspection_id = :id ORDER BY id ASC'
        );
        $imagesStmt->execute(['id' => $id]);
        $images = array_map(static function (array $img): array {
            $img = cast_decimal_fields($img, INSPECTION_IMAGE_DECIMAL_FIELDS);
            $img['image_url'] = build_upload_url($img['file_path']);
            unset($img['file_path']);
            return $img;
        }, $imagesStmt->fetchAll());

        $inspection['images'] = $images;

        // Surface the reviewer's remarks for a REJECTED inspection to
        // whoever is allowed to view this record at all (owner or scoped
        // supervisor, already checked above) — approvals.php's decision
        // history is supervisor-only, so without this the M&T who submitted
        // a rejected report would never learn why.
        $inspection['rejection'] = null;
        if ($inspection['overall_status'] === 'REJECTED') {
            $rejectionStmt = $pdo->prepare(
                'SELECT a.role_code, a.remarks, a.created_at, approver.full_name AS approver_name
                 FROM inspection_approvals a
                 INNER JOIN users approver ON approver.id = a.approver_user_id
                 WHERE a.inspection_id = :id AND a.action = \'REJECTED\'
                 ORDER BY a.created_at DESC LIMIT 1'
            );
            $rejectionStmt->execute(['id' => $id]);
            $rejection = $rejectionStmt->fetch();
            if ($rejection !== false) {
                $inspection['rejection'] = $rejection;
            }
        }

        json_response(['success' => true, 'data' => $inspection]);
        break;

    default:
        json_error(
            "Unknown action '{$action}'. Expected one of: form-options, consumer-fetch, inspection-submit, inspections-list, inspection-detail.",
            400,
            'UNKNOWN_ACTION'
        );
}
