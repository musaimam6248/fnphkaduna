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
    "SELECT r.id, r.department_id, r.report_month, r.report_year, r.period_from, r.period_to,
            r.narrative_html, r.attachment_filename, r.attachment_original_name, r.status,
            r.review_status, r.submitted_at, d.name AS department_name, d.hod,
            (SELECT COUNT(*) FROM department_monthly_kpi_update k WHERE k.report_id = r.id) AS kpi_count
     FROM department_monthly_report r
     INNER JOIN department d ON d.id = r.department_id
     WHERE r.report_month = :month AND r.report_year = :year
       AND r.status IN ('submitted', 'reviewed')
       AND (trim(r.narrative_html) <> '' OR COALESCE(r.attachment_filename, '') <> '')
       AND (:department_filter = 0 OR r.department_id = :department_match)
     ORDER BY d.name"
);
$stmt->execute([':month' => $month, ':year' => $year, ':department_filter' => $departmentId, ':department_match' => $departmentId]);
$reports = $stmt->fetchAll();

render_header('Monthly Department Reports');
?>
<section class="admin-monthly-list-page">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <a href="<?= e(app_url('admin/dashboard.php')) ?>" class="small">&larr; Admin dashboard</a>
            <h1 class="h3 mt-2 mb-1">Monthly Department Reports</h1>
            <p class="text-muted mb-0">Read monthly department narratives and supporting report attachments.</p>
        </div>
        <a class="btn btn-outline-primary" href="<?= e(app_url('admin/monthly_kpi_reports.php?month=' . $month . '&year=' . $year)) ?>">Open monthly KPI submissions</a>
    </div>

    <form method="get" class="form-panel mb-4">
        <div class="row g-3 align-items-end">
            <div class="col-md-3"><label class="form-label" for="report-month">Month</label><select class="form-select" name="month" id="report-month"><?php for ($option = 1; $option <= 12; $option++): ?><option value="<?= $option ?>" <?= $option === $month ? 'selected' : '' ?>><?= e(month_label($option)) ?></option><?php endfor; ?></select></div>
            <div class="col-md-2"><label class="form-label" for="report-year">Year</label><input class="form-control" type="number" name="year" id="report-year" min="2000" max="2200" value="<?= e((string) $year) ?>"></div>
            <div class="col-md-5"><label class="form-label" for="report-department">Department / unit</label><select class="form-select" name="department_id" id="report-department"><option value="0">All departments</option><?php foreach ($departments as $department): ?><option value="<?= (int) $department['id'] ?>" <?= $departmentId === (int) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2 d-grid"><button class="btn btn-primary" type="submit">Filter</button></div>
        </div>
    </form>

    <section class="monthly-kpi-list-summary mb-4">
        <article><span>Monthly reports</span><strong><?= e((string) count($reports)) ?></strong></article>
        <article><span>Reporting period</span><strong><?= e(format_month_year($month, $year)) ?></strong></article>
        <article><span>Departments</span><strong><?= e((string) count(array_unique(array_column($reports, 'department_id'))) ) ?></strong></article>
    </section>

    <section class="form-panel">
        <h2 class="h5 mb-3">Submitted monthly reports</h2>
        <?php if ($reports): ?>
            <div class="table-wrap"><table class="table table-striped align-middle mb-0">
                <thead><tr><th>Department / HOD</th><th>Period</th><th>Report narrative</th><th>Attachment</th><th>KPIs</th><th>Review</th><th class="text-end">Actions</th></tr></thead>
                <tbody><?php foreach ($reports as $report): ?>
                    <?php $reviewStatus = (string) ($report['review_status'] ?? 'pending'); $narrative = trim(strip_tags((string) $report['narrative_html'])); ?>
                    <tr>
                        <td><strong><?= e($report['department_name']) ?></strong><br><small class="text-muted">HOD: <?= e($report['hod'] ?: 'Not specified') ?></small></td>
                        <td><?= e(format_month_year((int) $report['report_month'], (int) $report['report_year'])) ?><br><small class="text-muted"><?= e($report['period_from']) ?> to <?= e($report['period_to']) ?></small></td>
                        <td class="monthly-report-narrative-cell"><?php if ($narrative !== ''): ?><details><summary>Read narrative</summary><p class="small mb-0 mt-2"><?= nl2br(e($narrative)) ?></p></details><?php else: ?><span class="text-muted">No narrative</span><?php endif; ?></td>
                        <td><?php if (!empty($report['attachment_filename'])): ?><a href="<?= e(app_url('exports/download_department_attachment.php?id=' . (int) $report['id'])) ?>"><?= e($report['attachment_original_name'] ?: 'Download report') ?></a><?php else: ?>—<?php endif; ?></td>
                        <td><?= e((string) $report['kpi_count']) ?></td>
                        <td><span class="badge text-bg-<?= $reviewStatus === 'returned' ? 'warning' : ($reviewStatus === 'reviewed' || $report['status'] === 'reviewed' ? 'success' : 'secondary') ?>"><?= e($reviewStatus === 'returned' ? 'Returned' : ($reviewStatus === 'reviewed' || $report['status'] === 'reviewed' ? 'Reviewed' : 'Pending')) ?></span></td>
                        <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-primary" href="<?= e(app_url('admin/monthly_report_review.php?report_id=' . (int) $report['id'])) ?>">Review</a><a class="btn btn-sm btn-outline-secondary ms-1" target="_blank" rel="noopener" href="<?= e(app_url('exports/print_monthly_report.php?id=' . (int) $report['id'])) ?>">Print</a></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php else: ?><p class="alert alert-secondary mb-0">No monthly report narratives or attachments were submitted for this period and filter.</p><?php endif; ?>
    </section>
</section>
<?php render_footer(); ?>
