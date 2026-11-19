<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$user = current_user();
if (!$user) {
    require_login();
}

$reportId = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT department_id, attachment_filename, attachment_original_name, status, review_status FROM department_monthly_report WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $reportId]);
$report = $stmt->fetch();
if (!$report || empty($report['attachment_filename'])) {
    http_response_code(404);
    exit('Attachment not found.');
}
$isReportOwner = (int) ($user['department_id'] ?? 0) === (int) $report['department_id'];
$isSharedSubmission = in_array($report['status'], ['submitted', 'reviewed'], true) && $report['review_status'] !== 'returned';
if ($user['role'] !== 'admin' && !$isReportOwner && !($user['role'] === 'department' && $isSharedSubmission)) {
    http_response_code(403);
    exit('Access denied.');
}

$storedName = (string) $report['attachment_filename'];
if (basename($storedName) !== $storedName) {
    http_response_code(404);
    exit('Attachment not found.');
}
$storageDirectory = realpath(UPLOAD_PATH . '/monthly_reports');
$filePath = realpath(UPLOAD_PATH . '/monthly_reports/' . $storedName);
if ($storageDirectory === false || $filePath === false || dirname($filePath) !== $storageDirectory || !is_file($filePath)) {
    http_response_code(404);
    exit('Attachment file is missing.');
}

$extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
$contentTypes = [
    'pdf' => 'application/pdf', 'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ppt' => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
];
if (!isset($contentTypes[$extension])) {
    http_response_code(415);
    exit('Unsupported attachment type.');
}

$originalName = basename(str_replace('\\', '/', (string) ($report['attachment_original_name'] ?? $storedName)));
$downloadName = preg_replace('/[^A-Za-z0-9._-]/', '_', $originalName) ?: 'department-report.' . $extension;
header('Content-Type: ' . $contentTypes[$extension]);
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . (string) filesize($filePath));
header('X-Content-Type-Options: nosniff');
readfile($filePath);
exit;
