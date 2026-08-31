<?php
/**
 * =============================================================================
 * FILE: config/database.php
 * PURPOSE: Central, secure PDO/MySQL connection factory.
 *
 * USAGE:
 *   require_once __DIR__ . '/../config/database.php';
 *   $pdo = get_db_connection();
 *
 * All credentials are pulled from environment variables ($_ENV / getenv()),
 * never hardcoded, so the exact same code works locally (.env) and on
 * Railway (service Variables tab).
 * =============================================================================
 */

declare(strict_types=1);

/**
 * Reads an environment variable, checking $_ENV, $_SERVER, and getenv()
 * in turn (different SAPIs / process managers populate different arrays).
 *
 * @param string $key
 * @param string|null $default
 * @return string|null
 */
function env_get(string $key, ?string $default = null): ?string
{
    if (array_key_exists($key, $_ENV) && $_ENV[$key] !== '') {
        return (string) $_ENV[$key];
    }

    if (array_key_exists($key, $_SERVER) && $_SERVER[$key] !== '') {
        return (string) $_SERVER[$key];
    }

    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return $value;
    }

    return $default;
}

/**
 * Loads a simple KEY=VALUE .env file into $_ENV if one exists and the
 * variables are not already set by the hosting platform. This lets the
 * exact same codebase run locally without any extra Composer package.
 * Railway/production should set real environment variables instead, in
 * which case this function is a harmless no-op.
 *
 * @return void
 */
function load_dotenv_if_present(): void
{
    $envPath = __DIR__ . '/../.env';

    if (!is_readable($envPath)) {
        return;
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (!str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);
        $value = trim($value, "\"'");

        if ($key === '') {
            continue;
        }

        // Do not override variables the platform already provided.
        if (array_key_exists($key, $_ENV) || getenv($key) !== false) {
            continue;
        }

        $_ENV[$key] = $value;
        putenv("$key=$value");
    }
}

/**
 * Creates (or returns a cached) PDO connection using credentials from the
 * environment. Throws a PDOException on failure — callers in the API
 * layer are responsible for catching it and returning a clean JSON error.
 *
 * @return PDO
 */
function get_db_connection(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    load_dotenv_if_present();

    $host     = env_get('MYSQLHOST');
    $port     = env_get('MYSQLPORT');
    $database = env_get('MYSQLDATABASE');
    $user     = env_get('MYSQLUSER');
    $password = env_get('MYSQLPASSWORD');

    $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
    ];

    $pdo = new PDO($dsn, $user, $password, $options);

    return $pdo;
}
