<?php
declare(strict_types=1);

define('APP_NAME', 'FNPH Kaduna Staff Excellence Portal');
define('BASE_PATH', realpath(__DIR__ . '/..') ?: (__DIR__ . '/..'));
$localSettingsPath = __DIR__ . '/local.php';
$localSettings = is_file($localSettingsPath) ? require $localSettingsPath : [];
if (!is_array($localSettings)) {
    $localSettings = [];
}
$appBaseUrl = getenv('APP_BASE_URL');
if (!$appBaseUrl) {
    $appBaseUrl = (string) ($localSettings['APP_BASE_URL'] ?? '');
}
if (!$appBaseUrl) {
    $forwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwardedProto === 'https') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $appBaseUrl = rtrim($scheme . '://' . $host, '/');
}
define('APP_BASE_URL', $appBaseUrl);
define('UPLOAD_PATH', getenv('UPLOAD_PATH') ?: (string) ($localSettings['UPLOAD_PATH'] ?? BASE_PATH . '/uploads'));

$timezone = getenv('APP_TIMEZONE') ?: (string) ($localSettings['APP_TIMEZONE'] ?? 'Africa/Lagos');
date_default_timezone_set($timezone);

$sessionPath = BASE_PATH . '/database/sessions';
if (!is_dir($sessionPath)) {
    mkdir($sessionPath, 0775, true);
}
if (session_status() === PHP_SESSION_NONE) {
    session_save_path($sessionPath);
    session_start();
}

$dbDsn = getenv('DB_DSN');
if (!$dbDsn) {
    $dbDsn = (string) ($localSettings['DB_DSN'] ?? ('sqlite:' . BASE_PATH . '/database/app.db'));
}

define('DB_DSN', $dbDsn);
define('DB_USER', getenv('DB_USER') ?: (string) ($localSettings['DB_USER'] ?? ''));
define('DB_PASS', getenv('DB_PASS') ?: (string) ($localSettings['DB_PASS'] ?? ''));

define('DEFAULT_ADMIN_USERNAME', getenv('ADMIN_DEFAULT_USERNAME') ?: 'admin');
$adminDefaultPassword = getenv('ADMIN_DEFAULT_PASSWORD');
define('DEFAULT_ADMIN_PASSWORD', $adminDefaultPassword === false ? (string) ($localSettings['ADMIN_DEFAULT_PASSWORD'] ?? '') : $adminDefaultPassword);
