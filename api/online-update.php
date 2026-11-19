<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$paths = [
    $root . DIRECTORY_SEPARATOR . 'index.php',
    $root . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'main.js',
    $root . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'style.css',
    $root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'app.db',
    $root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'database.sqlite',
];

$latest = 0;
foreach ($paths as $path) {
    if (is_file($path)) {
        $mtime = filemtime($path);
        if (is_int($mtime) && $mtime > $latest) {
            $latest = $mtime;
        }
    }
}

try {
    $pdo = db();
    $queries = [
        "SELECT MAX(updated_at) AS ts FROM submission_schedule",
        "SELECT MAX(updated_at) AS ts FROM department_info",
        "SELECT MAX(uploaded_at) AS ts FROM gallery",
        "SELECT MAX(uploaded_at) AS ts FROM event_video",
        "SELECT MAX(created_at) AS ts FROM committee",
        "SELECT MAX(created_at) AS ts FROM winner",
        "SELECT MAX(created_at) AS ts FROM submission",
    ];

    foreach ($queries as $sql) {
        $row = $pdo->query($sql)->fetch();
        $ts = $row['ts'] ?? null;
        if ($ts) {
            $unix = strtotime((string) $ts);
            if (is_int($unix) && $unix > $latest) {
                $latest = $unix;
            }
        }
    }
} catch (Throwable $exception) {
    // Keep endpoint resilient; file-based version still works if DB is unavailable.
}

echo json_encode([
    'ok' => true,
    'version' => (string) $latest,
    'server_time' => gmdate('c'),
], JSON_UNESCAPED_SLASHES);
