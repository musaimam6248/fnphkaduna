<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_login('admin');

$pdo = db();
$month = (int) ($_GET['month'] ?? date('n'));
$year = (int) ($_GET['year'] ?? date('Y'));
$departmentId = max(0, (int) ($_GET['department_id'] ?? 0));
if ($month < 1 || $month > 12) {
    $month = (int) date('n');
}
if ($year < 2000 || $year > 2200) {
    $year = (int) date('Y');
}
$previousDate = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->modify('-1 month');
$previousMonth = (int) $previousDate->format('n');
$previousYear = (int) $previousDate->format('Y');
$departments = $pdo->query('SELECT id, name FROM department ORDER BY name')->fetchAll();

$periodReports = $pdo->prepare(
    "SELECT r.id AS report_id, r.department_id, r.report_month, r.report_year, r.submitted_at,
            d.name AS department_name, d.hod AS responsible_unit,
            u.id AS measure_id, u.annual_plan_item_id, u.sort_order, u.kra, u.kpi, u.target,
            u.achievement, u.achievement_percent, u.actual_value, u.variance_value,
            u.unit, u.status AS measure_status, u.next_step, u.commentary
     FROM department_monthly_report r
     INNER JOIN department d ON d.id = r.department_id
     LEFT JOIN department_monthly_kpi_update u ON u.report_id = r.id
     WHERE r.report_month = :month AND r.report_year = :year
       AND r.status IN ('submitted', 'reviewed') AND r.review_status <> 'returned'
       AND (:department_filter = 0 OR r.department_id = :department_match)
     ORDER BY d.name, u.sort_order, u.id"
);
$periodReports->execute([':month' => $month, ':year' => $year, ':department_filter' => $departmentId, ':department_match' => $departmentId]);
$currentRows = $periodReports->fetchAll();

$priorStmt = $pdo->prepare(
    "SELECT r.department_id, u.annual_plan_item_id, u.kra, u.kpi, u.achievement_percent,
            u.actual_value, u.status AS measure_status
     FROM department_monthly_report r
     INNER JOIN department_monthly_kpi_update u ON u.report_id = r.id
     WHERE r.report_month = :month AND r.report_year = :year
       AND r.status IN ('submitted', 'reviewed') AND r.review_status <> 'returned'
       AND (:department_filter = 0 OR r.department_id = :department_match)
     ORDER BY r.department_id, u.sort_order, u.id"
);
$priorStmt->execute([':month' => $previousMonth, ':year' => $previousYear, ':department_filter' => $departmentId, ':department_match' => $departmentId]);
$priorByDepartment = [];
foreach ($priorStmt->fetchAll() as $priorRow) {
    $priorDepartment = (int) $priorRow['department_id'];
    $priorKey = mb_strtolower(trim((string) $priorRow['kra'])) . '|' . mb_strtolower(trim((string) $priorRow['kpi']));
    $priorByDepartment[$priorDepartment]['text:' . $priorKey] = $priorRow;
    if (!empty($priorRow['annual_plan_item_id'])) {
        $priorByDepartment[$priorDepartment]['plan:' . (int) $priorRow['annual_plan_item_id']] = $priorRow;
    }
}

$reportMap = [];
$measureComparisons = [];
$statusCounts = ['on_track' => 0, 'at_risk' => 0, 'off_track' => 0, 'not_available' => 0];
$trendCounts = ['improved' => 0, 'declined' => 0, 'unchanged' => 0, 'no_comparison' => 0];
$currentAchievementValues = [];
$pairedCurrentValues = [];
$pairedPreviousValues = [];
foreach ($currentRows as $row) {
    $reportId = (int) $row['report_id'];
    if (!isset($reportMap[$reportId])) {
        $reportMap[$reportId] = [
            'id' => $reportId,
            'department_id' => (int) $row['department_id'],
            'department_name' => (string) $row['department_name'],
            'responsible_unit' => (string) ($row['responsible_unit'] ?? ''),
            'submitted_at' => (string) $row['submitted_at'],
            'measures' => 0,
            'improved' => 0,
            'declined' => 0,
            'unchanged' => 0,
            'no_comparison' => 0,
            'current_values' => [],
            'paired_current_values' => [],
            'previous_values' => [],
        ];
    }
    if ($row['measure_id'] === null) {
        continue;
    }
    $reportMap[$reportId]['measures']++;
    $departmentKey = (int) $row['department_id'];
    $measurePercent = is_numeric($row['achievement_percent']) ? (float) $row['achievement_percent'] : null;
    $status = (string) ($row['measure_status'] ?? '');
    if (!isset($statusCounts[$status])) {
        $status = $measurePercent === null ? 'not_available' : ($measurePercent >= 100 ? 'on_track' : ($measurePercent >= 80 ? 'at_risk' : 'off_track'));
    }
    $statusCounts[$status]++;
    if ($measurePercent !== null) {
        $currentAchievementValues[] = $measurePercent;
        $reportMap[$reportId]['current_values'][] = $measurePercent;
    }
    $textKey = mb_strtolower(trim((string) $row['kra'])) . '|' . mb_strtolower(trim((string) $row['kpi']));
    $prior = null;
    if (!empty($row['annual_plan_item_id'])) {
        $prior = $priorByDepartment[$departmentKey]['plan:' . (int) $row['annual_plan_item_id']] ?? null;
    }
    $prior ??= $priorByDepartment[$departmentKey]['text:' . $textKey] ?? null;
    $priorPercent = $prior && is_numeric($prior['achievement_percent']) ? (float) $prior['achievement_percent'] : null;
    $difference = $measurePercent !== null && $priorPercent !== null ? $measurePercent - $priorPercent : null;
    $trend = $difference === null ? 'no_comparison' : (abs($difference) < 0.05 ? 'unchanged' : ($difference > 0 ? 'improved' : 'declined'));
    $trendCounts[$trend]++;
    $reportMap[$reportId][$trend === 'no_comparison' ? 'no_comparison' : $trend]++;
    if ($difference !== null) {
        $pairedCurrentValues[] = $measurePercent;
        $pairedPreviousValues[] = $priorPercent;
        $reportMap[$reportId]['paired_current_values'][] = $measurePercent;
        $reportMap[$reportId]['previous_values'][] = $priorPercent;
    }
    $measureStatusLabel = match ($status) {
        'on_track' => 'On target',
        'at_risk' => 'Near target',
        'off_track' => 'Below target',
        default => 'Achievement unavailable',
    };
    $analysis = match ($trend) {
        'improved' => number_format($difference, 1) . ' percentage-point improvement' . ($measurePercent !== null && $measurePercent < 100 ? ', but the measure remains below target' : ''),
        'declined' => number_format(abs($difference), 1) . ' percentage-point decline; review the reported barrier and next step',
        'unchanged' => 'No material month-to-month change; continue monitoring the measure',
        default => 'No matching KPI result in the previous month; use this result as a baseline',
    };
    $measureComparisons[] = [
        'department_name' => (string) $row['department_name'],
        'responsible_unit' => (string) ($row['responsible_unit'] ?? ''),
        'kra' => (string) $row['kra'],
        'kpi' => (string) $row['kpi'],
        'target' => (string) $row['target'],
        'current' => $measurePercent,
        'previous' => $priorPercent,
        'change' => $difference,
        'trend' => $trend,
        'status_label' => $measureStatusLabel,
        'analysis' => $analysis,
        'next_step' => trim((string) ($row['next_step'] ?? '')),
    ];
}

$reportList = array_values($reportMap);
$improvementDecision = 'No previous-month comparison available';
$averageChange = null;
if ($pairedCurrentValues) {
    $averageChange = array_sum($pairedCurrentValues) / count($pairedCurrentValues) - array_sum($pairedPreviousValues) / count($pairedPreviousValues);
    $improvementDecision = $averageChange > 0.05 ? 'Overall improvement' : ($averageChange < -0.05 ? 'Overall decline' : 'Overall stable');
}
$averageCurrentAchievement = $currentAchievementValues ? array_sum($currentAchievementValues) / count($currentAchievementValues) : null;
$decisionClass = str_contains($improvementDecision, 'improvement') ? 'is-improving' : (str_contains($improvementDecision, 'decline') ? 'is-declining' : 'is-stable');
$reportsWithPrior = count(array_filter($reportList, static fn(array $report): bool => count($report['previous_values']) > 0));
$averageBarWidth = $averageCurrentAchievement === null ? 0 : min(100, max(0, $averageCurrentAchievement / 120 * 100));

render_header('Monthly KPI Comparison Analytics');
?>
<section class="admin-monthly-analytics">
    <header class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <a href="<?= e(app_url('admin/dashboard.php')) ?>" class="small">&larr; Admin dashboard</a>
            <h1 class="h3 mt-2 mb-1">Monthly KPI Comparison Analytics</h1>
            <p class="text-muted mb-0">Compare current submissions with the immediately preceding month and review the measure-level evidence.</p>
        </div>
        <button type="button" class="btn btn-outline-primary" onclick="window.print()">Print / Save as PDF</button>
    </header>

    <form method="get" class="form-panel monthly-analytics-filters mb-4">
        <div class="row g-3 align-items-end">
            <div class="col-sm-4 col-lg-3"><label class="form-label" for="analytics-month">Current month</label><select class="form-select" id="analytics-month" name="month"><?php for ($option = 1; $option <= 12; $option++): ?><option value="<?= $option ?>" <?= $month === $option ? 'selected' : '' ?>><?= e(month_label($option)) ?></option><?php endfor; ?></select></div>
            <div class="col-sm-3 col-lg-2"><label class="form-label" for="analytics-year">Year</label><input class="form-control" id="analytics-year" type="number" name="year" min="2000" max="2200" value="<?= e((string) $year) ?>"></div>
            <div class="col-sm-5 col-lg-5"><label class="form-label" for="analytics-department">Department / unit</label><select class="form-select" id="analytics-department" name="department_id"><option value="0">All departments</option><?php foreach ($departments as $department): ?><option value="<?= (int) $department['id'] ?>" <?= $departmentId === (int) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-lg-2 d-grid"><button class="btn btn-primary" type="submit">Analyze period</button></div>
        </div>
        <p class="small text-muted mb-0 mt-3">Comparison period: <strong><?= e(format_month_year($previousMonth, $previousYear)) ?></strong>. Only submitted or reviewed reports are included; returned reports are excluded.</p>
    </form>

    <section class="monthly-analytics-decision <?= e($decisionClass) ?> mb-4">
        <div><span class="eyebrow">Month-to-month decision</span><h2><?= e($improvementDecision) ?></h2><p class="mb-0"><?php if ($averageChange !== null): ?>Average achievement changed <?= e(($averageChange > 0 ? '+' : '') . number_format($averageChange, 1)) ?> percentage points across <?= e((string) count($pairedCurrentValues)) ?> comparable KPI measures.<?php else: ?>No matching KPI measures were found in both periods, so a directional decision cannot be calculated.<?php endif; ?></p></div>
        <div class="decision-gauge" aria-label="<?= e($improvementDecision) ?>"><span><?= $averageChange !== null ? e(($averageChange > 0 ? '+' : '') . number_format($averageChange, 1) . ' pp') : 'N/A' ?></span></div>
    </section>

    <section class="monthly-analytics-summary mb-4" aria-label="Monthly report summary">
        <article><span>Reports submitted</span><strong><?= e((string) count($reportList)) ?></strong></article>
        <article><span>KPI measures</span><strong><?= e((string) count($measureComparisons)) ?></strong></article>
        <article class="is-improved"><span>Improved</span><strong><?= e((string) $trendCounts['improved']) ?></strong></article>
        <article class="is-declined"><span>Declined</span><strong><?= e((string) $trendCounts['declined']) ?></strong></article>
        <article class="is-unchanged"><span>No change</span><strong><?= e((string) $trendCounts['unchanged']) ?></strong></article>
        <article class="is-baseline"><span>No comparison</span><strong><?= e((string) $trendCounts['no_comparison']) ?></strong></article>
    </section>

    <section class="monthly-analytics-panels mb-4">
        <article class="form-panel monthly-analytics-chart">
            <div class="d-flex justify-content-between gap-2"><div><h2 class="h5 mb-1">Average achievement</h2><p class="small text-muted">Current month across reported KPIs</p></div><strong class="analytics-chart-value"><?= $averageCurrentAchievement !== null ? e(number_format($averageCurrentAchievement, 1) . '%') : '—' ?></strong></div>
            <div class="analytics-track" role="img" aria-label="Current average achievement <?= $averageCurrentAchievement !== null ? e(number_format($averageCurrentAchievement, 1) . ' percent') : 'not available' ?>"><span style="width: <?= e(number_format($averageBarWidth, 1)) ?>%"></span></div>
            <div class="analytics-scale"><span>0%</span><span>120% scale</span></div>
            <p class="small text-muted mb-0 mt-3">Bars cap at 120% for readability; the numeric average is shown without capping.</p>
        </article>
        <article class="form-panel monthly-analytics-chart">
            <h2 class="h5 mb-1">Current KPI status</h2><p class="small text-muted">Status against each reported target</p>
            <?php foreach (['on_track' => 'On target', 'at_risk' => 'Near target', 'off_track' => 'Below target', 'not_available' => 'No achievement'] as $statusKey => $statusText): ?>
                <?php $count = $statusCounts[$statusKey]; $width = count($measureComparisons) > 0 ? ($count / count($measureComparisons)) * 100 : 0; ?>
                <div class="status-chart-row"><span><?= e($statusText) ?></span><div class="status-chart-track"><i class="status-<?= e($statusKey) ?>" style="width: <?= e(number_format($width, 1)) ?>%"></i></div><strong><?= e((string) $count) ?></strong></div>
            <?php endforeach; ?>
        </article>
    </section>

    <section class="form-panel mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3"><div><h2 class="h5 mb-1">Department comparison</h2><p class="small text-muted mb-0">Average is calculated from measures reported by that department. Trend counts include only KPIs matched to the previous month.</p></div><span class="badge text-bg-light"><?= e((string) $reportsWithPrior) ?> departments with comparisons</span></div>
        <?php if ($reportList): ?>
            <div class="table-wrap"><table class="table table-striped align-middle mb-0 monthly-department-comparison">
                <thead><tr><th>Department / unit</th><th>Current average</th><th>Previous average<br><small><?= e(month_label($previousMonth)) ?></small></th><th>Movement</th><th>Improved</th><th>Declined</th><th>Decision</th></tr></thead>
                <tbody><?php foreach ($reportList as $reportSummary): ?>
                    <?php
                    $currentAverage = $reportSummary['paired_current_values'] ? array_sum($reportSummary['paired_current_values']) / count($reportSummary['paired_current_values']) : null;
                    $priorAverage = $reportSummary['previous_values'] ? array_sum($reportSummary['previous_values']) / count($reportSummary['previous_values']) : null;
                    $movement = $currentAverage !== null && $priorAverage !== null ? $currentAverage - $priorAverage : null;
                    $reportDecision = $movement === null ? 'No comparison' : ($movement > 0.05 ? 'Improving' : ($movement < -0.05 ? 'Declining' : 'Stable'));
                    $reportDecisionClass = $movement === null ? 'neutral' : ($movement > 0.05 ? 'positive' : ($movement < -0.05 ? 'negative' : 'neutral'));
                    ?>
                    <tr><td><strong><?= e($reportSummary['department_name']) ?></strong><br><small class="text-muted">HOD: <?= e($reportSummary['responsible_unit'] ?: 'Not specified') ?></small></td><td><?= $currentAverage !== null ? e(number_format($currentAverage, 1) . '%') : '—' ?></td><td><?= $priorAverage !== null ? e(number_format($priorAverage, 1) . '%') : '—' ?></td><td class="<?= $movement !== null && $movement > 0.05 ? 'text-success' : ($movement !== null && $movement < -0.05 ? 'text-danger' : 'text-muted') ?>"><?= $movement !== null ? e(($movement > 0 ? '+' : '') . number_format($movement, 1) . ' pp') : '—' ?></td><td><?= e((string) $reportSummary['improved']) ?></td><td><?= e((string) $reportSummary['declined']) ?></td><td><span class="analytics-verdict <?= e($reportDecisionClass) ?>"><?= e($reportDecision) ?></span></td></tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php else: ?><p class="alert alert-secondary mb-0">No submitted reports were found for <?= e(format_month_year($month, $year)) ?>.</p><?php endif; ?>
    </section>

    <section class="form-panel">
        <div class="d-flex flex-wrap justify-content-between gap-2 mb-3"><div><h2 class="h5 mb-1">KPI measure analysis</h2><p class="small text-muted mb-0">Each decision shows the current and previous achievement, target status, and the submitted next step.</p></div><span class="badge text-bg-light"><?= e((string) count($measureComparisons)) ?> measures</span></div>
        <?php if ($measureComparisons): ?>
            <div class="table-wrap"><table class="table table-bordered align-middle mb-0 monthly-measure-analysis">
                <thead><tr><th>Department / KPI</th><th>Target</th><th><?= e(month_label($previousMonth)) ?><br>achievement</th><th><?= e(month_label($month)) ?><br>achievement</th><th>Change</th><th>Assessment</th><th>Analysis / next step</th></tr></thead>
                <tbody><?php foreach ($measureComparisons as $measure): ?>
                    <?php $verdictClass = $measure['trend'] === 'improved' ? 'positive' : ($measure['trend'] === 'declined' ? 'negative' : 'neutral'); ?>
                    <tr><td><strong><?= e($measure['department_name']) ?></strong><br><span><?= e($measure['kpi']) ?></span><br><small class="text-muted"><?= e($measure['responsible_unit'] ?: 'Unit not specified') ?> · <?= e($measure['kra']) ?></small></td><td><?= e($measure['target']) ?></td><td><?= $measure['previous'] !== null ? e(number_format($measure['previous'], 1) . '%') : '—' ?></td><td><strong><?= $measure['current'] !== null ? e(number_format($measure['current'], 1) . '%') : '—' ?></strong><br><small class="text-muted"><?= e($measure['status_label']) ?></small></td><td class="<?= $measure['change'] !== null && $measure['change'] > 0.05 ? 'text-success' : ($measure['change'] !== null && $measure['change'] < -0.05 ? 'text-danger' : 'text-muted') ?>"><?= $measure['change'] !== null ? e(($measure['change'] > 0 ? '+' : '') . number_format($measure['change'], 1) . ' pp') : '—' ?></td><td><span class="analytics-verdict <?= e($verdictClass) ?>"><?= e(ucwords(str_replace('_', ' ', $measure['trend']))) ?></span></td><td><?= e($measure['analysis']) ?><?php if ($measure['next_step'] !== ''): ?><br><small class="text-muted"><strong>Reported next step:</strong> <?= e($measure['next_step']) ?></small><?php endif; ?></td></tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php else: ?><p class="alert alert-secondary mb-0">There are no KPI measures to analyze for this period.</p><?php endif; ?>
    </section>

    <p class="small text-muted mt-3 mb-0">Decision rule: improvement means achievement increased by more than 0.05 percentage points; decline means it decreased by more than 0.05 points. Target status is shown separately, so an improving KPI can still remain below target.</p>
</section>
<?php render_footer(); ?>
