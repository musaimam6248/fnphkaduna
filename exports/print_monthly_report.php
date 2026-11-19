<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login();
$user = current_user();

$reportId = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare(
    'SELECT r.*, d.name AS department_name, d.hod
     FROM department_monthly_report r INNER JOIN department d ON d.id = r.department_id
     WHERE r.id = :id LIMIT 1'
);
$stmt->execute([':id' => $reportId]);
$report = $stmt->fetch();
if (!$report) {
    http_response_code(404);
    exit('Monthly report not found.');
}
$isReportOwner = (int) ($user['department_id'] ?? 0) === (int) $report['department_id'];
$isSharedSubmission = in_array($report['status'], ['submitted', 'reviewed'], true) && $report['review_status'] !== 'returned';
if ($user['role'] !== 'admin' && !$isReportOwner && !($user['role'] === 'department' && $isSharedSubmission)) {
    http_response_code(403);
    exit('Access denied.');
}
$updatesStmt = db()->prepare('SELECT id, kra, smart_objective, kpi, target, target_value, unit, achievement, achievement_percent, actual_value, variance_value, status, evidence, commentary, next_step, achievement_attachment_filename, achievement_attachment_original_name, evidence_attachment_filename, evidence_attachment_original_name FROM department_monthly_kpi_update WHERE report_id = :id ORDER BY sort_order, id');
$updatesStmt->execute([':id' => $reportId]);
$updates = $updatesStmt->fetchAll();
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($report['department_name']) ?> Monthly Report</title>
<style>
body{font:11px Arial,sans-serif;color:#132238;margin:22px}.report-tools{display:flex;gap:10px;margin:0 0 12px}.report-tools a,.report-tools button{border:1px solid #1d6b38;border-radius:7px;padding:8px 12px;background:#fff;color:#174526;font-weight:700;text-decoration:none;cursor:pointer}.report-tools button{background:#1d6b38;color:#fff}.head{border-bottom:3px solid #1d6b38;padding-bottom:10px;margin-bottom:14px}.brand{font-size:10px;color:#52647a}.title{font-size:19px;margin:5px 0;color:#174526}.meta{line-height:1.6}h2{font-size:14px;margin:18px 0 8px;color:#174526}table{border-collapse:collapse;width:100%;table-layout:fixed}th,td{border:1px solid #7b8792;padding:7px;vertical-align:top;overflow-wrap:anywhere}th{background:#e7edf1;color:#273745;text-align:center}.section-heading{background:#263746;color:#fff;text-align:left;font-weight:700}.status{display:inline-block;border-radius:20px;padding:3px 7px;font-weight:700;white-space:nowrap}.status.on_track{background:#238044;color:#fff}.status.at_risk{background:#f2d35e;color:#332d09}.status.off_track{background:#b42318;color:#fff}.sign{display:flex;justify-content:space-between;margin-top:28px}.sign div{width:50%;min-height:45px;border:1px solid #7b8792;padding:8px}.sign strong{display:block;margin-bottom:18px}@media print{body{margin:8mm}.report-tools{display:none}@page{size:landscape;margin:9mm}}
</style></head><body>
<div class="report-tools"><a href="<?= e(app_url('department/monthly_report.php?month=' . (int) $report['report_month'] . '&year=' . (int) $report['report_year'])) ?>">Back to monthly report</a><button type="button" onclick="window.print()">Print this report</button></div>
<header class="head"><div class="brand">DIRECTORATE: <?= e(strtoupper($report['department_name'])) ?></div><h1 class="title">Monthly Report</h1><div class="meta"><strong>Report:</strong> <?= e(format_month_year((int) $report['report_month'], (int) $report['report_year'])) ?></div></header>
<table><thead><tr><th class="section-heading" colspan="2">UPDATE PERIOD</th></tr><tr><td><strong>From:</strong> <?= e($report['period_from']) ?></td><td><strong>To:</strong> <?= e($report['period_to']) ?></td></tr></thead></table>
<h2>UPDATES</h2><table><thead><tr><th>KRA</th><th>SMART Objectives</th><th>KPI</th><th>Target</th><th>Achievement</th><th>%</th><th>Evidence</th></tr></thead><tbody>
<?php if (!$updates): ?><tr><td colspan="7">No KPI updates were submitted.</td></tr><?php endif; ?>
<?php foreach ($updates as $index => $update): ?><tr><?php if ($index === 0 || $updates[$index - 1]['kra'] !== $update['kra']): ?><?php $kraRowspan = 1; while (isset($updates[$index + $kraRowspan]) && $updates[$index + $kraRowspan]['kra'] === $update['kra']) { $kraRowspan++; } ?><td rowspan="<?= $kraRowspan ?>"><?= e($update['kra']) ?></td><?php endif; ?><td><?= e($update['smart_objective']) ?></td><td><?= e($update['kpi']) ?></td><td><?= e($update['target']) ?><?php if ($update['target_value'] !== null): ?><br><strong><?= e((string) $update['target_value']) ?> <?= e($update['unit']) ?></strong><?php endif; ?></td><td><?= e($update['actual_value'] === null ? $update['achievement'] : (string) $update['actual_value'] . ' ' . $update['unit']) ?><?php if ($update['achievement_attachment_filename']): ?><br><a href="<?= e(app_url('exports/download_monthly_kpi_attachment.php?id=' . (int) $update['id'] . '&kind=achievement')) ?>"><?= e($update['achievement_attachment_original_name'] ?? 'Achievement file') ?></a><?php endif; ?></td><td><?= $update['achievement_percent'] === null ? '' : e(number_format((float) $update['achievement_percent'], 1)) ?></td><td><?= e($update['evidence']) ?><?php if ($update['evidence_attachment_filename']): ?><br><a href="<?= e(app_url('exports/download_monthly_kpi_attachment.php?id=' . (int) $update['id'] . '&kind=evidence')) ?>"><?= e($update['evidence_attachment_original_name'] ?? 'Evidence file') ?></a><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table>
<?php if (trim(strip_tags((string) $report['narrative_html'])) !== ''): ?><h2>UNIT / DEPARTMENT REPORT</h2><div style="white-space:pre-wrap;line-height:1.5"><?= e(trim(strip_tags((string) $report['narrative_html']))) ?></div><?php endif; ?>
<?php if (!empty($report['attachment_filename'])): ?><p><strong>Attached report:</strong> <a href="<?= e(app_url('exports/download_department_attachment.php?id=' . $reportId)) ?>"><?= e($report['attachment_original_name'] ?? 'Download report') ?></a></p><?php endif; ?>
<div class="sign"><div><strong>Head of Dept Name and Signature:</strong><?= e($report['hod']) ?></div><div><strong>Date:</strong></div></div>
</body></html>
