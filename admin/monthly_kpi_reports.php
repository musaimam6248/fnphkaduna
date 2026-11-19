<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_login('admin');

$pdo = db();
$month = (int) ($_GET['month'] ?? date('n'));
$year = (int) ($_GET['year'] ?? date('Y'));
$departmentId = max(0, (int) ($_GET['department_id'] ?? 0));
if ($month < 1 || $month > 12) $month = (int) date('n');
if ($year < 2000 || $year > 2200) $year = (int) date('Y');
$departments = $pdo->query('SELECT id, name FROM department ORDER BY name')->fetchAll();
$stmt = $pdo->prepare(
    "SELECT r.id, r.department_id, r.report_month, r.report_year, r.period_from, r.period_to, r.status, r.review_status,
            r.submitted_at, d.name AS department_name, d.hod,
            COUNT(k.id) AS measure_count,
            ROUND(AVG(k.achievement_percent), 1) AS average_achievement,
            SUM(CASE WHEN k.status = 'on_track' THEN 1 ELSE 0 END) AS on_track_count,
            SUM(CASE WHEN k.status = 'at_risk' THEN 1 ELSE 0 END) AS at_risk_count,
            SUM(CASE WHEN k.status = 'off_track' THEN 1 ELSE 0 END) AS off_track_count
     FROM department_monthly_report r
     INNER JOIN department d ON d.id = r.department_id
     INNER JOIN department_monthly_kpi_update k ON k.report_id = r.id
     WHERE r.report_month = :month AND r.report_year = :year
       AND r.status IN ('submitted', 'reviewed') AND r.review_status <> 'returned'
       AND (:department_filter = 0 OR r.department_id = :department_match)
     GROUP BY r.id
     ORDER BY d.name"
);
$stmt->execute([':month' => $month, ':year' => $year, ':department_filter' => $departmentId, ':department_match' => $departmentId]);
$reports = $stmt->fetchAll();
$measureTotal = array_sum(array_map(static fn(array $report): int => (int) $report['measure_count'], $reports));

render_header('Monthly KPI Submissions');
?>
<section class="admin-monthly-list-page">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <a href="<?= e(app_url('admin/dashboard.php')) ?>" class="small">&larr; Admin dashboard</a>
            <h1 class="h3 mt-2 mb-1">Monthly KPI Submissions</h1>
            <p class="text-muted mb-0">Review submitted KPI measures by department and open the full statistical assessment.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-success" href="<?= e(app_url('exports/monthly_kpi_excel.php?month=' . $month . '&year=' . $year . '&department_id=' . $departmentId)) ?>">Download KPI for Excel (CSV)</a>
            <a class="btn btn-outline-primary" href="<?= e(app_url('admin/monthly_report_analytics.php?month=' . $month . '&year=' . $year . '&department_id=' . $departmentId)) ?>">Comparison analytics</a>
            <a class="btn btn-outline-secondary" href="<?= e(app_url('admin/monthly_report_review.php')) ?>">Review comments</a>
        </div>
    </div>

    <form method="get" class="form-panel mb-4">
        <div class="row g-3 align-items-end">
            <div class="col-md-3"><label class="form-label" for="kpi-month">Month</label><select class="form-select" name="month" id="kpi-month"><?php for ($option = 1; $option <= 12; $option++): ?><option value="<?= $option ?>" <?= $option === $month ? 'selected' : '' ?>><?= e(month_label($option)) ?></option><?php endfor; ?></select></div>
            <div class="col-md-2"><label class="form-label" for="kpi-year">Year</label><input class="form-control" type="number" name="year" id="kpi-year" min="2000" max="2200" value="<?= e((string) $year) ?>"></div>
            <div class="col-md-5"><label class="form-label" for="kpi-department">Department / unit</label><select class="form-select" name="department_id" id="kpi-department"><option value="0">All departments</option><?php foreach ($departments as $department): ?><option value="<?= (int) $department['id'] ?>" <?= $departmentId === (int) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2 d-grid"><button class="btn btn-primary" type="submit">Filter</button></div>
        </div>
    </form>

    <section class="monthly-kpi-list-summary mb-4">
        <article><span>Departments with KPI submissions</span><strong><?= e((string) count($reports)) ?></strong></article>
        <article><span>Submitted measures</span><strong><?= e((string) $measureTotal) ?></strong></article>
        <article><span>Reporting period</span><strong><?= e(format_month_year($month, $year)) ?></strong></article>
    </section>

    <section class="form-panel">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h2 class="h5 mb-1">KPI submissions by department</h2><p class="small text-muted mb-0">Returned reports are listed in the monthly report review history rather than counted as current submissions.</p></div><span class="badge text-bg-light"><?= e((string) count($reports)) ?> departments</span></div>
        <?php if ($reports): ?>
            <div class="table-wrap"><table class="table table-striped align-middle mb-0">
                <thead><tr><th>Department / HOD</th><th>Period</th><th>Measures</th><th>Average achievement</th><th>Target status</th><th>Review status</th><th class="text-end">Actions</th></tr></thead>
                <tbody><?php foreach ($reports as $report): ?>
                    <?php $reviewStatus = (string) ($report['review_status'] ?? 'pending'); ?>
                    <tr>
                        <td><strong><?= e($report['department_name']) ?></strong><br><small class="text-muted">HOD: <?= e($report['hod'] ?: 'Not specified') ?></small></td>
                        <td><?= e(format_month_year((int) $report['report_month'], (int) $report['report_year'])) ?><br><small class="text-muted"><?= e($report['period_from']) ?> to <?= e($report['period_to']) ?></small></td>
                        <td><?= e((string) $report['measure_count']) ?></td>
                        <td><?= $report['average_achievement'] !== null ? e(number_format((float) $report['average_achievement'], 1) . '%') : '—' ?></td>
                        <td><span class="kpi-count-status">On target <?= e((string) $report['on_track_count']) ?> · Near <?= e((string) $report['at_risk_count']) ?> · Below <?= e((string) $report['off_track_count']) ?></span></td>
                        <td><span class="badge text-bg-<?= $reviewStatus === 'returned' ? 'warning' : ($reviewStatus === 'reviewed' || $report['status'] === 'reviewed' ? 'success' : 'secondary') ?>"><?= e($reviewStatus === 'returned' ? 'Returned' : ($reviewStatus === 'reviewed' || $report['status'] === 'reviewed' ? 'Reviewed' : 'Pending')) ?></span></td>
                        <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-primary" href="<?= e(app_url('admin/monthly_report_review.php?report_id=' . (int) $report['id'])) ?>">Read KPI</a><a class="btn btn-sm btn-outline-secondary ms-1" target="_blank" rel="noopener" href="<?= e(app_url('exports/print_monthly_report.php?id=' . (int) $report['id'])) ?>">Read report</a><a class="btn btn-sm btn-outline-secondary ms-1" href="<?= e(app_url('admin/monthly_report_analytics.php?month=' . $month . '&year=' . $year . '&department_id=' . (int) $report['department_id'])) ?>">Compare</a></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php else: ?><p class="alert alert-secondary mb-0">No KPI submissions were found for this period and filter.</p><?php endif; ?>
    </section>
</section>
<?php render_footer(); ?>
