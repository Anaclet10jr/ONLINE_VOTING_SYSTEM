<?php
/**
 * config/database.php
 * Secure MySQLi connection factory.
 *
 * Credentials are read from environment variables first (production),
 * falling back to XAMPP defaults for local development only.
 *
 * NEVER commit real production credentials. In production, set these
 * via Apache SetEnv or a .env file OUTSIDE the web root.
 */

declare(strict_types=1);

// ---- Load environment (optional .env in project root, dev only) ----
$envFile = dirname(__DIR__) . '/.env';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k); $v = trim($v, " \t\n\r\0\x0B\"'");
        if ($k !== '' && getenv($k) === false) {
            putenv("$k=$v");
            $_ENV[$k] = $v;
        }
    }
}

function env(string $key, string $default = ''): string
{
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

// ---- Connection parameters ----
$dbHost = env('DB_HOST', '127.0.0.1');
$dbUser = env('DB_USER', 'root');
$dbPass = env('DB_PASS', '');
$dbName = env('DB_NAME', 'sovms');
$dbPort = (int) env('DB_PORT', '3306');

// ---- Connect (mysqli, OOP style) ----
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
} catch (mysqli_sql_exception $e) {
    // Never expose DB errors to the browser
    error_log('DB connection failed: ' . $e->getMessage());
    http_response_code(503);
    exit('Service temporarily unavailable. Please try again later.');
}

// Charset
if (!$mysqli->set_charset('utf8mb4')) {
    error_log('Charset set failed: ' . $mysqli->error);
}

// Keep PHP form parsing and MySQL election dates on the configured site timezone.
$timezone = env('APP_TIMEZONE', 'Asia/Manila');
$timezoneSetting = db_fetch_one(
    $mysqli,
    "SELECT `value` FROM system_settings WHERE `key`='app_timezone'",
    "",
    []
);
if ($timezoneSetting && $timezoneSetting['value'] !== null && $timezoneSetting['value'] !== '') {
    $timezone = $timezoneSetting['value'];
}

$validTimezones = DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC);
if (!in_array($timezone, $validTimezones, true)) {
    error_log('Invalid application timezone configured: ' . $timezone);
    http_response_code(500);
    exit('Invalid application timezone configuration.');
}

$timezoneObject = new DateTimeZone($timezone);
date_default_timezone_set($timezone);
$mysqlOffset = (new DateTimeImmutable('now', $timezoneObject))->format('P');
$mysqli->query("SET time_zone = '" . $mysqli->real_escape_string($mysqlOffset) . "'");

/**
 * Run a prepared statement and return the statement object.
 * Usage: $stmt = db_prepare($mysqli, "SELECT ... WHERE id = ?", "i", [$id]);
 */
function db_prepare(mysqli $db, string $sql, string $types = '', array $params = []): mysqli_stmt
{
    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        error_log('Prepare failed: ' . $db->error . ' | SQL: ' . $sql);
        throw new RuntimeException('Database error.');
    }
    if ($types !== '' && count($params) > 0) {
        $stmt->bind_param($types, ...$params);
    }
    return $stmt;
}

/**
 * Fetch all rows from a prepared query.
 */
function db_fetch_all(mysqli $db, string $sql, string $types = '', array $params = []): array
{
    $stmt = db_prepare($db, $sql, $types, $params);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

/**
 * Fetch a single row.
 */
function db_fetch_one(mysqli $db, string $sql, string $types = '', array $params = []): ?array
{
    $rows = db_fetch_all($db, $sql, $types, $params);
    return $rows[0] ?? null;
}

/**
 * Execute an INSERT/UPDATE/DELETE prepared statement.
 * Returns affected rows.
 */
function db_execute(mysqli $db, string $sql, string $types = '', array $params = []): int
{
    $stmt = db_prepare($db, $sql, $types, $params);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $affected;
}

// Expose connection
$GLOBALS['db'] = $mysqli;
if (!isset($GLOBALS['mysqli'])) {
    $GLOBALS['mysqli'] = $mysqli;
}
