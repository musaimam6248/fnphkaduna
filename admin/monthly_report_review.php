<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/monthly_report_stats.php';
require_login('admin');

$pdo = db();
$departmentId = max(0, (int) ($_GET['department_id'] ?? 0));
$month = max(0, (int) ($_GET['month'] ?? 0));
$year = (int) ($_GET['year'] ?? 0);
if ($month > 12) {
    $month = 0;
}
if ($year < 2000 || $year > 2200) {
    $year = 0;
}
$reportId = max(0, (int) ($_GET['report_id'] ?? 0));

$departments = $pdo->query('SELECT id, name FROM department ORDER BY name')->fetchAll();
$filters = [];
$filterParams = [];
if ($departmentId > 0) {
    $filters[] = 'r.department_id = :department_id';
    $filterParams[':department_id'] = $departmentId;
}
if ($month > 0) {
    $filters[] = 'r.report_month = :month';
    $filterParams[':month'] = $month;
}
if ($year > 0) {
    $filters[] = 'r.report_year = :year';
    $filterParams[':year'] = $year;
}
$where = $filters ? 'WHERE ' . implode(' AND ', $filters) : '';
$listStmt = $pdo->prepare(
    "SELECT r.id, r.report_month, r.report_year, r.period_from, r.period_to, r.status, r.review_status,
            r.submitted_at, r.reviewed_at, d.name AS department_name, d.hod AS responsible_unit,
            (SELECT COUNT(*) FROM department_monthly_kpi_update k WHERE k.report_id = r.id) AS measure_count
     FROM department_monthly_report r
     INNER JOIN department d ON d.id = r.department_id
     {$where}
     ORDER BY r.report_year DESC, r.report_month DESC, d.name ASC
     LIMIT 200"
);
$listStmt->execute($filterParams);
$reports = $listStmt->fetchAll();

$selectedReport = null;
$statisticalRows = [];
$summary = monthly_report_statistical_summary([]);
$reviewHistory = [];
$measureComments = [];
if ($reportId > 0) {
    $selectedStmt = $pdo->prepare(
        'SELECT r.*, d.name AS department_name, d.hod AS responsible_unit
         FROM department_monthly_report r
         INNER JOIN department d ON d.id = r.department_id
         WHERE r.id = :id LIMIT 1'
    );
    $selectedStmt->execute([':id' => $reportId]);
    $selectedReport = $selectedStmt->fetch() ?: null;
    if ($selectedReport) {
        $statisticalRows = monthly_report_statistical_rows($pdo, $reportId);
        $summary = monthly_report_statistical_summary($statisticalRows);

        $historyStmt = $pdo->prepare(
            'SELECT review.*, users.username AS admin_name
             FROM department_monthly_report_review review
             INNER JOIN users ON users.id = review.admin_id
             WHERE review.report_id = :report_id AND review.kpi_update_id IS NULL
             ORDER BY review.id DESC LIMIT 12'
        );
        $historyStmt->execute([':report_id' => $reportId]);
        $reviewHistory = $historyStmt->fetchAll();
        foreach ($statisticalRows as $statisticalRow) {
            $measureComments[(int) $statisticalRow['id']] = (string) ($statisticalRow['admin_measure_comment'] ?? '');
        }
    }
}

render_header('Monthly Statistical Review');
?>
<section class="admin-monthly-review-page">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <a href="<?= e(app_url('admin/dashboard.php')) ?>" class="small">&larr; Admin dashboard</a>
            <h1 class="h3 mt-2 mb-1">Monthly Statistical Review</h1>
            <p class="text-muted mb-0">Review each department’s submitted KPI measures, trends, and improvement areas.</p>
        </div>
        <?php if ($selectedReport): ?>
            <button class="btn btn-outline-primary" type="button" onclick="window.print()">Print / Save as PDF</button>
        <?php endif; ?>
    </div>

    <form method="get" class="form-panel row g-3 align-items-end mb-4 review-filter-form">
        <div class="col-md-5">
            <label class="form-label" for="review-department">Department / Unit</label>
            <select class="form-select" id="review-department" name="department_id">
                <option value="0">All departments</option>
                <?php foreach ($departments as $department): ?>
                    <option value="<?= (int) $department['id'] ?>" <?= $departmentId === (int) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="review-month">Month</label>
            <select class="form-select" id="review-month" name="month">
                <option value="0">All months</option>
                <?php for ($monthOption = 1; $monthOption <= 12; $monthOption++): ?>
                    <option value="<?= $monthOption ?>" <?= $month === $monthOption ? 'selected' : '' ?>><?= e(month_label($monthOption)) ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="review-year">Year</label>
            <input class="form-control" id="review-year" type="number" name="year" min="2000" max="2200" value="<?= $year > 0 ? e((string) $year) : '' ?>" placeholder="All">
        </div>
        <div class="col-md-2 d-grid"><button class="btn btn-primary" type="submit">Filter</button></div>
    </form>

    <section class="form-panel mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h2 class="h5 mb-1">Submitted Monthly Reports</h2>
                <p class="small text-muted mb-0">Choose a report to review its statistical measures.</p>
            </div>
            <span class="badge text-bg-light"><?= e((string) count($reports)) ?> reports</span>
        </div>
        <?php if ($reports): ?>
            <div class="table-wrap">
                <table class="table table-striped align-middle mb-0">
                    <thead><tr><th>Period</th><th>Department / HOD</th><th>Measures</th><th>Review status</th><th class="text-end">Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($reports as $report): ?>
                        <?php
                        $reviewStatus = (string) ($report['review_status'] ?? 'pending');
                        $reviewLabel = $reviewStatus === 'returned' ? 'Returned for revision' : ($reviewStatus === 'reviewed' || $report['status'] === 'reviewed' ? 'Reviewed' : 'Pending review');
                        $reviewBadge = $reviewStatus === 'returned' ? 'warning' : ($reviewLabel === 'Reviewed' ? 'success' : 'secondary');
                        ?>
                        <tr>
                            <td><?= e(format_month_year((int) $report['report_month'], (int) $report['report_year'])) ?><br><small class="text-muted"><?= e($report['period_from']) ?> to <?= e($report['period_to']) ?></small></td>
                            <td><strong><?= e($report['department_name']) ?></strong><br><small class="text-muted">HOD: <?= e($report['responsible_unit']) ?></small></td>
                            <td><?= e((string) $report['measure_count']) ?></td>
                            <td><span class="badge text-bg-<?= e($reviewBadge) ?>"><?= e($reviewLabel) ?></span></td>
                            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= e(app_url('admin/monthly_report_review.php?report_id=' . (int) $report['id'] . '&department_id=' . $departmentId . '&month=' . $month . '&year=' . $year)) ?>">Open review</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="text-muted mb-0">No monthly reports match these filters.</p>
        <?php endif; ?>
    </section>

    <?php if ($reportId > 0 && !$selectedReport): ?>
        <div class="alert alert-warning">The selected monthly report could not be found.</div>
    <?php elseif ($selectedReport): ?>
        <section class="monthly-statistical-report form-panel mb-4" aria-labelledby="statistical-report-title">
            <div class="monthly-statistical-report-heading d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
                <div>
                    <p class="small text-uppercase fw-bold mb-1">FNPH Statistical Follow-up Report</p>
                    <h2 class="h4 mb-1" id="statistical-report-title"><?= e(format_month_year((int) $selectedReport['report_month'], (int) $selectedReport['report_year'])) ?> · <?= e($selectedReport['department_name']) ?></h2>
                    <p class="small text-muted mb-0">Submitted <?= e(format_datetime_human($selectedReport['submitted_at'])) ?> · Reporting period <?= e($selectedReport['period_from']) ?> to <?= e($selectedReport['period_to']) ?></p>
                </div>
                <?php
                $reviewStatus = (string) ($selectedReport['review_status'] ?? 'pending');
                $selectedStatusLabel = $reviewStatus === 'returned' ? 'Returned for revision' : ($reviewStatus === 'reviewed' || $selectedReport['status'] === 'reviewed' ? 'Reviewed' : 'Pending review');
                ?>
                <span class="badge text-bg-<?= $reviewStatus === 'returned' ? 'warning' : ($selectedStatusLabel === 'Reviewed' ? 'success' : 'secondary') ?>"><?= e($selectedStatusLabel) ?></span>
            </div>

            <div class="monthly-statistical-summary mb-4" aria-label="Measure status summary">
                <div><span>Total measures</span><strong><?= e((string) $summary['total']) ?></strong></div>
                <div class="is-on-track"><span>On track</span><strong><?= e((string) $summary['on_track']) ?></strong></div>
                <div class="is-at-risk"><span>Near target</span><strong><?= e((string) $summary['at_risk']) ?></strong></div>
                <div class="is-off-track"><span>Below target</span><strong><?= e((string) $summary['off_track']) ?></strong></div>
                <div class="is-improvement"><span>Improvement flags</span><strong><?= e((string) $summary['improvement_required']) ?></strong></div>
            </div>
            <p class="small text-muted mb-3">Generated from submitted KPI measures. Period changes compare achievement with the most recent earlier report containing a matching KRA and KPI.</p>

            <form method="post" action="<?= e(app_url('actions/admin_save_monthly_report_review.php')) ?>" class="monthly-review-form">
                    <input type="hidden" name="report_id" value="<?= (int) $selectedReport['id'] ?>">
                    <input type="hidden" name="department_id" value="<?= $departmentId ?>">
                    <input type="hidden" name="month" value="<?= $month ?>">
                    <input type="hidden" name="year" value="<?= $year ?>">
                    <?php if ($statisticalRows): ?>
                    <div class="table-wrap monthly-statistical-table-wrap mb-4">
                        <table class="table table-bordered align-middle monthly-statistical-table mb-0">
                            <thead><tr><th>S/N</th><th>Subject Matter</th><th>Problem / Statistical Comparison</th><th>Responsible Department</th><th>Responsible Unit</th><th>Improvement Area</th><th>Admin Remark</th></tr></thead>
                            <tbody>
                            <?php foreach ($statisticalRows as $index => $measure): ?>
                                <?php
                                $measureStatusClass = $measure['statistical_status'] === 'off_track' ? 'off_track' : ($measure['statistical_status'] === 'at_risk' ? 'at_risk' : ($measure['statistical_status'] === 'on_track' ? 'on_track' : 'not_available'));
                                ?>
                                <tr>
                                    <td><?= e((string) ($index + 1)) ?></td>
                                    <td><strong><?= e($measure['kpi']) ?></strong><br><small class="monthly-measure-status <?= e($measureStatusClass) ?>"><?= e($measure['finding_label']) ?></small><br><small class="text-muted"><?= e($measure['smart_objective']) ?></small></td>
                                    <td><?= e($measure['statistical_problem']) ?></td>
                                    <td><?= e($measure['department_name']) ?></td>
                                    <td><?= e($measure['responsible_unit'] ?: 'Not specified') ?></td>
                                    <td><?= e($measure['improvement_area']) ?><?php if (($measure['trend'] ?? null) === 'declined'): ?><br><small class="text-danger fw-semibold">Achievement declined <?= e(number_format(abs((float) $measure['change_percentage_points']), 1)) ?> percentage points from the previous report.</small><?php elseif (($measure['trend'] ?? null) === 'improved'): ?><br><small class="text-success fw-semibold">Achievement improved <?= e(number_format((float) $measure['change_percentage_points'], 1)) ?> percentage points from the previous report.</small><?php endif; ?></td>
                                    <td><label class="visually-hidden" for="measure-comment-<?= (int) $measure['id'] ?>">Admin comment on <?= e($measure['kpi']) ?></label><textarea class="form-control monthly-measure-comment-input" id="measure-comment-<?= (int) $measure['id'] ?>" name="measure_comments[<?= (int) $measure['id'] ?>]" rows="3" maxlength="2000" placeholder="Comment on this measure"><?= e($measureComments[(int) $measure['id']] ?? '') ?></textarea><span class="monthly-print-comment"><?= nl2br(e($measureComments[(int) $measure['id']] ?? '')) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                        <div class="alert alert-info mb-4">This is a monthly report submission without KPI measures. You can review the narrative and attached file from the report list, then mark it reviewed or return it with feedback here.</div>
                    <?php endif; ?>

                    <div class="form-panel monthly-review-decision">
                        <label class="form-label" for="overall-review-comment">Overall feedback to the department</label>
                        <textarea class="form-control" id="overall-review-comment" name="overall_comment" rows="4" maxlength="5000" placeholder="Summarize the corrections needed or note the review outcome."></textarea>
                        <p class="small text-muted mt-2 mb-3">Returning the report saves your measure comments, notifies the department in the portal, and reopens this report for correction.</p>
                        <div class="d-flex flex-wrap justify-content-end gap-2">
                            <button class="btn btn-outline-success" type="submit" name="decision" value="reviewed">Save comments and mark reviewed</button>
                            <button class="btn btn-warning" type="submit" name="decision" value="returned">Return to department</button>
                        </div>
                    </div>
            </form>
        </section>

        <?php if ($reviewHistory): ?>
            <section class="form-panel monthly-review-history">
                <h2 class="h5">Review history</h2>
                <div class="vstack gap-3">
                    <?php foreach ($reviewHistory as $review): ?>
                        <article class="monthly-review-history-item">
                            <div class="d-flex flex-wrap justify-content-between gap-2"><strong><?= $review['action'] === 'returned' ? 'Returned for revision' : 'Marked reviewed' ?></strong><small class="text-muted"><?= e($review['admin_name']) ?> · <?= e(format_datetime_human($review['created_at'])) ?></small></div>
                            <?php if (trim((string) $review['comment']) !== ''): ?><p class="mb-0 mt-1"><?= nl2br(e($review['comment'])) ?></p><?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
