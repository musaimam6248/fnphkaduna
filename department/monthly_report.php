<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_login('department');

$departmentId = current_department_id();
$departmentUser = current_user();
if ($departmentId === null) {
    set_flash('error', 'Department account could not be loaded.');
    redirect('logout.php');
}

$pdo = db();
$month = (int) ($_GET['month'] ?? date('n'));
$year = (int) ($_GET['year'] ?? date('Y'));
$pageFile = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'monthly_report.php'));
$viewOnly = (string) ($_GET['view'] ?? '') === '1';
$defaultSubmissionMode = $pageFile === 'monthly_report.php' ? 'monthly_report' : 'kpi';
$submissionMode = (string) ($_GET['mode'] ?? $defaultSubmissionMode);
if (!in_array($submissionMode, ['kpi', 'monthly_report'], true)) {
    $submissionMode = 'kpi';
}
if ($month < 1 || $month > 12 || $year < 2000 || $year > 2200) {
    $month = (int) date('n');
    $year = (int) date('Y');
}
$requestedReportId = (int) ($_GET['report_id'] ?? 0);
$viewDepartmentId = $departmentId;
if ($requestedReportId > 0 && $viewOnly) {
    $periodStmt = $pdo->prepare("SELECT department_id, report_month, report_year FROM department_monthly_report WHERE id = :id AND (department_id = :department_id OR (status IN ('submitted', 'reviewed') AND review_status <> 'returned')) LIMIT 1");
    $periodStmt->execute([':id' => $requestedReportId, ':department_id' => $departmentId]);
    $requestedPeriod = $periodStmt->fetch();
    if ($requestedPeriod) {
        $viewDepartmentId = (int) $requestedPeriod['department_id'];
        $month = (int) $requestedPeriod['report_month'];
        $year = (int) $requestedPeriod['report_year'];
    }
}

$planStmt = $pdo->prepare('SELECT id, period_from, period_to FROM department_annual_plan WHERE department_id = :department_id AND plan_year = :plan_year AND status = \'submitted\' LIMIT 1');
$planStmt->execute([':department_id' => $viewDepartmentId, ':plan_year' => $year]);
$plan = $planStmt->fetch();
$planItems = [];
if ($plan) {
    $planItemsStmt = $pdo->prepare('SELECT id, sort_order, kra, smart_objective, kpi, target, target_value, unit, direction, verification FROM department_annual_plan_item WHERE plan_id = :plan_id ORDER BY sort_order, id');
    $planItemsStmt->execute([':plan_id' => (int) $plan['id']]);
    $planItems = $planItemsStmt->fetchAll();
}

if ($requestedReportId > 0 && $viewOnly) {
    $reportStmt = $pdo->prepare("SELECT * FROM department_monthly_report WHERE id = :id AND (department_id = :department_id OR (status IN ('submitted', 'reviewed') AND review_status <> 'returned')) LIMIT 1");
    $reportStmt->execute([':id' => $requestedReportId, ':department_id' => $departmentId]);
} else {
    $reportStmt = $pdo->prepare('SELECT * FROM department_monthly_report WHERE department_id = :department_id AND report_month = :month AND report_year = :year LIMIT 1');
    $reportStmt->execute([':department_id' => $departmentId, ':month' => $month, ':year' => $year]);
}
$report = $reportStmt->fetch();
$isOwnReport = !$report || (int) $report['department_id'] === $departmentId;
$canEditReport = !$report || ($isOwnReport && !empty($report['edit_approved_at']));
$hasMonthlyNarrative = $report && (trim(strip_tags((string) ($report['narrative_html'] ?? ''))) !== '' || !empty($report['attachment_filename']));
$canEditMonthlyReport = !$report || $canEditReport || ($report['review_status'] ?? '') === 'returned' || !$hasMonthlyNarrative;
$previousMonthDate = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->modify('-1 month');
$previousMonth = (int) $previousMonthDate->format('n');
$previousYear = (int) $previousMonthDate->format('Y');
$previousReportStmt = $pdo->prepare("SELECT id FROM department_monthly_report WHERE department_id = :department_id AND report_month = :month AND report_year = :year AND status IN ('submitted', 'reviewed') AND review_status <> 'returned' LIMIT 1");
$previousReportStmt->execute([':department_id' => $viewDepartmentId, ':month' => $previousMonth, ':year' => $previousYear]);
$previousReportId = (int) ($previousReportStmt->fetchColumn() ?: 0);
$previousUpdatesByPlanItem = [];
$previousUpdatesByKpi = [];
if ($previousReportId > 0) {
    $previousUpdatesStmt = $pdo->prepare('SELECT annual_plan_item_id, kra, kpi, achievement_percent FROM department_monthly_kpi_update WHERE report_id = :report_id');
    $previousUpdatesStmt->execute([':report_id' => $previousReportId]);
    foreach ($previousUpdatesStmt->fetchAll() as $previousUpdate) {
        if (!empty($previousUpdate['annual_plan_item_id'])) {
            $previousUpdatesByPlanItem[(int) $previousUpdate['annual_plan_item_id']] = $previousUpdate;
        }
        $previousKpiKey = mb_strtolower(trim((string) $previousUpdate['kra'])) . '|' . mb_strtolower(trim((string) $previousUpdate['kpi']));
        $previousUpdatesByKpi[$previousKpiKey] = $previousUpdate;
    }
}
$savedUpdates = [];
$adHocUpdates = [];
$latestReportReview = null;
$adminMeasureComments = [];
if ($report) {
    $updatesStmt = $pdo->prepare('SELECT * FROM department_monthly_kpi_update WHERE report_id = :report_id ORDER BY sort_order, id');
    $updatesStmt->execute([':report_id' => (int) $report['id']]);
    foreach ($updatesStmt->fetchAll() as $update) {
        if (!empty($update['annual_plan_item_id'])) {
            $savedUpdates[(int) $update['annual_plan_item_id']] = $update;
        } else {
            $adHocUpdates[] = $update;
        }
    }
    $reviewStmt = $pdo->prepare(
        'SELECT review.kpi_update_id, review.action, review.comment, review.created_at, users.username AS admin_name
         FROM department_monthly_report_review review
         INNER JOIN users ON users.id = review.admin_id
         WHERE review.report_id = :report_id
         ORDER BY review.id DESC'
    );
    $reviewStmt->execute([':report_id' => (int) $report['id']]);
    foreach ($reviewStmt->fetchAll() as $review) {
        if ($review['kpi_update_id'] === null && $latestReportReview === null) {
            $latestReportReview = $review;
        } elseif ($review['kpi_update_id'] !== null && !isset($adminMeasureComments[(int) $review['kpi_update_id']]) && $review['comment'] !== '') {
            $adminMeasureComments[(int) $review['kpi_update_id']] = $review;
        }
    }
}
if (!$planItems && !$report) {
    $adHocUpdates[] = [
        'id' => 0, 'kra' => '', 'smart_objective' => '', 'kpi' => '', 'target' => '',
        'achievement' => '', 'achievement_percent' => '', 'actual_value' => null,
        'variance_value' => null, 'status' => '', 'evidence' => '', 'commentary' => '',
        'next_step' => '', 'achievement_attachment_filename' => null, 'evidence_attachment_filename' => null,
    ];
}
$monthComparisons = [];
if ($report) {
    foreach (array_merge(array_values($savedUpdates), $adHocUpdates) as $currentUpdate) {
        $planItemId = (int) ($currentUpdate['annual_plan_item_id'] ?? 0);
        $kpiKey = mb_strtolower(trim((string) $currentUpdate['kra'])) . '|' . mb_strtolower(trim((string) $currentUpdate['kpi']));
        $previousUpdate = ($planItemId > 0 ? ($previousUpdatesByPlanItem[$planItemId] ?? null) : null) ?? ($previousUpdatesByKpi[$kpiKey] ?? null);
        $currentPercent = is_numeric($currentUpdate['achievement_percent'] ?? null) ? (float) $currentUpdate['achievement_percent'] : null;
        $previousPercent = is_numeric($previousUpdate['achievement_percent'] ?? null) ? (float) $previousUpdate['achievement_percent'] : null;
        $changePoints = $currentPercent !== null && $previousPercent !== null ? $currentPercent - $previousPercent : null;
        $monthComparisons[] = [
            'kpi' => (string) $currentUpdate['kpi'],
            'current_percent' => $currentPercent,
            'previous_percent' => $previousPercent,
            'change_points' => $changePoints,
            'assessment' => $changePoints === null ? 'No comparable previous-month result' : (abs($changePoints) < 0.05 ? 'No change' : ($changePoints > 0 ? 'Improved' : 'Declined')),
        ];
    }
}

$reportsStmt = $pdo->prepare("SELECT r.id, r.report_month, r.report_year, r.status, r.review_status, r.submitted_at, r.attachment_original_name, d.name AS department_name FROM department_monthly_report r INNER JOIN department d ON d.id = r.department_id WHERE r.status IN ('submitted', 'reviewed') AND r.review_status <> 'returned' ORDER BY r.report_year DESC, r.report_month DESC, d.name LIMIT 100");
$reportsStmt->execute();
$reports = $reportsStmt->fetchAll();
$defaultFrom = sprintf('%04d-%02d-01', $year, $month);
$defaultTo = (new DateTimeImmutable($defaultFrom))->modify('last day of this month')->format('Y-m-d');
$trendByItem = [];
if ($planItems) {
    $trendStmt = $pdo->prepare(
        'SELECT u.annual_plan_item_id, r.report_month, r.report_year, u.achievement_percent, u.actual_value
         FROM department_monthly_kpi_update u
         INNER JOIN department_monthly_report r ON r.id = u.report_id
         WHERE r.department_id = :department_id
           AND (r.report_year < :year OR (r.report_year = :year AND r.report_month <= :month))
         ORDER BY r.report_year DESC, r.report_month DESC, u.id DESC'
    );
    $trendStmt->execute([':department_id' => $viewDepartmentId, ':year' => $year, ':month' => $month]);
    foreach ($trendStmt->fetchAll() as $trendRow) {
        $itemId = (int) ($trendRow['annual_plan_item_id'] ?? 0);
        if ($itemId > 0 && count($trendByItem[$itemId] ?? []) < 6) {
            $trendByItem[$itemId][] = $trendRow;
        }
    }
}
render_header($viewOnly ? 'Monthly Report' : 'Monthly KPI');
?>
<section class="department-dashboard-page">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <a href="<?= e(app_url('department/dashboard.php')) ?>" class="small">&larr; Department dashboard</a>
            <h1 class="h3 mt-2 mb-1"><?= $viewOnly ? 'Monthly Report' : 'Monthly KPI' ?></h1>
            <p class="text-muted mb-0"><?= $viewOnly ? 'Review submitted reports and month-to-month KPI improvement.' : 'Enter this month’s KPI targets, achievements, evidence, commentary, and next steps.' ?></p>
        </div>
        <form method="get" class="d-flex flex-wrap align-items-center gap-2">
            <?php if ($viewOnly): ?><input type="hidden" name="view" value="1"><?php endif; ?>
            <label class="visually-hidden" for="report-month">Month</label>
            <select id="report-month" name="month" class="form-select">
                <?php for ($monthOption = 1; $monthOption <= 12; $monthOption++): ?>
                    <option value="<?= $monthOption ?>" <?= $monthOption === $month ? 'selected' : '' ?>><?= e(month_label($monthOption)) ?></option>
                <?php endfor; ?>
            </select>
            <label class="visually-hidden" for="report-year">Year</label>
            <input id="report-year" name="year" type="number" min="2000" max="2200" value="<?= e((string) $year) ?>" class="form-control" style="max-width: 110px">
            <button class="btn btn-outline-primary" type="submit">Open period</button>
        </form>
    </div>

    <?php if (!$planItems && !$viewOnly): ?><div class="alert alert-info">Add KPI details and targets directly in the monthly form below.</div><?php endif; ?>
        <?php if ($viewOnly && !$report): ?><div class="alert alert-info">No report is available for <?= e(format_month_year($month, $year)) ?>. Select a submitted period from the report list below.</div><?php endif; ?>
        <?php if ($report): ?>
            <div class="alert alert-<?= ($report['review_status'] ?? '') === 'returned' ? 'warning' : (!$canEditReport ? ($report['status'] === 'reviewed' ? 'success' : 'info') : 'warning') ?> d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span><?php if (($report['review_status'] ?? '') === 'returned'): ?>An admin returned this report for revision. Update the report and submit it again.<?php else: ?>This monthly KPI report is <?= e($report['status']) ?>. Submitted <?= e(format_datetime_human($report['submitted_at'])) ?>.<?php if (!$canEditReport && $canEditMonthlyReport): ?> KPI entries are locked. You can still submit the monthly narrative or report file.<?php elseif (!$canEditReport && !$canEditMonthlyReport): ?> KPI and monthly report details are locked; ask an admin to approve an edit.<?php elseif (($report['review_status'] ?? '') === 'pending' && $report['status'] === 'submitted'): ?> It will lock after an admin review.<?php endif; ?><?php endif; ?></span>
                <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener" href="<?= e(app_url('exports/print_monthly_report.php?id=' . (int) $report['id'])) ?>">Print report</a>
            </div>
            <?php if ($latestReportReview && trim((string) $latestReportReview['comment']) !== ''): ?>
                <section class="form-panel mb-3 monthly-admin-feedback">
                    <h2 class="h6 mb-1">Latest admin feedback</h2>
                    <p class="mb-1"><?= nl2br(e($latestReportReview['comment'])) ?></p>
                    <small class="text-muted"><?= e($latestReportReview['admin_name']) ?> · <?= e(format_datetime_human($latestReportReview['created_at'])) ?></small>
                </section>
            <?php endif; ?>
            <?php if ($adminMeasureComments): ?>
                <section class="form-panel mb-3 monthly-admin-feedback">
                    <h2 class="h6 mb-2">Admin comments on KPI measures</h2>
                    <div class="vstack gap-2">
                        <?php foreach ($adminMeasureComments as $updateId => $review): ?>
                            <?php
                            $commentedMeasure = null;
                            foreach (array_merge(array_values($savedUpdates), $adHocUpdates) as $candidateMeasure) {
                                if ((int) $candidateMeasure['id'] === $updateId) { $commentedMeasure = $candidateMeasure; break; }
                            }
                            ?>
                            <div class="monthly-admin-measure-comment"><strong><?= e($commentedMeasure['kpi'] ?? 'KPI measure') ?></strong><p class="mb-1"><?= nl2br(e($review['comment'])) ?></p><small class="text-muted"><?= e($review['admin_name']) ?> · <?= e(format_datetime_human($review['created_at'])) ?></small></div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
            <?php if ($monthComparisons): ?>
                <section class="form-panel mb-4 monthly-comparison-panel">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                        <div><h2 class="h5 mb-1">Month-to-month KPI comparison</h2><p class="small text-muted mb-0">Achievement compared with <?= e(format_month_year($previousMonth, $previousYear)) ?>.</p></div>
                        <?php if ($previousReportId < 1): ?><span class="badge text-bg-secondary">No previous month report</span><?php endif; ?>
                    </div>
                    <div class="table-wrap"><table class="table table-sm table-striped align-middle mb-0">
                        <thead><tr><th>KPI</th><th><?= e(month_label($previousMonth)) ?> achievement</th><th><?= e(month_label($month)) ?> achievement</th><th>Change</th><th>Assessment</th></tr></thead>
                        <tbody><?php foreach ($monthComparisons as $comparison): ?>
                            <?php $comparisonClass = $comparison['change_points'] === null ? 'text-muted' : ($comparison['change_points'] > 0.05 ? 'text-success' : ($comparison['change_points'] < -0.05 ? 'text-danger' : 'text-muted')); ?>
                            <tr><td><strong><?= e($comparison['kpi']) ?></strong></td><td><?= $comparison['previous_percent'] !== null ? e(number_format($comparison['previous_percent'], 1) . '%') : '—' ?></td><td><?= $comparison['current_percent'] !== null ? e(number_format($comparison['current_percent'], 1) . '%') : '—' ?></td><td class="<?= e($comparisonClass) ?>"><?= $comparison['change_points'] !== null ? e(($comparison['change_points'] > 0 ? '+' : '') . number_format($comparison['change_points'], 1) . ' pp') : '—' ?></td><td><span class="fw-semibold <?= e($comparisonClass) ?>"><?= e($comparison['assessment']) ?></span></td></tr>
                        <?php endforeach; ?></tbody>
                    </table></div>
                </section>
            <?php endif; ?>
        <?php endif; ?>

        <?php
        ?>
        <?php if ($trendByItem): ?>
            <section class="kpi-trend-panel form-panel mb-4">
                <h2 class="h5">Recent KPI trends</h2>
                <p class="small text-muted">Achievement against target for up to six submitted months.</p>
                <?php foreach ($planItems as $trendItem): ?>
                    <?php $itemTrend = array_reverse($trendByItem[(int) $trendItem['id']] ?? []); if (!$itemTrend) continue; ?>
                    <div class="kpi-trend-row">
                        <div class="kpi-trend-label"><strong><?= e($trendItem['kra']) ?></strong><span><?= e($trendItem['kpi']) ?></span></div>
                        <div class="kpi-trend-bars" role="img" aria-label="Recent achievement trend for <?= e($trendItem['kpi']) ?>">
                            <?php foreach ($itemTrend as $trendPoint): ?>
                                <?php $trendPercent = max(0, min(120, (float) ($trendPoint['achievement_percent'] ?? 0))); $trendClass = $trendPercent >= 100 ? 'on_track' : ($trendPercent >= 80 ? 'at_risk' : 'off_track'); ?>
                                <div class="kpi-trend-point"><span><?= e(number_format((float) ($trendPoint['achievement_percent'] ?? 0), 0)) ?>%</span><div class="kpi-trend-bar <?= $trendClass ?>" style="height: <?= max(5, ($trendPercent / 120) * 100) ?>%"></div><small><?= e(month_label((int) $trendPoint['report_month'])) ?><br><?= e((string) $trendPoint['report_year']) ?></small></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <?php if (!$viewOnly): ?>
        <form method="post" action="<?= e(app_url('actions/department_save_monthly_report.php')) ?>" enctype="multipart/form-data" class="form-panel monthly-kpi-form mb-5">
            <input type="hidden" name="report_month" value="<?= e((string) $month) ?>">
            <input type="hidden" name="report_year" value="<?= e((string) $year) ?>">
            <input type="hidden" name="submission_mode" id="monthly-submission-mode" value="<?= e($submissionMode) ?>">
            <fieldset class="border-0 p-0 m-0">
            <section class="monthly-submission-mode-picker mb-4" aria-labelledby="submission-mode-heading">
                <h2 class="h6 mb-2" id="submission-mode-heading">Choose what to submit</h2>
                <div class="monthly-submission-mode-options">
                    <label class="monthly-submission-mode-option <?= $submissionMode === 'kpi' ? 'is-selected' : '' ?>">
                        <input type="radio" name="submission_mode_choice" value="kpi" <?= $submissionMode === 'kpi' ? 'checked' : '' ?>>
                        <span><strong>KPI only</strong><small>Submit KPI achievements, evidence, and improvement steps.</small></span>
                    </label>
                    <label class="monthly-submission-mode-option <?= $submissionMode === 'monthly_report' ? 'is-selected' : '' ?>">
                        <input type="radio" name="submission_mode_choice" value="monthly_report" <?= $submissionMode === 'monthly_report' ? 'checked' : '' ?>>
                        <span><strong>Monthly report only</strong><small>Submit the unit or department narrative and/or report file.</small></span>
                    </label>
                </div>
            </section>
            <div class="monthly-kpi-meta mb-4">
                <div class="monthly-kpi-unit">
                    <span>Directorate / Department / Unit</span>
                    <strong><?= e($departmentUser['department_name'] ?? 'Department') ?></strong>
                </div>
                <div class="monthly-kpi-period-title">Update period</div>
            <div class="row g-3 mb-4">
                <div class="col-md-6"><label class="form-label" for="period-from">From</label><input class="form-control" id="period-from" name="period_from" type="date" value="<?= e($report['period_from'] ?? $defaultFrom) ?>" required></div>
                <div class="col-md-6"><label class="form-label" for="period-to">To</label><input class="form-control" id="period-to" name="period_to" type="date" value="<?= e($report['period_to'] ?? $defaultTo) ?>" required></div>
            </div>
            </div>

            <fieldset id="monthly-kpi-mode-fields" class="monthly-submission-fields border-0 p-0 m-0" <?= $submissionMode !== 'kpi' || !$canEditReport ? 'hidden disabled' : '' ?>>
            <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                <h2 class="monthly-kpi-updates-heading flex-grow-1 mb-0">Updates</h2>
                <button class="btn btn-outline-primary" id="add-monthly-kpi-row" type="button" <?= !$canEditReport ? 'disabled' : '' ?>>Add KPI row</button>
            </div>
            <p class="small text-muted mb-2">Scroll within the KPI table to view all columns.</p>
            <div class="table-wrap mb-4">
                <table class="table table-bordered align-middle monthly-kpi-table">
                    <thead class="table-light"><tr><th>KRA</th><th>SMART Objectives</th><th>KPI</th><th>Target</th><th>Achievement</th><th>%</th><th>Evidence</th></tr></thead>
                    <tbody id="monthly-kpi-rows">
                    <?php foreach ($planItems as $itemIndex => $item): ?>
                        <?php $update = $savedUpdates[(int) $item['id']] ?? []; ?>
                        <tr>
                            <td><textarea class="form-control" name="kpi_updates[<?= (int) $item['id'] ?>][kra]" rows="2" required aria-label="KRA"><?= e($update['kra'] ?? $item['kra']) ?></textarea></td>
                            <td><textarea class="form-control" name="kpi_updates[<?= (int) $item['id'] ?>][smart_objective]" rows="2" required aria-label="SMART Objectives"><?= e($update['smart_objective'] ?? $item['smart_objective']) ?></textarea></td>
                            <td><textarea class="form-control" name="kpi_updates[<?= (int) $item['id'] ?>][kpi]" rows="2" required aria-label="KPI"><?= e($update['kpi'] ?? $item['kpi']) ?></textarea></td>
                            <td><textarea class="form-control" name="kpi_updates[<?= (int) $item['id'] ?>][target]" rows="2" required aria-label="Target"><?= e($update['target'] ?? $item['target']) ?></textarea><?php if ($item['target_value'] !== null): ?><small class="d-block text-muted">Numeric target: <?= e((string) $item['target_value']) ?> <?= e($item['unit']) ?></small><?php endif; ?></td>
                            <td><?php if ($item['target_value'] !== null): ?><input class="form-control kpi-actual-input" type="number" step="any" data-target="<?= e((string) $item['target_value']) ?>" data-direction="<?= e($item['direction']) ?>" name="kpi_updates[<?= (int) $item['id'] ?>][actual_value]" value="<?= e(isset($update['actual_value']) ? (string) $update['actual_value'] : '') ?>" required aria-label="Achievement"><small><?= e($item['unit']) ?></small><?php else: ?><textarea class="form-control" name="kpi_updates[<?= (int) $item['id'] ?>][achievement]" rows="2" placeholder="Enter this month’s achievement" required><?= e($update['achievement'] ?? '') ?></textarea><?php endif; ?>
                                <input class="form-control form-control-sm mt-2" type="file" name="achievement_files[<?= (int) $item['id'] ?>]" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.webp" aria-label="Attach achievement file">
                                <?php if (!empty($update['achievement_attachment_filename'])): ?><small class="d-block mt-1">Current: <a target="_blank" rel="noopener" href="<?= e(app_url('exports/download_monthly_kpi_attachment.php?id=' . (int) $update['id'] . '&kind=achievement')) ?>"><?= e($update['achievement_attachment_original_name'] ?? 'Achievement attachment') ?></a></small><?php endif; ?>
                            </td>
                            <td><?php if ($item['target_value'] !== null): ?><input class="form-control kpi-percent-input" type="number" min="0" max="1000" step="0.1" name="kpi_updates[<?= (int) $item['id'] ?>][percentage]" value="<?= e(isset($update['achievement_percent']) ? (string) $update['achievement_percent'] : '') ?>" readonly aria-label="Achievement percentage"><?php else: ?><input class="form-control" type="number" min="0" max="1000" step="0.1" name="kpi_updates[<?= (int) $item['id'] ?>][percentage]" value="<?= e(isset($update['achievement_percent']) ? (string) $update['achievement_percent'] : '') ?>" required aria-label="Achievement percentage"><?php endif; ?></td>
                            <td><textarea class="form-control" name="kpi_updates[<?= (int) $item['id'] ?>][evidence]" rows="2" placeholder="Evidence or reference"><?= e($update['evidence'] ?? '') ?></textarea><input class="form-control form-control-sm mt-2" type="file" name="evidence_files[<?= (int) $item['id'] ?>]" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.webp" aria-label="Attach evidence file"><?php if (!empty($update['evidence_attachment_filename'])): ?><small class="d-block mt-1">Current: <a target="_blank" rel="noopener" href="<?= e(app_url('exports/download_monthly_kpi_attachment.php?id=' . (int) $update['id'] . '&kind=evidence')) ?>"><?= e($update['evidence_attachment_original_name'] ?? 'Evidence attachment') ?></a></small><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php foreach ($adHocUpdates as $adhocIndex => $update): ?>
                        <?php $rowKey = 'new_saved_' . (int) $update['id']; ?>
                        <tr class="dynamic-kpi-row">
                            <td><textarea class="form-control" name="kpi_updates[<?= e($rowKey) ?>][kra]" rows="2" required aria-label="KRA"><?= e($update['kra']) ?></textarea></td>
                            <td><textarea class="form-control" name="kpi_updates[<?= e($rowKey) ?>][smart_objective]" rows="2" required aria-label="SMART Objectives"><?= e($update['smart_objective']) ?></textarea></td>
                            <td><textarea class="form-control" name="kpi_updates[<?= e($rowKey) ?>][kpi]" rows="2" required aria-label="KPI"><?= e($update['kpi']) ?></textarea></td>
                            <td><textarea class="form-control" name="kpi_updates[<?= e($rowKey) ?>][target]" rows="2" required aria-label="Target"><?= e($update['target']) ?></textarea></td>
                            <td><textarea class="form-control" name="kpi_updates[<?= e($rowKey) ?>][achievement]" rows="2" required aria-label="Achievement"><?= e($update['achievement']) ?></textarea><input class="form-control form-control-sm mt-2" type="file" name="achievement_files[<?= e($rowKey) ?>]" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.webp" aria-label="Attach achievement file"><?php if (!empty($update['achievement_attachment_filename'])): ?><small class="d-block mt-1">Current: <a target="_blank" rel="noopener" href="<?= e(app_url('exports/download_monthly_kpi_attachment.php?id=' . (int) $update['id'] . '&kind=achievement')) ?>"><?= e($update['achievement_attachment_original_name'] ?? 'Achievement attachment') ?></a></small><?php endif; ?></td>
                            <td><input class="form-control" type="number" min="0" max="1000" step="0.1" name="kpi_updates[<?= e($rowKey) ?>][percentage]" value="<?= e((string) $update['achievement_percent']) ?>" required aria-label="Achievement percentage"></td>
                            <td><textarea class="form-control" name="kpi_updates[<?= e($rowKey) ?>][evidence]" rows="2" aria-label="Evidence"><?= e($update['evidence']) ?></textarea><input class="form-control form-control-sm mt-2" type="file" name="evidence_files[<?= e($rowKey) ?>]" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.webp" aria-label="Attach evidence file"><?php if (!empty($update['evidence_attachment_filename'])): ?><small class="d-block mt-1">Current: <a target="_blank" rel="noopener" href="<?= e(app_url('exports/download_monthly_kpi_attachment.php?id=' . (int) $update['id'] . '&kind=evidence')) ?>"><?= e($update['evidence_attachment_original_name'] ?? 'Evidence attachment') ?></a></small><?php endif; ?><button class="btn btn-sm btn-outline-danger remove-monthly-kpi-row mt-2" type="button" aria-label="Remove KPI row" title="Remove KPI row">×</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            </fieldset>

            <fieldset id="monthly-report-mode-fields" class="monthly-submission-fields border-0 p-0 m-0" <?= $submissionMode !== 'monthly_report' || !$canEditMonthlyReport ? 'hidden disabled' : '' ?>>
            <section class="form-panel mb-4">
                <h2 class="h5">Unit / Department Report</h2>
                <label class="form-label" for="monthly-report-narrative">Type the report for your unit or department</label>
                <textarea class="form-control" id="monthly-report-narrative" name="narrative_html" rows="6" placeholder="Summarize the unit’s work and monthly results."><?= e(trim(strip_tags((string) ($report['narrative_html'] ?? '')))) ?></textarea>
                <label class="form-label mt-3" for="monthly-report-file">Or attach a report file from your computer</label>
                <input class="form-control" id="monthly-report-file" type="file" name="report_file" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.webp">
                <div class="form-text">Attach one PDF, Word, Excel, PowerPoint, or image file, up to 50 MB.
                    <?php if ($report && !empty($report['attachment_filename'])): ?> Current file: <a target="_blank" rel="noopener" href="<?= e(app_url('exports/download_department_attachment.php?id=' . (int) $report['id'])) ?>"><?= e($report['attachment_original_name'] ?? 'Download attachment') ?></a>.<?php endif; ?>
                </div>
            </section>
            </fieldset>

            <div class="d-flex justify-content-end"><button id="monthly-submit-button" class="btn btn-primary" type="submit" <?= ($submissionMode === 'kpi' && !$canEditReport) || ($submissionMode === 'monthly_report' && !$canEditMonthlyReport) ? 'disabled' : '' ?>><?= $submissionMode === 'kpi' ? 'Submit KPI only' : 'Submit monthly report only' ?></button></div>
        </form>
        <?php endif; ?>
    <section class="form-panel">
        <h2 class="h5">Submitted Monthly Reports from All Units / Departments</h2>
        <?php if ($reports): ?>
            <div class="table-wrap"><table class="table table-striped align-middle mb-0"><thead><tr><th>Unit / Department</th><th>Period</th><th>Status</th><th>Submitted</th><th>Attachment</th><th></th></tr></thead><tbody>
            <?php foreach ($reports as $savedReport): ?>
                <tr>
                    <td><?= e($savedReport['department_name']) ?></td>
                    <td><?= e(format_month_year((int) $savedReport['report_month'], (int) $savedReport['report_year'])) ?></td>
                    <td><?= e(ucfirst(($savedReport['review_status'] ?? '') === 'returned' ? 'returned for revision' : $savedReport['status'])) ?></td>
                    <td><?= e(format_datetime_human($savedReport['submitted_at'])) ?></td>
                    <td><?= !empty($savedReport['attachment_filename']) ? e($savedReport['attachment_original_name'] ?? 'Attached file') : '—' ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= e(app_url('department/monthly_report.php?report_id=' . (int) $savedReport['id'] . '&view=1')) ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php else: ?>
            <p class="text-muted mb-0">No monthly reports submitted yet.</p>
        <?php endif; ?>
    </section>
</section>
<template id="monthly-kpi-row-template">
    <tr class="dynamic-kpi-row">
        <td><textarea class="form-control" name="kpi_updates[new___INDEX__][kra]" rows="2" required aria-label="KRA"></textarea></td>
        <td><textarea class="form-control" name="kpi_updates[new___INDEX__][smart_objective]" rows="2" required aria-label="SMART Objectives"></textarea></td>
        <td><textarea class="form-control" name="kpi_updates[new___INDEX__][kpi]" rows="2" required aria-label="KPI"></textarea></td>
        <td><textarea class="form-control" name="kpi_updates[new___INDEX__][target]" rows="2" required aria-label="Target"></textarea></td>
        <td><textarea class="form-control" name="kpi_updates[new___INDEX__][achievement]" rows="2" required aria-label="Achievement"></textarea><input class="form-control form-control-sm mt-2" type="file" name="achievement_files[new___INDEX__]" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.webp" aria-label="Attach achievement file"></td>
        <td><input class="form-control" type="number" min="0" max="1000" step="0.1" name="kpi_updates[new___INDEX__][percentage]" required aria-label="Achievement percentage"></td>
        <td><textarea class="form-control" name="kpi_updates[new___INDEX__][evidence]" rows="2" aria-label="Evidence"></textarea><input class="form-control form-control-sm mt-2" type="file" name="evidence_files[new___INDEX__]" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.webp" aria-label="Attach evidence file"><button class="btn btn-sm btn-outline-danger remove-monthly-kpi-row mt-2" type="button" aria-label="Remove KPI row" title="Remove KPI row">×</button></td>
    </tr>
</template>
<script>
(() => {
    const rows = document.getElementById('monthly-kpi-rows');
    const template = document.getElementById('monthly-kpi-row-template');
    let nextIndex = 0;
    document.getElementById('add-monthly-kpi-row')?.addEventListener('click', () => {
        if (!rows || !template) return;
        const rowMarkup = template.innerHTML.replaceAll('__INDEX__', String(nextIndex++));
        rows.insertAdjacentHTML('beforeend', rowMarkup);
        rows.lastElementChild?.querySelector('textarea')?.focus();
    });
    rows?.addEventListener('click', (event) => {
        const removeButton = event.target.closest('.remove-monthly-kpi-row');
        if (removeButton) removeButton.closest('.dynamic-kpi-row')?.remove();
    });
})();
const monthlyKpiRows = document.getElementById('monthly-kpi-rows');
const updateAchievementPercent = (row) => {
    const actualInput = row?.querySelector('.kpi-actual-input');
    const percentageInput = row?.querySelector('.kpi-percent-input');
    if (!actualInput || !percentageInput || actualInput.value === '') return;
    const actualValue = Number(actualInput.value);
    const targetValue = Number(actualInput.dataset.target);
    const percentage = actualInput.dataset.direction === 'lower'
        ? (actualValue === 0 ? (targetValue === 0 ? 100 : 1000) : targetValue / actualValue * 100)
        : (targetValue === 0 ? (actualValue >= 0 ? 100 : 0) : actualValue / targetValue * 100);
    percentageInput.value = Number.isFinite(percentage) ? Math.min(1000, Math.max(0, percentage)).toFixed(1) : '';
};
monthlyKpiRows?.addEventListener('input', (event) => updateAchievementPercent(event.target.closest('tr')));
monthlyKpiRows?.querySelectorAll('tr').forEach(updateAchievementPercent);
</script>
<script>
(() => {
    const modeInput = document.getElementById('monthly-submission-mode');
    const modeRadios = document.querySelectorAll('input[name="submission_mode_choice"]');
    const kpiFields = document.getElementById('monthly-kpi-mode-fields');
    const reportFields = document.getElementById('monthly-report-mode-fields');
    const submitButton = document.getElementById('monthly-submit-button');
    const canEditKpis = <?= $canEditReport ? 'true' : 'false' ?>;
    const canEditMonthlyReport = <?= $canEditMonthlyReport ? 'true' : 'false' ?>;
    if (!modeInput || !kpiFields || !reportFields) return;
    const setMode = (mode) => {
        const isKpi = mode === 'kpi';
        modeInput.value = isKpi ? 'kpi' : 'monthly_report';
        kpiFields.hidden = !isKpi;
        kpiFields.disabled = !isKpi || !canEditKpis;
        reportFields.hidden = isKpi;
        reportFields.disabled = isKpi || !canEditMonthlyReport;
        document.querySelectorAll('.monthly-submission-mode-option').forEach((option) => {
            option.classList.toggle('is-selected', option.querySelector('input')?.value === mode);
        });
        if (submitButton) {
            submitButton.textContent = isKpi ? 'Submit KPI only' : 'Submit monthly report only';
            submitButton.disabled = isKpi ? !canEditKpis : !canEditMonthlyReport;
        }
    };
    modeRadios.forEach((radio) => radio.addEventListener('change', () => setMode(radio.value)));
    setMode(modeInput.value);
})();
</script>
<?php render_footer(); ?>
