<?php
/**
 * =============================================================================
 * FILE: config/helpers.php
 * PURPOSE: Small, shared, framework-free helper functions used across every
 *          endpoint in api/. Kept procedural/functional on purpose — no
 *          classes, no autoloading, no framework magic.
 * =============================================================================
 */

declare(strict_types=1);

/**
 * Sends the standard security + CORS headers every JSON endpoint needs,
 * then handles CORS pre-flight (OPTIONS) requests by exiting early.
 *
 * @return void
 */
function send_common_headers(): void
{
    $allowedOrigins = env_get('APP_CORS_ALLOWED_ORIGINS', '*');

    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: ' . $allowedOrigins);
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('X-Content-Type-Options: nosniff');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

/**
 * Emits a JSON response with the given HTTP status code and terminates
 * the script. Every endpoint should funnel its output through this so the
 * response shape is always consistent.
 *
 * @param array<string, mixed> $payload
 * @param int $statusCode
 * @return never
 */
function json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Shortcut for a standard {success:false, message:...} error response.
 *
 * @param string $message
 * @param int $statusCode
 * @param string|null $errorCode Machine-readable error code for client-side branching.
 * @return never
 */
function json_error(string $message, int $statusCode = 400, ?string $errorCode = null): void
{
    json_response([
        'success' => false,
        'message' => $message,
        'error_code' => $errorCode,
    ], $statusCode);
}

/**
 * Reads and JSON-decodes the raw request body into an associative array.
 * Returns an empty array (never null) if the body is missing or invalid,
 * so callers can safely use ?? defaults without extra null checks.
 *
 * @return array<string, mixed>
 */
function get_json_body(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * Extracts the bearer token from the Authorization header, if present.
 *
 * @return string|null
 */
function get_bearer_token(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if ($header === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $header  = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }

    if (preg_match('/Bearer\s+(\S+)/i', $header, $matches) === 1) {
        return $matches[1];
    }

    return null;
}

/**
 * Validates the bearer token from the current request against auth_tokens,
 * checking both existence and expiry. On success returns the joined
 * user + token row. On failure, sends a 401 JSON response and exits.
 *
 * @param PDO $pdo
 * @return array<string, mixed> The authenticated user's row.
 */
function require_authenticated_user(PDO $pdo): array
{
    $token = get_bearer_token();

    if ($token === null || $token === '') {
        json_error('Missing or malformed Authorization header. Expected: Bearer <token>.', 401, 'AUTH_MISSING_TOKEN');
    }

    $stmt = $pdo->prepare(
        'SELECT u.*, t.expires_at AS token_expires_at
         FROM auth_tokens t
         INNER JOIN users u ON u.id = t.user_id
         WHERE t.token = :token
         LIMIT 1'
    );
    $stmt->execute(['token' => $token]);
    $row = $stmt->fetch();

    if ($row === false) {
        json_error('Invalid or unrecognized token. Please log in again.', 401, 'AUTH_INVALID_TOKEN');
    }

    if (strtotime((string) $row['token_expires_at']) < time()) {
        json_error('Session expired. Please log in again.', 401, 'AUTH_TOKEN_EXPIRED');
    }

    if ((int) $row['is_active'] === 0) {
        json_error('This account has been deactivated. Contact your administrator.', 403, 'AUTH_ACCOUNT_DISABLED');
    }

    return $row;
}

/**
 * Generates a cryptographically secure random token string.
 *
 * @param int $bytes
 * @return string
 */
function generate_secure_token(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

/**
 * Generates a 6-digit numeric OTP code as a zero-padded string.
 *
 * @return string
 */
function generate_otp_code(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * Masks a raw phone number for display, matching the frontend's expected
 * "contact_masked" shape, e.g. "03001234892" -> "03**-***-4892".
 *
 * @param string|null $rawNumber
 * @return string|null
 */
function mask_contact_number(?string $rawNumber): ?string
{
    if ($rawNumber === null || strlen($rawNumber) < 8) {
        return null;
    }

    $prefix = substr($rawNumber, 0, 2);
    $suffix = substr($rawNumber, -4);

    return "{$prefix}**-***-{$suffix}";
}

/**
 * Builds the exact UserModel-shaped JSON payload expected by the Flutter
 * app's UserModel.fromJson() (see lib/core/models/user_model.dart).
 *
 * @param array<string, mixed> $userRow
 * @param string $token
 * @return array<string, mixed>
 */
function build_user_payload(array $userRow, string $token): array
{
    return [
        'employee_id'    => $userRow['employee_id'],
        'full_name'      => $userRow['full_name'],
        'username'       => $userRow['username'],
        'role_code'      => $userRow['role_code'],
        'scope_code'     => $userRow['scope_code'],
        'scope_name'     => $userRow['scope_name'],
        'token'          => $token,
        'is_first_login' => (bool) $userRow['is_first_login'],
        'contact_masked' => mask_contact_number($userRow['contact_number'] ?? null),
    ];
}

/**
 * Same shape as build_user_payload() but without a token -- used for
 * read-only profile responses (api/me.php) where we don't want to imply
 * a new session was issued.
 *
 * @param array<string, mixed> $userRow
 * @return array<string, mixed>
 */
function build_user_profile(array $userRow): array
{
    return [
        'employee_id'    => $userRow['employee_id'],
        'full_name'      => $userRow['full_name'],
        'username'       => $userRow['username'],
        'role_code'      => $userRow['role_code'],
        'scope_code'     => $userRow['scope_code'],
        'scope_name'     => $userRow['scope_name'],
        'is_first_login' => (bool) $userRow['is_first_login'],
        'contact_masked' => mask_contact_number($userRow['contact_number'] ?? null),
    ];
}

/**
 * Guards a route to a specific set of roles (e.g. ADMIN-only management
 * endpoints). Sends a 403 JSON response and exits if the authenticated
 * user's role is not in the allow-list.
 *
 * @param array<string, mixed> $userRow The row returned by require_authenticated_user().
 * @param string[] $allowedRoles e.g. ['ADMIN']
 * @return void
 */
function require_role(array $userRow, array $allowedRoles): void
{
    if (!in_array($userRow['role_code'], $allowedRoles, true)) {
        json_error(
            'You do not have permission to perform this action.',
            403,
            'FORBIDDEN_ROLE'
        );
    }
}

/**
 * Revokes (deletes) a single bearer token -- used by api/logout.php.
 *
 * @param PDO $pdo
 * @param string $token
 * @return void
 */
function revoke_token(PDO $pdo, string $token): void
{
    $stmt = $pdo->prepare('DELETE FROM auth_tokens WHERE token = :token');
    $stmt->execute(['token' => $token]);
}

/**
 * Reads pagination-style query params ("page" and "per_page") and returns
 * a [limit, offset] pair, clamped to sane bounds.
 *
 * @return array{0:int,1:int} [limit, offset]
 */
function get_pagination(): array
{
    $page    = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = max(1, min(100, (int) ($_GET['per_page'] ?? 20)));

    return [$perPage, ($page - 1) * $perPage];
}

// =============================================================================
// SCHEDULING HELPERS (Meter Scheduling System)
// =============================================================================

/**
 * Returns the quarter string (e.g. "2026-Q3") for a given date, or today
 * if no date is supplied.
 *
 * @param string|null $date Any strtotime()-parseable date.
 * @return string
 */
function quarter_string(?string $date = null): string
{
    $timestamp = $date !== null ? strtotime($date) : time();
    $timestamp = $timestamp !== false ? $timestamp : time();

    $year    = (int) date('Y', $timestamp);
    $quarter = (int) ceil(((int) date('n', $timestamp)) / 3);

    return "{$year}-Q{$quarter}";
}

/**
 * Parses a "YYYY-Qn" string into [year, quarter].
 *
 * @param string $quarter
 * @return array{0:int,1:int}|null Null if malformed.
 */
function parse_quarter_string(string $quarter): ?array
{
    if (preg_match('/^(\d{4})-Q([1-4])$/', $quarter, $matches) !== 1) {
        return null;
    }

    return [(int) $matches[1], (int) $matches[2]];
}

/**
 * Returns the [startDate, endDate] (inclusive, Y-m-d) covered by a
 * "YYYY-Qn" quarter string.
 *
 * @param string $quarter
 * @return array{0:string,1:string}|null Null if malformed.
 */
function quarter_date_range(string $quarter): ?array
{
    $parsed = parse_quarter_string($quarter);
    if ($parsed === null) {
        return null;
    }

    [$year, $q] = $parsed;
    $startMonth = (($q - 1) * 3) + 1;

    $start = sprintf('%04d-%02d-01', $year, $startMonth);
    $endTimestamp = strtotime($start . ' +3 months -1 day');

    return [$start, date('Y-m-d', $endTimestamp)];
}

/**
 * Spreads N schedule entries evenly across a quarter's date range so an
 * auto-generated batch doesn't dump every visit on day one. Deterministic
 * given the same quarter + index, so re-running generation is predictable.
 *
 * @param string $quarter
 * @param int $index Zero-based position of this entry within the batch.
 * @param int $total Total entries in the batch (>= 1).
 * @return string Y-m-d scheduled date.
 */
function distribute_schedule_date(string $quarter, int $index, int $total): string
{
    $range = quarter_date_range($quarter);
    if ($range === null) {
        return date('Y-m-d');
    }

    [$start, $end] = $range;
    $startTs = strtotime($start);
    $endTs   = strtotime($end);
    $spanDays = max(1, (int) (($endTs - $startTs) / 86400));

    $total = max(1, $total);
    $offsetDays = (int) floor(($index / $total) * $spanDays);

    return date('Y-m-d', $startTs + ($offsetDays * 86400));
}

// =============================================================================
// GPS / LOCATION VALIDATION (Data Validation — GPS accuracy check)
// =============================================================================

/**
 * Validates a lat/lng pair is within real-world bounds.
 *
 * @param mixed $latitude
 * @param mixed $longitude
 * @return bool
 */
function is_valid_gps_coordinate($latitude, $longitude): bool
{
    if (!is_numeric($latitude) || !is_numeric($longitude)) {
        return false;
    }

    $lat = (float) $latitude;
    $lng = (float) $longitude;

    return $lat >= -90.0 && $lat <= 90.0 && $lng >= -180.0 && $lng <= 180.0;
}

/**
 * Checks a device-reported GPS accuracy (in meters) against the
 * configurable maximum. Smaller = more accurate; null accuracy passes
 * (older devices/emulators sometimes omit it) but a present-and-too-large
 * value fails, per the "GPS accuracy check" validation requirement.
 *
 * @param mixed $accuracyMeters
 * @return bool
 */
function is_acceptable_gps_accuracy($accuracyMeters): bool
{
    if ($accuracyMeters === null || $accuracyMeters === '') {
        return true;
    }

    if (!is_numeric($accuracyMeters)) {
        return false;
    }

    $maxAccuracy = (float) env_get('GPS_MAX_ACCURACY_METERS', '150');

    return (float) $accuracyMeters <= $maxAccuracy;
}

// =============================================================================
// IMAGE STORAGE (Image Capture)
// =============================================================================

/** Allowed image MIME types for uploaded evidence/inspection photos. */
const ALLOWED_IMAGE_MIME_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

/** Maximum accepted size, per image, in bytes (8 MB). */
const MAX_IMAGE_BYTES = 8 * 1024 * 1024;

/**
 * Decodes a base64 (optionally data-URI-prefixed) image, validates its
 * type/size, and writes it to disk under uploads/{$subdir}/, returning the
 * relative path to store in the database (e.g. "inspections/42/ab12cd.jpg").
 *
 * Throws a RuntimeException with a client-safe message on any validation
 * failure — callers should catch it and turn it into a 422 json_error().
 *
 * PERSISTENCE (READ BEFORE DEPLOYING): this writes to local disk, which is
 * ephemeral on Railway (and most container platforms) unless a Volume is
 * explicitly attached to this service with Mount Path = /app/uploads —
 * see nixpacks.toml for the full explanation and setup steps. Without that
 * volume, every file written here is silently lost on the next
 * deploy/restart even though the corresponding DB row (inspection_images /
 * discrepancies.photo_evidence_url) still points at it. A Volume is also
 * only visible to a single running instance — if this service is ever
 * scaled to multiple replicas, move this to S3-compatible object storage
 * instead of local disk.
 *
 * @param string $base64Data Raw base64 or "data:image/jpeg;base64,...." string.
 * @param string $subdir Relative subdirectory under uploads/, e.g. "inspections/42".
 * @return string Relative file path (under uploads/) that was written.
 */
function store_base64_image(string $base64Data, string $subdir): string
{
    // Strip a data URI prefix if present, e.g. "data:image/jpeg;base64,...."
    if (preg_match('/^data:(image\/[a-zA-Z0-9.+-]+);base64,(.+)$/', $base64Data, $matches) === 1) {
        $mimeType = strtolower($matches[1]);
        $raw      = $matches[2];
    } else {
        $mimeType = null;
        $raw      = $base64Data;
    }

    $decoded = base64_decode($raw, true);
    if ($decoded === false || $decoded === '') {
        throw new RuntimeException('One or more images could not be decoded. Please retake and try again.');
    }

    if (strlen($decoded) > MAX_IMAGE_BYTES) {
        throw new RuntimeException('One or more images exceed the 8 MB size limit.');
    }

    // Detect the real MIME type from the decoded bytes rather than trusting
    // the client-supplied data URI header, using PHP's fileinfo extension.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedMime = $finfo->buffer($decoded) ?: $mimeType;

    if ($detectedMime === null || !array_key_exists($detectedMime, ALLOWED_IMAGE_MIME_TYPES)) {
        throw new RuntimeException('Images must be JPEG, PNG, or WEBP.');
    }

    $extension = ALLOWED_IMAGE_MIME_TYPES[$detectedMime];
    $relativeDir = 'uploads/' . trim($subdir, '/');
    $absoluteDir = __DIR__ . '/../' . $relativeDir;

    if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
        throw new RuntimeException('Could not save image (server storage error).');
    }

    $filename = bin2hex(random_bytes(12)) . '.' . $extension;
    $absolutePath = $absoluteDir . '/' . $filename;

    if (file_put_contents($absolutePath, $decoded) === false) {
        throw new RuntimeException('Could not save image (server storage error).');
    }

    return trim($subdir, '/') . '/' . $filename;
}

/**
 * Builds a publicly reachable absolute URL for a stored upload path
 * (as returned by store_base64_image()), e.g.
 * "https://api.example.com/uploads/inspections/42/ab12cd.jpg".
 *
 * @param string|null $relativePath Path returned by store_base64_image(), or null.
 * @return string|null
 */
function build_upload_url(?string $relativePath): ?string
{
    if ($relativePath === null || $relativePath === '') {
        return null;
    }

    $base = env_get('APP_BASE_URL');

    if ($base === null) {
        $scheme = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base   = "{$scheme}://{$host}";
    }

    return rtrim($base, '/') . '/uploads/' . ltrim($relativePath, '/');
}

/**
 * PDO/mysqlnd always returns MySQL DECIMAL columns as PHP strings — even
 * with native prepared statements (PDO::ATTR_EMULATE_PREPARES => false) —
 * because there's no lossless native PHP float type guaranteed to match
 * arbitrary DECIMAL precision. Left uncast, json_encode() then emits them
 * as quoted JSON strings (e.g. "kwh": "123.45"), silently breaking any
 * strongly-typed client (e.g. Flutter's `json['kwh'] as num?`, which throws
 * "type 'String' is not a subtype of type 'num?'" — exactly the DECIMAL
 * columns below: readings, TOU breakdown, and GPS coordinates).
 *
 * Call this on every row fetched from a table with DECIMAL columns before
 * passing it to json_response()/json_encode(), so the API always returns
 * genuine JSON numbers for these fields, never numeric strings.
 *
 * @param array<string, mixed> $row One fetched row (assoc array).
 * @param string[] $decimalFields Keys in $row that are DECIMAL columns.
 * @return array<string, mixed> $row with those keys cast to float (nulls left as null).
 */
function cast_decimal_fields(array $row, array $decimalFields): array
{
    foreach ($decimalFields as $field) {
        if (array_key_exists($field, $row) && $row[$field] !== null) {
            $row[$field] = (float) $row[$field];
        }
    }
    return $row;
}

/** DECIMAL columns on `inspections` — see cast_decimal_fields(). */
const INSPECTION_DECIMAL_FIELDS = [
    'gps_latitude', 'gps_longitude', 'gps_accuracy_meters',
    'kwh', 'kvarh', 'mdi',
    'tou_peak', 'tou_off_peak', 'tou_day', 'tou_night',
];

/** DECIMAL columns on `inspection_images` — see cast_decimal_fields(). */
const INSPECTION_IMAGE_DECIMAL_FIELDS = ['gps_latitude', 'gps_longitude'];

// =============================================================================
// ROLE GROUPS
// =============================================================================

/** Roles considered "supervisory" — can manage schedules, assign tasks, resolve discrepancies. */
const SUPERVISORY_ROLES = ['SDO', 'XEN', 'SE', 'ADMIN'];

/** Valid consumer billing categories, used by consumers/schedules validation. */
const VALID_CATEGORIES = ['B1', 'B2', 'B3', 'B4'];

// =============================================================================
// APPROVAL WORKFLOW (spec 3.10 — Level 1 M&T submit, then SDO -> XEN -> SE,
// with the exact chain of required levels configurable per meter category
// via the approval_workflow_rules table, since "workflow depends on meter
// category").
// =============================================================================

/** Maps an approval level number to the role_code that must decide it. */
const APPROVAL_LEVEL_ROLES = [1 => 'SDO', 2 => 'XEN', 3 => 'SE'];

/**
 * Looks up which approval levels (1 = SDO, 2 = XEN, 3 = SE — see
 * APPROVAL_LEVEL_ROLES) a given meter category must pass through, per the
 * admin-configurable approval_workflow_rules table. Falls back to requiring
 * all three levels if the category is unknown/null or has no rule row, so a
 * missing config row never silently skips review.
 *
 * @param PDO $pdo
 * @param string|null $category One of VALID_CATEGORIES, or null/unknown.
 * @return int[] Ascending list of required levels, e.g. [1,2,3]. Empty array
 *               means the category is configured to skip approval entirely
 *               (auto-approved on submission).
 */
function get_approval_required_levels(PDO $pdo, ?string $category): array
{
    if ($category !== null && in_array($category, VALID_CATEGORIES, true)) {
        $stmt = $pdo->prepare(
            'SELECT requires_sdo, requires_xen, requires_se FROM approval_workflow_rules WHERE category = :category LIMIT 1'
        );
        $stmt->execute(['category' => $category]);
        $rule = $stmt->fetch();

        if ($rule !== false) {
            $levels = [];
            if ((int) $rule['requires_sdo'] === 1) $levels[] = 1;
            if ((int) $rule['requires_xen'] === 1) $levels[] = 2;
            if ((int) $rule['requires_se'] === 1) $levels[] = 3;
            return $levels;
        }
    }

    // No category / no rule row on file — default to the full chain.
    return [1, 2, 3];
}

/**
 * The level an inspection should start at once submitted.
 *
 * @param int[] $requiredLevels As returned by get_approval_required_levels().
 * @return int The first required level, or 0 if the category requires no
 *             approval at all (i.e. the inspection is auto-approved).
 */
function first_approval_level(array $requiredLevels): int
{
    sort($requiredLevels);
    return $requiredLevels[0] ?? 0;
}

/**
 * The next level after $currentLevel is approved.
 *
 * @param int[] $requiredLevels As returned by get_approval_required_levels().
 * @param int $currentLevel The level that was just approved.
 * @return int The next required level, or 0 if $currentLevel was the last
 *             one (i.e. the inspection is now fully approved).
 */
function next_approval_level(array $requiredLevels, int $currentLevel): int
{
    sort($requiredLevels);
    foreach ($requiredLevels as $level) {
        if ($level > $currentLevel) {
            return $level;
        }
    }
    return 0;
}

/**
 * The role_code required to decide a given approval level.
 *
 * @param int $level
 * @return string|null Null for level 0 (no approval pending / already final).
 */
function approval_role_for_level(int $level): ?string
{
    return APPROVAL_LEVEL_ROLES[$level] ?? null;
}

// =============================================================================
// GEOGRAPHIC SCOPE ENFORCEMENT
// Backs the access-control requirement that appears throughout the SDO/XEN/SE
// spec: "the SDO can access only their assigned sub-division... cannot access
// other SDOs' sub-divisions, other divisions, or other circles." This is
// SERVER-SIDE enforcement — every listing endpoint below applies it to the
// SQL WHERE clause itself, so a client can't bypass it by simply omitting or
// forging a division/sub_division query parameter. Client-supplied
// division/sub_division filters are still honored on TOP of this (to narrow
// further), never in place of it.
//
// users.scope_code is one of SUB_DIVISION/DIVISION/CIRCLE/REGION/NATIONAL and
// users.scope_name is free text set by the Admin. An SDO may supervise more
// than one sub-division ("the SDO supervises one or more sub-divisions"), so
// scope_name may list several, delimited by comma/semicolon/slash/"and".
//
// CIRCLE-level enforcement for SE isn't possible yet: consumers/schedules
// only carry `division`/`sub_division` columns, no `circle` column. SE (and
// ADMIN, and any role above SUB_DIVISION/DIVISION) therefore remain
// unrestricted here — a known gap to close by adding a circle column and a
// division->circle mapping, not something this function can paper over.
// =============================================================================

/**
 * Resolves the enforced division / sub-division(s) for the current user.
 *
 * @param array<string, mixed> $currentUser The row from require_authenticated_user().
 * @return array{0: string|null, 1: string[]} [division, subDivisions]. At
 *         most one of these is non-empty. Both empty/null means unrestricted
 *         (XEN gets division, SE/ADMIN/REGION/NATIONAL get neither).
 */
function enforced_scope(array $currentUser): array
{
    $scopeCode = (string) ($currentUser['scope_code'] ?? '');
    $scopeName = (string) ($currentUser['scope_name'] ?? '');

    if ($scopeCode === 'SUB_DIVISION' && $scopeName !== '') {
        $parts = preg_split('/\s*(,|;|\/|\band\b)\s*/i', $scopeName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $subDivisions = array_values(array_filter(array_map('trim', $parts), fn (string $s): bool => $s !== ''));
        return [null, $subDivisions];
    }

    if ($scopeCode === 'DIVISION' && $scopeName !== '') {
        return [$scopeName, []];
    }

    return [null, []];
}

/**
 * Builds the SQL fragment + bound params that enforce the current user's
 * geographic scope against the given columns, for use in a WHERE clause.
 * Returns an empty fragment for unrestricted roles.
 *
 * Bind params use a `__scope_` prefix to avoid colliding with any
 * caller-supplied filter params sharing the same base name (e.g. a
 * client-supplied `:division` filter can coexist with the enforced one).
 *
 * @param array<string, mixed> $currentUser
 * @param string $divisionCol e.g. "c.division"
 * @param string $subDivisionCol e.g. "c.sub_division"
 * @return array{0: string, 1: array<string, string>} [sqlFragment, params].
 *         sqlFragment is '' when unrestricted (no AND needed).
 */
function enforced_scope_sql(array $currentUser, string $divisionCol, string $subDivisionCol): array
{
    [$division, $subDivisions] = enforced_scope($currentUser);

    if ($division !== null) {
        return ["{$divisionCol} = :__scope_division", ['__scope_division' => $division]];
    }

    if (!empty($subDivisions)) {
        $placeholders = [];
        $params = [];
        foreach ($subDivisions as $i => $sd) {
            $key = "__scope_sd_{$i}";
            $placeholders[] = ":{$key}";
            $params[$key] = $sd;
        }
        return ["{$subDivisionCol} IN (" . implode(', ', $placeholders) . ')', $params];
    }

    return ['', []];
}

/**
 * True if $row (an assoc array containing division/sub_division keys, e.g. a
 * single fetched consumer/task/schedule row) falls within the current user's
 * enforced scope. Use this to guard single-record GET/PUT/DELETE lookups by
 * id — enforced_scope_sql() alone only protects list queries.
 *
 * @param array<string, mixed> $currentUser
 * @param array<string, mixed> $row Must contain 'division' and 'sub_division' keys.
 */
function is_within_enforced_scope(array $currentUser, array $row): bool
{
    [$division, $subDivisions] = enforced_scope($currentUser);

    if ($division !== null) {
        return ($row['division'] ?? null) === $division;
    }

    if (!empty($subDivisions)) {
        return in_array($row['sub_division'] ?? null, $subDivisions, true);
    }

    return true; // unrestricted role
}

// =============================================================================
// AUTOMATIC TASK REASSIGNMENT (SRS Schedule section: "Automatically
// reassigned inspections")
//
// DESIGN NOTE — same reasoning as escalation in api/alerts.php: no
// cron/worker exists in this deployment, so this runs opportunistically —
// called from the top of any supervisory-facing list endpoint (tasks.php,
// dashboard.php) rather than on a schedule. It's guarded by
// auto_reassigned_at IS NULL so a task is only ever auto-reassigned once —
// it will never bounce repeatedly between workers, and a task a supervisor
// has since manually reassigned (which doesn't touch auto_reassigned_at)
// is still eligible again if it goes stale a second time... actually no:
// once auto_reassigned_at is set it stays set forever, by design — a task
// only gets ONE free automatic reassignment; a second miss is a people
// problem for a supervisor to handle manually, not something to keep
// automating.
//
// THRESHOLD (assumption — the SRS specifies only the separate "30 days ->
// SDO alert" rule, not a reassignment cadence): a task still open 15+ days
// past its due date (or its linked schedule's date, if no due_date was
// set) gets automatically reassigned once, to the least-loaded active M&T
// worker in the same sub-division. Chosen to fire before the 30-day SDO
// alert, so a stale task gets one automatic shot at recovery before a
// human needs to be alerted. Confirm with the client if a different
// cadence is wanted.
// =============================================================================
const AUTO_REASSIGN_THRESHOLD_DAYS = 15;

function run_auto_reassignment_sweep(PDO $pdo): void
{
    $staleStmt = $pdo->prepare(
        "SELECT t.id, t.assigned_to_user_id, c.sub_division
         FROM task_assignments t
         INNER JOIN consumers c ON c.id = t.consumer_id
         LEFT JOIN schedules s ON s.id = t.schedule_id
         WHERE t.status IN ('PENDING', 'IN_PROGRESS')
           AND t.auto_reassigned_at IS NULL
           AND COALESCE(t.due_date, s.scheduled_date) IS NOT NULL
           AND COALESCE(t.due_date, s.scheduled_date) < DATE_SUB(CURDATE(), INTERVAL :threshold DAY)"
    );
    $staleStmt->bindValue('threshold', AUTO_REASSIGN_THRESHOLD_DAYS, PDO::PARAM_INT);
    $staleStmt->execute();
    $staleTasks = $staleStmt->fetchAll();

    if (empty($staleTasks)) {
        return;
    }

    $note = sprintf(
        '[Auto-reassigned - previous assignee did not complete within %d days.]',
        AUTO_REASSIGN_THRESHOLD_DAYS
    );

    $reassignStmt = $pdo->prepare(
        "UPDATE task_assignments
         SET assigned_to_user_id = :new_assignee,
             auto_reassigned_at = NOW(),
             auto_reassigned_from_user_id = :old_assignee,
             notes = TRIM(CONCAT(COALESCE(notes, ''), ' ', :note))
         WHERE id = :id"
    );

    foreach ($staleTasks as $task) {
        // Least-loaded active M&T worker in the same sub-division,
        // excluding the current assignee. If none exists, leave the task
        // as-is — there's no one else to hand it to.
        $candidateStmt = $pdo->prepare(
            "SELECT u.id
             FROM users u
             LEFT JOIN task_assignments open_t
                ON open_t.assigned_to_user_id = u.id AND open_t.status IN ('PENDING', 'IN_PROGRESS')
             WHERE u.role_code = 'MT'
               AND u.is_active = 1
               AND u.scope_name = :sub_division
               AND u.id != :current_assignee
             GROUP BY u.id
             ORDER BY COUNT(open_t.id) ASC
             LIMIT 1"
        );
        $candidateStmt->execute([
            'sub_division'     => $task['sub_division'],
            'current_assignee' => $task['assigned_to_user_id'],
        ]);
        $candidate = $candidateStmt->fetchColumn();

        if ($candidate === false) {
            continue;
        }

        $reassignStmt->execute([
            'new_assignee' => (int) $candidate,
            'old_assignee' => (int) $task['assigned_to_user_id'],
            'note'         => $note,
            'id'           => (int) $task['id'],
        ]);
    }
}
