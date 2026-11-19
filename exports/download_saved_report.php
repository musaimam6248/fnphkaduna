<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_login('admin');

$id = (int) ($_GET['id'] ?? 0);
if ($id < 1) {
    http_response_code(404);
    exit('Report not found.');
}

$stmt = db()->prepare('SELECT format, file_path FROM saved_report WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$report = $stmt->fetch();
if (!$report) {
    http_response_code(404);
    exit('Report not found.');
}

$relativePath = (string) ($report['file_path'] ?? '');
if (!str_starts_with($relativePath, 'uploads/reports/') || basename($relativePath) !== substr($relativePath, strlen('uploads/reports/'))) {
    http_response_code(404);
    exit('Report not found.');
}

$reportsDirectory = realpath(UPLOAD_PATH . '/reports');
$reportPath = realpath(BASE_PATH . '/' . $relativePath);
if ($reportsDirectory === false || $reportPath === false || dirname($reportPath) !== $reportsDirectory || !is_file($reportPath)) {
    http_response_code(404);
    exit('Report file is missing.');
}

$format = strtolower((string) $report['format']);
if (!in_array($format, ['csv', 'pdf'], true)) {
    http_response_code(415);
    exit('Unsupported report format.');
}

$downloadName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($reportPath)) ?: 'staff-report.' . $format;
header('Content-Type: ' . ($format === 'pdf' ? 'application/pdf' : 'text/csv; charset=utf-8'));
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . (string) filesize($reportPath));
header('X-Content-Type-Options: nosniff');
readfile($reportPath);
exit;
