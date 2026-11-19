<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login('admin');

if (!is_post()) {
    redirect('admin/department_reports.php');
}

$recordType = (string) ($_POST['record_type'] ?? '');
$recordId = (int) ($_POST['record_id'] ?? 0);
$user = current_user();

if ($recordId < 1 || !in_array($recordType, ['annual_plan', 'monthly_report', 'annual_event_report'], true)) {
    set_flash('error', 'Choose a valid submitted plan or monthly KPI report.');
    redirect('admin/department_reports.php');
}

$table = match ($recordType) {
    'annual_plan' => 'department_annual_plan',
    'monthly_report' => 'department_monthly_report',
    default => 'department_annual_event_report',
};
$stmt = db()->prepare(
    "UPDATE {$table}
     SET edit_approved_at = CURRENT_TIMESTAMP, edit_approved_by = :admin_id, updated_at = CURRENT_TIMESTAMP
     WHERE id = :id AND status IN ('submitted', 'reviewed')"
);
$stmt->execute([':admin_id' => (int) $user['id'], ':id' => $recordId]);

set_flash($stmt->rowCount() > 0 ? 'success' : 'info', $stmt->rowCount() > 0
    ? 'One edit approved. The department will be locked again after resubmission.'
    : 'The record was not found or is not submitted.');
redirect('admin/department_reports.php');
