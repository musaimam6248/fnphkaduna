<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login();
$user = current_user();

$planId = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare(
    'SELECT p.*, d.name AS department_name, d.hod
     FROM department_annual_plan p INNER JOIN department d ON d.id = p.department_id
     WHERE p.id = :id LIMIT 1'
);
$stmt->execute([':id' => $planId]);
$plan = $stmt->fetch();
if (!$plan) {
    http_response_code(404);
    exit('Annual plan not found.');
}
if ($user['role'] !== 'admin' && (int) $user['department_id'] !== (int) $plan['department_id']) {
    http_response_code(403);
    exit('Access denied.');
}
$itemsStmt = db()->prepare('SELECT kra, smart_objective, kpi, target, target_value, unit, direction, verification FROM department_annual_plan_item WHERE plan_id = :id ORDER BY sort_order, id');
$itemsStmt->execute([':id' => $planId]);
$items = $itemsStmt->fetchAll();
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($plan['department_name']) ?> Annual Plan <?= e((string) $plan['plan_year']) ?></title>
<style>
body{font:12px Arial,sans-serif;color:#132238;margin:24px}.report-tools{display:flex;gap:10px;margin:0 0 12px}.report-tools a,.report-tools button{border:1px solid #1d6b38;border-radius:7px;padding:8px 12px;background:#fff;color:#174526;font-weight:700;text-decoration:none;cursor:pointer}.report-tools button{background:#1d6b38;color:#fff}.head{border-bottom:3px solid #1d6b38;padding-bottom:12px;margin-bottom:16px}.brand{font-size:11px;color:#52647a}.title{font-size:20px;margin:6px 0;color:#174526}.meta{line-height:1.65}table{border-collapse:collapse;width:100%;table-layout:fixed}th,td{border:1px solid #aab69a;padding:7px;vertical-align:top;overflow-wrap:anywhere}th{background:#f5f6e9;color:#174526;text-align:left}.sign{display:flex;justify-content:space-between;margin-top:42px}.sign div{width:45%;border-top:1px solid #333;padding-top:6px}@media print{body{margin:10mm}.report-tools{display:none}@page{size:landscape;margin:10mm}}
</style></head><body>
<div class="report-tools"><a href="<?= e(app_url('department/annual_plan.php?year=' . (int) $plan['plan_year'])) ?>">Back to KPI targets</a><button type="button" onclick="window.print()">Print this plan</button></div>
<header class="head"><div class="brand">FEDERAL NEURO-PSYCHIATRIC HOSPITAL, KADUNA</div><h1 class="title">Department Annual KPI Targets</h1><div class="meta"><strong>Department:</strong> <?= e($plan['department_name']) ?> &nbsp; <strong>Plan year:</strong> <?= e((string) $plan['plan_year']) ?><br><strong>Period:</strong> <?= e($plan['period_from']) ?> to <?= e($plan['period_to']) ?> &nbsp; <strong>Status:</strong> <?= e(ucfirst($plan['status'])) ?><br><strong>Head of Department:</strong> <?= e($plan['hod']) ?></div></header>
<table><thead><tr><th style="width:16%">KRA</th><th style="width:27%">SMART Objectives</th><th style="width:19%">KPI</th><th style="width:13%">Target</th><th style="width:25%">Evidence / Verification</th></tr></thead><tbody>
<?php if (!$items): ?><tr><td colspan="5">No KPI rows in this plan.</td></tr><?php endif; ?>
<?php foreach ($items as $item): ?><tr><td><?= e($item['kra']) ?></td><td><?= e($item['smart_objective']) ?></td><td><?= e($item['kpi']) ?></td><td><?= e($item['target']) ?><?php if ($item['target_value'] !== null): ?><br><strong><?= e((string) $item['target_value']) ?> <?= e($item['unit']) ?></strong><br><small><?= $item['direction'] === 'lower' ? 'Lower is better' : 'Higher is better' ?></small><?php endif; ?></td><td><?= e($item['verification']) ?></td></tr><?php endforeach; ?>
</tbody></table>
<div class="sign"><div>Head of Department name and signature</div><div>Date</div></div>
</body></html>
