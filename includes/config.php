<?php
// ============================================================
// includes/config.php — Secure version (reads from .env)
// ============================================================
// 🔒 Credentials are now stored in /.env (outside web root
//    ideally, or blocked via .htaccess).
//    This file contains NO hardcoded passwords.
// ============================================================

// ── PERMISSIONS POLICY — sent globally for all pages ─────────
// Allows geolocation only from same origin (self).
// Must be called before any output.
if (!headers_sent()) {
    header("Permissions-Policy: geolocation=(self), camera=(self), microphone=(self)");
}

// ── LOAD .env FILE ───────────────────────────────────────────
(function () {
    $envFile = dirname(__DIR__) . '/.env';

    if (!file_exists($envFile)) {
        error_log('[CONFIG] .env file not found at: ' . $envFile);
        die('Application configuration error. Please contact the administrator.');
    }

    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (str_starts_with(trim($line), '#')) continue;
        if (!str_contains($line, '=')) continue;

        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);

        // Strip inline comments  (e.g.  KEY=value  # comment)
        if (($pos = strpos($value, ' #')) !== false) {
            $value = substr($value, 0, $pos);
        }
        $value = trim($value, '"\'');

        if (!array_key_exists($key, $_ENV)) {
            $_ENV[$key]    = $value;
            putenv("$key=$value");
        }
    }
})();

// ── HELPER: safe env() reader ────────────────────────────────
function env(string $key, mixed $default = null): mixed {
    $val = $_ENV[$key] ?? getenv($key);
    return ($val !== false && $val !== '') ? $val : $default;
}

// ── DATABASE CONFIG ──────────────────────────────────────────
define('DB_HOST',    env('DB_HOST',    'localhost'));
define('DB_NAME',    env('DB_NAME',    ''));
define('DB_USER',    env('DB_USER',    ''));
define('DB_PASS',    env('DB_PASS',    ''));
define('DB_CHARSET', env('DB_CHARSET', 'utf8mb4'));

// ── APP SETTINGS ─────────────────────────────────────────────
define('APP_NAME',       env('APP_NAME', 'FieldApp'));
define('APP_ENV',        env('APP_ENV',  'production'));
define('APP_SECRET_KEY', env('APP_SECRET_KEY', ''));

// ── SESSION SETTINGS ─────────────────────────────────────────
define('MAX_MAIN_SESSIONS',    (int) env('MAX_MAIN_SESSIONS',    3));
define('MAX_SUB_SESSIONS',     (int) env('MAX_SUB_SESSIONS',     1));
define('SESSION_LIFETIME',     (int) env('SESSION_LIFETIME',     86400));
define('ADMIN_IDLE_TIMEOUT',   (int) env('ADMIN_IDLE_TIMEOUT',   900));
define('OFFICE_ADMIN_IDLE_TIMEOUT', (int) env('OFFICE_ADMIN_IDLE_TIMEOUT', 900));
define('SUBADMIN_IDLE_TIMEOUT',(int) env('SUBADMIN_IDLE_TIMEOUT',900));
define('DB_TIMEZONE_OFFSET',   env('DB_TIMEZONE_OFFSET', '+05:30'));

// ── TIMEZONE ─────────────────────────────────────────────────
date_default_timezone_set(env('APP_TIMEZONE', 'Asia/Kolkata'));

// ── ERROR DISPLAY ────────────────────────────────────────────
$displayErrors = (int) env('DISPLAY_ERRORS', 0);
ini_set('display_errors',         $displayErrors);
ini_set('display_startup_errors', $displayErrors);
error_reporting(E_ALL);

if ((int) env('LOG_ERRORS', 1)) {
    ini_set('log_errors', 1);
    $logPath = env('ERROR_LOG_PATH', '');
    if ($logPath) ini_set('error_log', $logPath);
}

// ── AUTO DETECT BASE URL ──────────────────────────────────────
(function () {
    $isHttps =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
        (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    $proto   = $isHttps ? 'https' : 'http';
    $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $appRoot = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');

    $subPath = '';
    if ($docRoot && str_starts_with($appRoot, $docRoot)) {
        $subPath = substr($appRoot, strlen($docRoot));
    }

    $subPath = trim($subPath, '/');
    $base    = $proto . '://' . $host . ($subPath ? '/' . $subPath : '');

    define('APP_URL', rtrim($base, '/'));
})();

// ── UPLOAD PATHS ─────────────────────────────────────────────
$customUploadDir = env('UPLOAD_DIR', '');
$customUploadUrl = env('UPLOAD_URL', '');

define('UPLOAD_DIR', $customUploadDir ?: dirname(__DIR__) . '/uploads/');
define('UPLOAD_URL', $customUploadUrl ?: APP_URL . '/uploads/');

if (!is_dir(UPLOAD_DIR)) {
    if (!@mkdir(UPLOAD_DIR, 0750, true) && !is_dir(UPLOAD_DIR)) {
        error_log('[CONFIG] Failed to create uploads directory: ' . UPLOAD_DIR);
        die('Server configuration error. Please try again later.');
    }
}

// ── SESSION COOKIE (SECURE) ───────────────────────────────────
ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
ini_set('session.use_strict_mode', 1);  // 🔒 reject unrecognized session IDs
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);

session_set_cookie_params([
    'lifetime' => SESSION_LIFETIME,
    'path'     => '/',
    'secure'   => isset($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Strict',   // 🔒 upgraded from Lax → Strict
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── DATABASE CONNECTION (SAFE + SINGLETON) ────────────────────
function getDB(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        if (!DB_NAME || !DB_USER) {
            error_log('[DB] Missing database credentials in .env');
            die('Database configuration error. Please contact the administrator.');
        }

        try {
            $dsn = "mysql:host=" . DB_HOST .
                   ";dbname=" . DB_NAME .
                   ";charset=" . DB_CHARSET;

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,   // 🔒 true prepared statements
                PDO::ATTR_PERSISTENT         => false,
                PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
            ];

            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

            // Keep all DB-generated DATETIME/NOW() values on IST.
            $pdo->exec("SET SESSION time_zone = " . $pdo->quote(DB_TIMEZONE_OFFSET));

            // 🔒 Enforce strict SQL mode to prevent silent truncation / type coercion
            $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO'");

        } catch (PDOException $e) {
            error_log('[DB] Connection Error: ' . $e->getMessage());

            if (APP_ENV === 'development') {
                die('DB ERROR: ' . htmlspecialchars($e->getMessage()));
            } else {
                die('Database connection error. Please try again later.');
            }
        }
    }

    return $pdo;
}
