<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login();
$reportId = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT r.attachment_filename, r.attachment_original_name, r.department_id, d.name AS department_name FROM department_annual_event_report r INNER JOIN department d ON d.id = r.department_id WHERE r.id = :id LIMIT 1');
$stmt->execute([':id' => $reportId]);
$report = $stmt->fetch();
$user = current_user();
if (!$report || empty($report['attachment_filename'])) {
    http_response_code(404);
    exit('Attachment not found.');
}
if ($user['role'] !== 'admin' && (int) $user['department_id'] !== (int) $report['department_id']) {
    http_response_code(403);
    exit('Access denied.');
}
$filename = basename((string) $report['attachment_filename']);
$path = UPLOAD_PATH . '/annual_events/' . $filename;
if (!is_file($path)) {
    http_response_code(404);
    exit('Attachment file not found.');
}
$downloadName = preg_replace('/[\r\n"]/', '', basename((string) ($report['attachment_original_name'] ?: 'annual-event-attachment')));
header('Content-Type: application/octet-stream');
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
readfile($path);
exit;
