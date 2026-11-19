<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login();

$updateId = (int) ($_GET['id'] ?? 0);
$kind = (string) ($_GET['kind'] ?? '');
$columns = [
    'achievement' => ['achievement_attachment_filename', 'achievement_attachment_original_name'],
    'evidence' => ['evidence_attachment_filename', 'evidence_attachment_original_name'],
];
if ($updateId < 1 || !isset($columns[$kind])) {
    http_response_code(404);
    exit('Attachment not found.');
}
[$filenameColumn, $nameColumn] = $columns[$kind];
$stmt = db()->prepare(
    'SELECT u.' . $filenameColumn . ' AS attachment_filename, u.' . $nameColumn . ' AS original_name, dmr.department_id, dmr.status, dmr.review_status
     FROM department_monthly_kpi_update u
     INNER JOIN department_monthly_report dmr ON dmr.id = u.report_id
     WHERE u.id = :id LIMIT 1'
);
$stmt->execute([':id' => $updateId]);
$attachment = $stmt->fetch();
$user = current_user();
if (!$attachment || empty($attachment['attachment_filename'])) {
    http_response_code(404);
    exit('Attachment not found.');
}
$isReportOwner = (int) ($user['department_id'] ?? 0) === (int) $attachment['department_id'];
$isSharedSubmission = in_array($attachment['status'], ['submitted', 'reviewed'], true) && $attachment['review_status'] !== 'returned';
if ($user['role'] !== 'admin' && !$isReportOwner && !($user['role'] === 'department' && $isSharedSubmission)) {
    http_response_code(403);
    exit('Access denied.');
}
$filename = basename((string) $attachment['attachment_filename']);
$path = UPLOAD_PATH . '/monthly_reports/' . $filename;
if (!is_file($path)) {
    http_response_code(404);
    exit('Attachment file not found.');
}
$downloadName = preg_replace('/[\r\n"]/', '', basename((string) ($attachment['original_name'] ?: 'monthly-kpi-attachment')));
header('Content-Type: application/octet-stream');
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
readfile($path);
exit;
