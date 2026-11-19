<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login();
$reportId = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT r.*, d.name AS department_name, d.hod FROM department_annual_event_report r INNER JOIN department d ON d.id = r.department_id WHERE r.id = :id LIMIT 1');
$stmt->execute([':id' => $reportId]);
$report = $stmt->fetch();
$user = current_user();
if (!$report) {
    http_response_code(404);
    exit('Annual event report not found.');
}
if ($user['role'] !== 'admin' && (int) $user['department_id'] !== (int) $report['department_id']) {
    http_response_code(403);
    exit('Access denied.');
}
$itemsStmt = db()->prepare('SELECT event_name, event_date_from, event_date_to, purpose, planned_activities, responsible_officer, expected_outcomes, evidence FROM department_annual_event_item WHERE report_id = :id ORDER BY sort_order, id');
$itemsStmt->execute([':id' => $reportId]);
$items = $itemsStmt->fetchAll();
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($report['department_name']) ?> Annual Event Report <?= e((string) $report['report_year']) ?></title>
<style>
body{font:12px Arial,sans-serif;color:#132238;margin:24px}.report-tools{display:flex;gap:10px;margin:0 0 12px}.report-tools a,.report-tools button{border:1px solid #1d6b38;border-radius:7px;padding:8px 12px;background:#fff;color:#174526;font-weight:700;text-decoration:none;cursor:pointer}.report-tools button{background:#1d6b38;color:#fff}.head{border-bottom:3px solid #1d6b38;padding-bottom:12px;margin-bottom:16px}.brand{font-size:11px;color:#52647a}.title{font-size:20px;margin:6px 0;color:#174526}.meta{line-height:1.65}.event{border:1px solid #aab69a;border-radius:8px;padding:12px;margin:12px 0;break-inside:avoid}.event h2{font-size:15px;color:#174526;margin:0 0 8px}.event p{margin:5px 0;white-space:pre-wrap}.sign{display:flex;justify-content:space-between;margin-top:42px}.sign div{width:45%;border-top:1px solid #333;padding-top:6px}@media print{body{margin:10mm}.report-tools{display:none}@page{size:portrait;margin:12mm}}
</style></head><body>
<div class="report-tools"><a href="<?= e(app_url($user['role'] === 'admin' ? 'admin/department_reports.php' : 'department/annual_event_report.php?year=' . (int) $report['report_year'])) ?>">Back to reports</a><button type="button" onclick="window.print()">Print this report</button></div>
<header class="head"><div class="brand">FEDERAL NEURO-PSYCHIATRIC HOSPITAL, KADUNA</div><h1 class="title">Annual Department Event Report</h1><div class="meta"><strong>Department:</strong> <?= e($report['department_name']) ?> &nbsp; <strong>Year:</strong> <?= e((string) $report['report_year']) ?><br><strong>Head of Department:</strong> <?= e($report['hod']) ?> &nbsp; <strong>Status:</strong> <?= e(ucfirst($report['status'])) ?><?php if ($report['submitted_at']): ?><br><strong>Submitted:</strong> <?= e(format_datetime_human($report['submitted_at'])) ?><?php endif; ?></div></header>
<?php if (!$items): ?><p>No events submitted.</p><?php endif; ?>
<?php foreach ($items as $index => $item): ?><article class="event"><h2><?= $index + 1 ?>. <?= e($item['event_name']) ?></h2><p><strong>Date:</strong> <?= e($item['event_date_from']) ?> to <?= e($item['event_date_to']) ?></p><p><strong>Purpose:</strong> <?= e($item['purpose']) ?></p><p><strong>Planned activities:</strong> <?= e($item['planned_activities']) ?></p><p><strong>Responsible officer:</strong> <?= e($item['responsible_officer']) ?></p><p><strong>Expected outcomes:</strong> <?= e($item['expected_outcomes']) ?></p><?php if ($item['evidence'] !== ''): ?><p><strong>Evidence / verification:</strong> <?= e($item['evidence']) ?></p><?php endif; ?></article><?php endforeach; ?>
<?php if ($report['attachment_filename']): ?><p><strong>Supporting attachment:</strong> <a href="<?= e(app_url('exports/download_annual_event_attachment.php?id=' . $reportId)) ?>"><?= e($report['attachment_original_name'] ?? 'Download attachment') ?></a></p><?php endif; ?>
<div class="sign"><div>Head of Department name and signature</div><div>Date</div></div>
</body></html>
