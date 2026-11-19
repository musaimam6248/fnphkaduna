<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_login('admin');

$pdo = db();
$departmentId = (int) ($_GET['department_id'] ?? 0);
$month = (int) ($_GET['month'] ?? 0);
$year = (int) ($_GET['year'] ?? 0);
$trackerMonth = $month >= 1 && $month <= 12 ? $month : (int) date('n');
$trackerYear = $year >= 2000 && $year <= 2200 ? $year : (int) date('Y');
$filters = [];
$params = [];
if ($departmentId > 0) {
    $filters[] = 'r.department_id = :department_id';
    $params[':department_id'] = $departmentId;
}
if ($month >= 1 && $month <= 12) {
    $filters[] = 'r.report_month = :month';
    $params[':month'] = $month;
}
if ($year >= 2000 && $year <= 2200) {
    $filters[] = 'r.report_year = :year';
    $params[':year'] = $year;
}
$where = $filters ? 'WHERE ' . implode(' AND ', $filters) : '';
$reportsStmt = $pdo->prepare(
    "SELECT r.id, r.report_month, r.report_year, r.period_from, r.period_to, r.status, r.review_status, r.submitted_at, r.edit_approved_at,
            r.attachment_filename, r.attachment_original_name, d.name AS department_name, d.hod
     FROM department_monthly_report r
     INNER JOIN department d ON d.id = r.department_id
     {$where}
     ORDER BY r.report_year DESC, r.report_month DESC, d.name ASC
     LIMIT 200"
);
$reportsStmt->execute($params);
$reports = $reportsStmt->fetchAll();

$planFilters = [];
$planParams = [];
if ($departmentId > 0) {
    $planFilters[] = 'p.department_id = :department_id';
    $planParams[':department_id'] = $departmentId;
}
if ($year >= 2000 && $year <= 2200) {
    $planFilters[] = 'p.plan_year = :year';
    $planParams[':year'] = $year;
}
$planWhere = $planFilters ? 'WHERE ' . implode(' AND ', $planFilters) : '';
$plansStmt = $pdo->prepare(
    "SELECT p.id, p.plan_year, p.period_from, p.period_to, p.status, p.submitted_at, p.edit_approved_at, d.name AS department_name, d.hod,
            (SELECT COUNT(*) FROM department_annual_plan_item pi WHERE pi.plan_id = p.id) AS kpi_count
     FROM department_annual_plan p
     INNER JOIN department d ON d.id = p.department_id
     {$planWhere}
     ORDER BY p.plan_year DESC, d.name ASC
     LIMIT 200"
);
$plansStmt->execute($planParams);
$plans = $plansStmt->fetchAll();
$eventFilters = [];
$eventParams = [];
if ($departmentId > 0) {
    $eventFilters[] = 'r.department_id = :department_id';
    $eventParams[':department_id'] = $departmentId;
}
if ($year >= 2000 && $year <= 2200) {
    $eventFilters[] = 'r.report_year = :year';
    $eventParams[':year'] = $year;
}
$eventWhere = $eventFilters ? 'WHERE ' . implode(' AND ', $eventFilters) : '';
$eventReportsStmt = $pdo->prepare(
    "SELECT r.id, r.report_year, r.status, r.submitted_at, r.edit_approved_at, r.attachment_original_name,
            d.name AS department_name, d.hod,
            (SELECT COUNT(*) FROM department_annual_event_item i WHERE i.report_id = r.id) AS event_count
     FROM department_annual_event_report r
     INNER JOIN department d ON d.id = r.department_id
     {$eventWhere}
     ORDER BY r.report_year DESC, d.name ASC
     LIMIT 200"
);
$eventReportsStmt->execute($eventParams);
$eventReports = $eventReportsStmt->fetchAll();
$departments = $pdo->query('SELECT id, name FROM department ORDER BY name')->fetchAll();
$trackerStmt = $pdo->prepare(
    'SELECT d.id AS department_id, d.name AS department_name, d.hod,
            r.id AS report_id, r.status, r.submitted_at, r.attachment_original_name
     FROM department d
     LEFT JOIN department_monthly_report r
       ON r.department_id = d.id AND r.report_month = :month AND r.report_year = :year
     ORDER BY d.name ASC'
);
$trackerStmt->execute([':month' => $trackerMonth, ':year' => $trackerYear]);
$departmentReportStatuses = $trackerStmt->fetchAll();

$chartYear = $year >= 2000 && $year <= 2200 ? $year : (int) date('Y');
$chartParams = [':chart_year' => $chartYear];
$chartDepartmentFilter = '';
if ($departmentId > 0) {
    $chartDepartmentFilter = ' AND d.id = :chart_department_id';
    $chartParams[':chart_department_id'] = $departmentId;
}
$chartStmt = $pdo->prepare(
    'SELECT d.id AS department_id, d.name AS department_name, r.report_month,
            COUNT(u.id) AS kpi_count, AVG(u.achievement_percent) AS average_percent
     FROM department d
     LEFT JOIN department_monthly_report r ON r.department_id = d.id AND r.report_year = :chart_year AND r.status IN (\'submitted\', \'reviewed\')
     LEFT JOIN department_monthly_kpi_update u ON u.report_id = r.id
     WHERE 1 = 1' . $chartDepartmentFilter . '
     GROUP BY d.id, d.name, r.report_month
     ORDER BY d.name, r.report_month'
);
$chartStmt->execute($chartParams);
$departmentProgress = [];
foreach ($chartStmt->fetchAll() as $progressRow) {
    $id = (int) $progressRow['department_id'];
    if (!isset($departmentProgress[$id])) {
        $departmentProgress[$id] = ['name' => $progressRow['department_name'], 'months' => []];
    }
    if ($progressRow['report_month'] !== null && $progressRow['average_percent'] !== null) {
        $departmentProgress[$id]['months'][(int) $progressRow['report_month']] = [
            'average' => (float) $progressRow['average_percent'],
            'kpi_count' => (int) $progressRow['kpi_count'],
        ];
    }
}

render_header('Department Reports and Plans');
?>
<section class="department-reports-admin">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div><a href="<?= e(app_url('admin/dashboard.php')) ?>" class="small">&larr; Admin dashboard</a><h1 class="h3 mt-2 mb-1">Department KPI and Event Reports</h1><p class="text-muted mb-0">Review monthly KPI submissions, annual event workflows, and annual KPI targets by department.</p></div>
    </div>

    <form method="get" class="form-panel row g-3 align-items-end mb-4">
        <div class="col-md-5"><label class="form-label" for="department-filter">Department</label><select class="form-select" id="department-filter" name="department_id"><option value="0">All departments</option><?php foreach ($departments as $department): ?><option value="<?= (int) $department['id'] ?>" <?= $departmentId === (int) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label" for="filter-month">Month</label><select class="form-select" id="filter-month" name="month"><option value="0">All months</option><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>" <?= $month === $m ? 'selected' : '' ?>><?= e(month_label($m)) ?></option><?php endfor; ?></select></div>
        <div class="col-md-2"><label class="form-label" for="filter-year">Year</label><input class="form-control" id="filter-year" type="number" name="year" min="2000" max="2200" value="<?= $year > 0 ? e((string) $year) : '' ?>" placeholder="All"></div>
        <div class="col-md-2 d-grid"><button class="btn btn-primary" type="submit">Filter</button></div>
    </form>

    <section class="form-panel mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
            <div><h2 class="h5 mb-1">Monthly KPI Progress by Department / Unit</h2><p class="small text-muted mb-0">Average achievement percentage across submitted KPI rows for each month in <?= e((string) $chartYear) ?>.</p></div>
            <span class="badge text-bg-light">Higher percentage means more target achievement</span>
        </div>
        <?php if (!$departmentProgress): ?>
            <p class="text-muted mb-0">No departments are registered.</p>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($departmentProgress as $progress): ?>
                    <?php
                    $reportedMonths = array_keys($progress['months']);
                    $latestMonth = $reportedMonths ? max($reportedMonths) : null;
                    $previousMonths = $latestMonth === null ? [] : array_values(array_filter($reportedMonths, static fn($m) => $m < $latestMonth));
                    $previousMonth = $previousMonths ? max($previousMonths) : null;
                    $latestPercent = $latestMonth === null ? null : $progress['months'][$latestMonth]['average'];
                    $change = ($latestMonth !== null && $previousMonth !== null) ? $latestPercent - $progress['months'][$previousMonth]['average'] : null;
                    $plotPoints = [];
                    foreach ($progress['months'] as $monthNumber => $monthData) {
                        $x = 48 + (($monthNumber - 1) * 49);
                        $y = 202 - (max(0, min(100, $monthData['average'])) * 1.55);
                        $plotPoints[] = $x . ',' . number_format($y, 1, '.', '');
                    }
                    ?>
                    <div class="col-12 col-xl-6">
                        <article class="card card-soft h-100">
                            <div class="card-body">
                                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                                    <h3 class="h6 mb-0"><?= e($progress['name']) ?></h3>
                                    <?php if ($latestPercent !== null): ?><span class="badge text-bg-<?= $latestPercent >= 80 ? 'success' : ($latestPercent >= 50 ? 'warning' : 'danger') ?>">Latest <?= e(month_label($latestMonth)) ?>: <?= e(number_format($latestPercent, 1)) ?>%</span><?php endif; ?>
                                </div>
                                <?php if ($plotPoints): ?>
                                    <div class="small text-muted mb-2">
                                        <?php if ($change !== null): ?><?= $change >= 0 ? 'Up ' : 'Down ' ?><?= e(number_format(abs($change), 1)) ?> percentage points from <?= e(month_label($previousMonth)) ?>.<?php else: ?>One submitted month available; a trend needs at least two reports.<?php endif; ?>
                                        <?= e((string) $progress['months'][$latestMonth]['kpi_count']) ?> KPI rows in the latest report.
                                    </div>
                                    <div class="table-wrap">
                                        <svg class="department-kpi-chart" viewBox="0 0 640 250" role="img" aria-label="Monthly KPI achievement trend for <?= e($progress['name']) ?> in <?= e((string) $chartYear) ?>">
                                            <?php foreach ([0, 50, 100] as $gridValue): ?><?php $gridY = 202 - ($gridValue * 1.55); ?><line x1="42" y1="<?= e(number_format($gridY, 1, '.', '')) ?>" x2="603" y2="<?= e(number_format($gridY, 1, '.', '')) ?>" stroke="#d8e0e5" stroke-width="1"/><text x="4" y="<?= e(number_format($gridY + 4, 1, '.', '')) ?>" font-size="11" fill="#52647a"><?= $gridValue ?>%</text><?php endforeach; ?>
                                            <?php if (count($plotPoints) > 1): ?><polyline points="<?= e(implode(' ', $plotPoints)) ?>" fill="none" stroke="#1d6b38" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/><?php endif; ?>
                                            <?php for ($monthNumber = 1; $monthNumber <= 12; $monthNumber++): ?><?php $monthX = 48 + (($monthNumber - 1) * 49); ?><text x="<?= $monthX ?>" y="229" text-anchor="middle" font-size="10" fill="#52647a"><?= e(substr(month_label($monthNumber), 0, 3)) ?></text><?php if (isset($progress['months'][$monthNumber])): ?><?php $value = $progress['months'][$monthNumber]['average']; $pointY = 202 - (max(0, min(100, $value)) * 1.55); ?><circle cx="<?= $monthX ?>" cy="<?= e(number_format($pointY, 1, '.', '')) ?>" r="4.5" fill="#1d6b38"><title><?= e(month_label($monthNumber)) ?>: <?= e(number_format($value, 1)) ?>%</title></circle><?php endif; ?><?php endfor; ?>
                                        </svg>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted mb-0">No submitted monthly KPI reports for <?= e((string) $chartYear) ?>.</p>
                                <?php endif; ?>
                            </div>
                        </article>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="form-panel mb-4">
        <h2 class="h5">Annual Event Workflows</h2>
        <?php if ($eventReports): ?>
            <div class="table-wrap"><table class="table table-striped align-middle"><thead><tr><th>Year</th><th>Department / HOD</th><th>Events</th><th>Status</th><th>Attachment</th><th class="text-end">Actions</th></tr></thead><tbody>
            <?php foreach ($eventReports as $eventReport): ?>
                <tr>
                    <td><?= e((string) $eventReport['report_year']) ?></td>
                    <td><strong><?= e($eventReport['department_name']) ?></strong><br><small class="text-muted">HOD: <?= e($eventReport['hod']) ?></small></td>
                    <td><?= e((string) $eventReport['event_count']) ?></td>
                    <td><span class="badge text-bg-<?= $eventReport['status'] === 'reviewed' ? 'success' : ($eventReport['status'] === 'submitted' ? 'warning' : 'secondary') ?>"><?= e(ucfirst($eventReport['status'])) ?></span><?php if ($eventReport['submitted_at']): ?><br><small class="text-muted"><?= e(format_datetime_human($eventReport['submitted_at'])) ?></small><?php endif; ?></td>
                    <td><?php if ($eventReport['attachment_original_name']): ?><a href="<?= e(app_url('exports/download_annual_event_attachment.php?id=' . (int) $eventReport['id'])) ?>"><?= e($eventReport['attachment_original_name']) ?></a><?php else: ?>—<?php endif; ?></td>
                    <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener" href="<?= e(app_url('exports/print_annual_event_report.php?id=' . (int) $eventReport['id'])) ?>">View / print</a>
                        <?php if ($eventReport['status'] !== 'draft' && empty($eventReport['edit_approved_at'])): ?><form method="post" action="<?= e(app_url('actions/admin_approve_department_edit.php')) ?>" class="d-inline"><input type="hidden" name="record_type" value="annual_event_report"><input type="hidden" name="record_id" value="<?= (int) $eventReport['id'] ?>"><button type="submit" class="btn btn-sm btn-outline-warning">Approve edit</button></form><?php elseif (!empty($eventReport['edit_approved_at'])): ?><span class="badge text-bg-warning">Edit approved</span><?php endif; ?>
                        <?php if ($eventReport['status'] === 'submitted'): ?><form method="post" action="<?= e(app_url('actions/admin_mark_annual_event_report_reviewed.php')) ?>" class="d-inline"><input type="hidden" name="report_id" value="<?= (int) $eventReport['id'] ?>"><button type="submit" class="btn btn-sm btn-outline-success">Mark reviewed</button></form><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php else: ?><p class="text-muted mb-0">No annual event reports match these filters.</p><?php endif; ?>
    </section>

    <section class="form-panel mb-4">
        <h2 class="h5">Department submission status</h2>
        <p class="text-muted">Monthly report coverage for <?= e(format_month_year($trackerMonth, $trackerYear)) ?>.</p>
        <?php if ($departmentReportStatuses): ?>
            <div class="table-wrap"><table class="table table-striped align-middle mb-0"><thead><tr><th>Department / HOD</th><th>Status</th><th>Submitted</th><th>Attachment</th><th class="text-end">Report</th></tr></thead><tbody>
            <?php foreach ($departmentReportStatuses as $departmentStatus): ?>
                <tr>
                    <td><strong><?= e($departmentStatus['department_name']) ?></strong><br><small class="text-muted">HOD: <?= e($departmentStatus['hod']) ?></small></td>
                    <td><?php if (empty($departmentStatus['report_id'])): ?><span class="badge text-bg-danger">Missing</span><?php else: ?><span class="badge text-bg-<?= $departmentStatus['status'] === 'reviewed' ? 'success' : 'warning' ?>"><?= e(ucfirst($departmentStatus['status'])) ?></span><?php endif; ?></td>
                    <td><?= !empty($departmentStatus['submitted_at']) ? e(format_datetime_human($departmentStatus['submitted_at'])) : '—' ?></td>
                    <td><?= !empty($departmentStatus['attachment_original_name']) ? e($departmentStatus['attachment_original_name']) : '—' ?></td>
                    <td class="text-end"><?php if (!empty($departmentStatus['report_id'])): ?><a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener" href="<?= e(app_url('exports/print_monthly_report.php?id=' . (int) $departmentStatus['report_id'])) ?>">View / print</a><?php else: ?>—<?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php else: ?><p class="text-muted mb-0">No departments are registered.</p><?php endif; ?>
    </section>

    <section class="form-panel mb-4">
        <h2 class="h5">Monthly KPI Reports by Department / Unit</h2>
        <?php if ($reports): ?>
            <div class="table-wrap"><table class="table table-striped align-middle"><thead><tr><th>Period</th><th>Department / HOD</th><th>KPIs</th><th>Status</th><th>File</th><th class="text-end">Actions</th></tr></thead><tbody>
            <?php foreach ($reports as $report): ?>
                <?php $countStmt = $pdo->prepare('SELECT COUNT(*) FROM department_monthly_kpi_update WHERE report_id = :id'); $countStmt->execute([':id' => (int) $report['id']]); $kpiCount = (int) $countStmt->fetchColumn(); ?>
                <tr>
                    <td><?= e(format_month_year((int) $report['report_month'], (int) $report['report_year'])) ?><br><small class="text-muted"><?= e($report['period_from']) ?> to <?= e($report['period_to']) ?></small></td>
                    <td><strong><?= e($report['department_name']) ?></strong><br><small class="text-muted">HOD: <?= e($report['hod']) ?></small></td>
                    <td><?= e((string) $kpiCount) ?></td>
                    <td><span class="badge text-bg-<?= ($report['review_status'] ?? '') === 'returned' ? 'danger' : ($report['status'] === 'reviewed' ? 'success' : 'warning') ?>"><?= e(($report['review_status'] ?? '') === 'returned' ? 'Returned for revision' : ucfirst($report['status'])) ?></span><br><small class="text-muted"><?= e(format_datetime_human($report['submitted_at'])) ?></small></td>
                    <td><?php if (!empty($report['attachment_filename'])): ?><a href="<?= e(app_url('exports/download_department_attachment.php?id=' . (int) $report['id'])) ?>"><?= e($report['attachment_original_name'] ?? 'Download') ?></a><?php else: ?>—<?php endif; ?></td>
                    <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-primary" href="<?= e(app_url('admin/monthly_report_review.php?report_id=' . (int) $report['id'])) ?>">Review measures</a>
                    <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener" href="<?= e(app_url('exports/print_monthly_report.php?id=' . (int) $report['id'])) ?>">View / print</a>
                    <?php if (empty($report['edit_approved_at'])): ?><form method="post" action="<?= e(app_url('actions/admin_approve_department_edit.php')) ?>" class="d-inline"><input type="hidden" name="record_type" value="monthly_report"><input type="hidden" name="record_id" value="<?= (int) $report['id'] ?>"><button type="submit" class="btn btn-sm btn-outline-warning">Approve edit</button></form><?php else: ?><span class="badge text-bg-warning">Edit approved</span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php else: ?><p class="text-muted mb-0">No monthly reports match these filters.</p><?php endif; ?>
    </section>

    <section class="form-panel">
        <h2 class="h5">Annual KPI Targets</h2>
        <?php if ($plans): ?>
            <div class="table-wrap"><table class="table table-striped align-middle"><thead><tr><th>Year</th><th>Department / HOD</th><th>Plan period</th><th>KPI rows</th><th>Status</th><th></th></tr></thead><tbody>
            <?php foreach ($plans as $plan): ?><tr>
                <td><?= e((string) $plan['plan_year']) ?></td><td><strong><?= e($plan['department_name']) ?></strong><br><small class="text-muted">HOD: <?= e($plan['hod']) ?></small></td>
                <td><?= e($plan['period_from']) ?> to <?= e($plan['period_to']) ?></td><td><?= e((string) $plan['kpi_count']) ?></td><td><?= e(ucfirst($plan['status'])) ?></td>
                <td class="text-end"><a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener" href="<?= e(app_url('exports/print_annual_plan.php?id=' . (int) $plan['id'])) ?>">View / print</a>
                    <?php if ($plan['status'] === 'submitted' && empty($plan['edit_approved_at'])): ?><form method="post" action="<?= e(app_url('actions/admin_approve_department_edit.php')) ?>" class="d-inline"><input type="hidden" name="record_type" value="annual_plan"><input type="hidden" name="record_id" value="<?= (int) $plan['id'] ?>"><button type="submit" class="btn btn-sm btn-outline-warning">Approve edit</button></form><?php elseif (!empty($plan['edit_approved_at'])): ?><span class="badge text-bg-warning">Edit approved</span><?php endif; ?></td>
            </tr><?php endforeach; ?>
            </tbody></table></div>
        <?php else: ?><p class="text-muted mb-0">No annual plans match these filters.</p><?php endif; ?>
    </section>
</section>
<?php render_footer(); ?>
