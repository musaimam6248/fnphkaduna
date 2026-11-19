<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login('admin');

if (!is_post()) {
    redirect('admin/department_reports.php');
}

$reportId = (int) ($_POST['report_id'] ?? 0);
if ($reportId < 1) {
    set_flash('error', 'Choose a valid monthly report.');
    redirect('admin/department_reports.php');
}

$stmt = db()->prepare("UPDATE department_monthly_report SET status = 'reviewed', review_status = 'reviewed', reviewed_at = CURRENT_TIMESTAMP, edit_approved_at = NULL, edit_approved_by = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
$stmt->execute([':id' => $reportId]);
set_flash($stmt->rowCount() > 0 ? 'success' : 'info', $stmt->rowCount() > 0 ? 'Monthly report marked as reviewed.' : 'Monthly report was not changed.');
redirect('admin/department_reports.php');
