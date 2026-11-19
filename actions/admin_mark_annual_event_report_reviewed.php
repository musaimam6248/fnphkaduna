<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login('admin');

if (!is_post()) {
    redirect('admin/department_reports.php');
}
$reportId = (int) ($_POST['report_id'] ?? 0);
if ($reportId < 1) {
    set_flash('error', 'Choose a valid annual event report.');
    redirect('admin/department_reports.php');
}
$stmt = db()->prepare("UPDATE department_annual_event_report SET status = 'reviewed', reviewed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND status = 'submitted'");
$stmt->execute([':id' => $reportId]);
set_flash($stmt->rowCount() > 0 ? 'success' : 'info', $stmt->rowCount() > 0 ? 'Annual event report marked as reviewed.' : 'Annual event report was not changed.');
redirect('admin/department_reports.php');
