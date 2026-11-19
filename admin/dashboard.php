<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';

require_login('admin');

$pdo = db();
$departmentFilter = (int) ($_GET['department_id'] ?? 0);
$monthFilter = (int) ($_GET['month'] ?? 0);
$yearFilter = (int) ($_GET['year'] ?? 0);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;

$where = [];
$params = [];

if ($departmentFilter > 0) {
    $where[] = 's.department_id = :department_id';
    $params[':department_id'] = $departmentFilter;
}
if ($monthFilter >= 1 && $monthFilter <= 12) {
    $where[] = 's.month = :month';
    $params[':month'] = $monthFilter;
}
if ($yearFilter >= 2000) {
    $where[] = 's.year = :year';
    $params[':year'] = $yearFilter;
}

$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

$countStmt = $pdo->prepare(
    "SELECT COUNT(*) AS total_rows
     FROM submission s
     INNER JOIN department d ON d.id = s.department_id
     INNER JOIN staff st ON st.id = s.staff_id
     {$whereSql}"
);
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$pagination = paginate($totalRows, $perPage, $page);

$submissionStmt = $pdo->prepare(
    "SELECT s.id, s.month, s.year, s.reason_one, s.reason_two, s.score, s.final_remarks, s.created_at,
            d.name AS department_name, st.name AS staff_name, st.rank AS staff_rank, st.photo AS staff_photo,
            COALESCE(SUM(CASE WHEN pe.category = 'leadership' THEN pe.score ELSE 0 END), 0) AS cat_leadership,
            COALESCE(SUM(CASE WHEN pe.category = 'positive_attitude' THEN pe.score ELSE 0 END), 0) AS cat_positive_attitude,
            COALESCE(SUM(CASE WHEN pe.category = 'innovation' THEN pe.score ELSE 0 END), 0) AS cat_innovation,
            COALESCE(SUM(CASE WHEN pe.category = 'interpersonal_skills' THEN pe.score ELSE 0 END), 0) AS cat_interpersonal_skills,
            COALESCE(SUM(CASE WHEN pe.category = 'willingness_to_learn' THEN pe.score ELSE 0 END), 0) AS cat_willingness_to_learn,
            COALESCE(SUM(CASE WHEN pe.category = 'productivity' THEN pe.score ELSE 0 END), 0) AS cat_productivity,
            COALESCE(SUM(CASE WHEN pe.category = 'punctuality' THEN pe.score ELSE 0 END), 0) AS cat_punctuality
     FROM submission s
     INNER JOIN department d ON d.id = s.department_id
     INNER JOIN staff st ON st.id = s.staff_id
     LEFT JOIN performance_evaluation pe ON pe.submission_id = s.id
     {$whereSql}
     GROUP BY s.id
     ORDER BY s.score DESC, s.year DESC, s.month DESC, s.created_at DESC
     LIMIT :limit OFFSET :offset"
);

foreach ($params as $key => $value) {
    $submissionStmt->bindValue($key, $value);
}
$submissionStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$submissionStmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$submissionStmt->execute();
$submissions = $submissionStmt->fetchAll();

$submissionIds = array_map(static fn($sub) => (int) $sub['id'], $submissions);
$evalDetailsBySubmission = [];
if (!empty($submissionIds)) {
    $inClause = implode(',', $submissionIds);
    $evalDetailsStmt = $pdo->query(
        "SELECT submission_id, category, criterion_key, criterion_label, score
         FROM performance_evaluation
         WHERE submission_id IN ({$inClause})
         ORDER BY id ASC"
    );
    foreach ($evalDetailsStmt->fetchAll() as $evalDetail) {
        $evalDetailsBySubmission[(int)$evalDetail['submission_id']][] = $evalDetail;
    }
}

$departments = $pdo->query('SELECT id, name, hod FROM department ORDER BY name')->fetchAll();
$winnerCandidates = $pdo->query(
    "SELECT s.id, s.month, s.year, d.name AS department_name, st.name AS staff_name
     FROM submission s
     INNER JOIN department d ON d.id = s.department_id
     INNER JOIN staff st ON st.id = s.staff_id
     ORDER BY s.year DESC, s.month DESC, s.created_at DESC
     LIMIT 100"
)->fetchAll();

$committeeMembers = $pdo->query('SELECT id, name, role, photo FROM committee ORDER BY created_at DESC')->fetchAll();
$galleryImages = $pdo->query('SELECT id, image, title, uploaded_at FROM gallery ORDER BY uploaded_at DESC LIMIT 50')->fetchAll();
$eventVideos = $pdo->query('SELECT id, video, title, uploaded_at FROM event_video ORDER BY uploaded_at DESC LIMIT 24')->fetchAll();
$departmentInfo = $pdo->query('SELECT id, vision, mission, objectives, image FROM department_info ORDER BY updated_at DESC LIMIT 1')->fetch();
$homepageSettings = $pdo->query('SELECT app_background_color, homepage_background_color, school_name, school_badge FROM homepage_settings WHERE id = 1 LIMIT 1')->fetch() ?: [];
$homepageBackgroundImages = $pdo->query('SELECT id, image FROM homepage_background_image ORDER BY sort_order ASC, id ASC LIMIT 10')->fetchAll();
$submissionSchedule = $pdo->query(
    'SELECT id, opens_at, closes_at, is_enabled, note, updated_at FROM submission_schedule WHERE id = 1 LIMIT 1'
)->fetch();
$scheduleEnabled = $submissionSchedule ? ((int) $submissionSchedule['is_enabled'] === 1) : false;
$scheduleState = get_submission_window_state(
    $submissionSchedule['opens_at'] ?? null,
    $submissionSchedule['closes_at'] ?? null,
    $scheduleEnabled
);

$totalDepartments = (int) $pdo->query('SELECT COUNT(*) FROM department')->fetchColumn();
$totalStaff = (int) $pdo->query('SELECT COUNT(*) FROM staff')->fetchColumn();
$totalSubmissions = (int) $pdo->query('SELECT COUNT(*) FROM submission')->fetchColumn();
$totalWinners = (int) $pdo->query('SELECT COUNT(*) FROM winner')->fetchColumn();
$currentMonth = (int) date('n');
$currentYear = (int) date('Y');
$currentPeriodStmt = $pdo->prepare('SELECT COUNT(*) FROM submission WHERE month = :month AND year = :year');
$currentPeriodStmt->execute([':month' => $currentMonth, ':year' => $currentYear]);
$currentPeriodSubmissions = (int) $currentPeriodStmt->fetchColumn();

$deptEvalRows = $pdo->query(
    "SELECT d.name AS department_name, pe.category, pe.criterion_label, COUNT(pe.id) AS count, SUM(pe.score) AS total_score, ROUND(AVG(pe.score), 1) AS avg_score
     FROM performance_evaluation pe
     INNER JOIN submission s ON s.id = pe.submission_id
     INNER JOIN department d ON d.id = s.department_id
     GROUP BY d.name, pe.category, pe.criterion_label
     ORDER BY d.name, pe.category, pe.criterion_label"
)->fetchAll();

$categoryTotals = $pdo->query(
    "SELECT pe.category, SUM(pe.score) AS total_score, COUNT(pe.id) AS count, ROUND(AVG(pe.score), 1) AS avg_score
     FROM performance_evaluation pe
     GROUP BY pe.category
     ORDER BY pe.category"
)->fetchAll();

$totalEvalCount = 0;
$totalEvalScore = 0;
foreach ($categoryTotals as $catRow) {
    $totalEvalCount += (int) $catRow['count'];
    $totalEvalScore += (int) $catRow['total_score'];
}

$availableMonthsQuery = $pdo->query("SELECT DISTINCT month, year FROM submission WHERE id IN (SELECT submission_id FROM performance_evaluation) ORDER BY year DESC, month DESC")->fetchAll();

$filterMonth = $_GET['eval_month'] ?? null;
$filterYear = $_GET['eval_year'] ?? null;

if (!$filterMonth || !$filterYear) {
    if (count($availableMonthsQuery) > 0) {
        $filterMonth = $availableMonthsQuery[0]['month'];
        $filterYear = $availableMonthsQuery[0]['year'];
    }
}

$whereClause = "";
$params = [];
if ($filterMonth && $filterYear) {
    $whereClause = "WHERE s.month = :month AND s.year = :year";
    $params['month'] = $filterMonth;
    $params['year'] = $filterYear;
}

$staffEvalQuery = "
    SELECT s.id AS submission_id, st.name AS staff_name, st.rank AS staff_rank, st.photo AS staff_photo,
            d.name AS department_name, s.month, s.year, s.final_remarks,
            SUM(CASE WHEN pe.category = 'leadership' THEN pe.score ELSE 0 END) AS leadership_total,
            SUM(CASE WHEN pe.category = 'positive_attitude' THEN pe.score ELSE 0 END) AS positive_attitude_total,
            SUM(CASE WHEN pe.category = 'innovation' THEN pe.score ELSE 0 END) AS innovation_total,
            SUM(CASE WHEN pe.category = 'interpersonal_skills' THEN pe.score ELSE 0 END) AS interpersonal_skills_total,
            SUM(CASE WHEN pe.category = 'willingness_to_learn' THEN pe.score ELSE 0 END) AS willingness_to_learn_total,
            SUM(CASE WHEN pe.category = 'productivity' THEN pe.score ELSE 0 END) AS productivity_total,
            SUM(CASE WHEN pe.category = 'punctuality' THEN pe.score ELSE 0 END) AS punctuality_total,
            SUM(pe.score) AS grand_total,
            COUNT(pe.id) AS criteria_count
     FROM performance_evaluation pe
     INNER JOIN submission s ON s.id = pe.submission_id
     INNER JOIN staff st ON st.id = s.staff_id
     INNER JOIN department d ON d.id = s.department_id
     $whereClause
     GROUP BY s.id, st.name, st.rank, st.photo, d.name, s.month, s.year, s.final_remarks
     ORDER BY grand_total DESC
";
$stmt = $pdo->prepare($staffEvalQuery);
$stmt->execute($params);
$staffEvalRows = $stmt->fetchAll();

$savedReports = $pdo->query("SELECT id, month, year, format, file_path, created_at FROM saved_report ORDER BY created_at DESC")->fetchAll();

render_header('Admin Dashboard');
?>
<div class="admin-dashboard-modern">
    <main class="admin-content">
        <div class="admin-status-strip mb-4"><span class="admin-status-dot" aria-hidden="true"></span> Federal Neuro-Psychiatric Hospital, Kaduna <span aria-hidden="true">·</span> Staff Excellence Portal <span aria-hidden="true">·</span> Nomination Window: <strong><?= e(strtoupper(get_submission_window_label($scheduleState))) ?></strong></div>

        <section class="admin-dashboard-hero mb-4 fade-rise" id="admin-overview">
            <div>
                <p class="admin-dashboard-kicker mb-1">Hospital Administration <span aria-hidden="true">·</span> Super Administrator Console</p>
                <h1 class="h2 mb-1">Staff Excellence Management Dashboard</h1>
                <p class="text-muted mb-0">Federal Neuro-Psychiatric Hospital, Kaduna · Central award grading, window governance, and department reporting.</p>
            </div>
            <div class="admin-dashboard-actions">
                <a class="btn btn-primary" href="<?= e(app_url('admin/monthly_kpi_reports.php')) ?>"><span aria-hidden="true">▤</span> Monthly KPI Submissions</a>
                <a class="btn btn-outline-primary" href="<?= e(app_url('admin/monthly_reports.php')) ?>"><span aria-hidden="true">▧</span> Monthly Reports</a>
                <a class="btn btn-outline-primary" href="<?= e(app_url('exports/report.php?format=csv')) ?>"><span aria-hidden="true">▤</span> Export CSV</a>
                <a class="btn btn-outline-secondary" href="<?= e(app_url('exports/print_report.php')) ?>" target="_blank" rel="noopener"><span aria-hidden="true">▣</span> Print Dossier</a>
            </div>
        </section>

        <section class="row g-3 mb-4 fade-rise admin-metric-summary" aria-label="Portal summary">
            <div class="col-sm-6 col-xl-3">
                <div class="metric-card card admin-summary-card">
                    <div class="card-body">
                        <div><p class="mb-2 admin-summary-label">Total Departments</p><p class="metric-number mb-0"><?= e((string) $totalDepartments) ?></p><small>Active hospital units</small></div>
                        <span class="admin-summary-icon" aria-hidden="true">▦</span>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="metric-card card admin-summary-card">
                    <div class="card-body">
                        <div><p class="mb-2 admin-summary-label">Total Submissions</p><p class="metric-number mb-0"><?= e((string) $totalSubmissions) ?></p><small>Staff nominees on record</small></div>
                        <span class="admin-summary-icon" aria-hidden="true">♙</span>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="metric-card card admin-summary-card">
                    <div class="card-body">
                        <div><p class="mb-2 admin-summary-label">Current Period Submissions</p><p class="metric-number mb-0"><?= e((string) $currentPeriodSubmissions) ?></p><small><?= e(month_label($currentMonth)) ?> <?= e((string) $currentYear) ?> entries</small></div>
                        <span class="admin-summary-icon" aria-hidden="true">♧</span>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="metric-card card admin-summary-card admin-window-summary">
                    <div class="card-body">
                        <div><p class="mb-2 admin-summary-label">Submission Window</p><p class="metric-number mb-0"><?= e(strtoupper(get_submission_window_label($scheduleState))) ?></p><small><?= !empty($submissionSchedule['closes_at']) ? 'Closes ' . e(date('m/d/Y', strtotime((string) $submissionSchedule['closes_at']))) : 'Schedule not set' ?></small></div>
                        <span class="admin-summary-icon" aria-hidden="true">◷</span>
                    </div>
                </div>
            </div>
        </section>

        <nav class="admin-workspace-tabs mb-4" aria-label="Admin management sections">
            <a class="is-active" href="#admin-evaluation"><span aria-hidden="true">♙</span> Review Nominations (<?= e((string) $totalRows) ?>)</a>
            <a href="#admin-submission-window"><span aria-hidden="true">◷</span> Window Control</a>
            <a href="#admin-winner"><span aria-hidden="true">✧</span> Hall of Laureates (<?= e((string) $totalWinners) ?>)</a>
            <a href="#admin-committee"><span aria-hidden="true">♧</span> Committee (<?= e((string) count($committeeMembers)) ?>)</a>
            <a href="#admin-notifications"><span aria-hidden="true">➤</span> Broadcast Notice</a>
            <a href="#admin-dept-info"><span aria-hidden="true">◎</span> Hospital Info</a>
        </nav>

        <nav class="admin-report-shortcuts mb-4" aria-label="Reporting and portal tools">
            <a href="<?= e(app_url('admin/monthly_report_analytics.php')) ?>">Monthly comparison analytics</a>
            <a href="<?= e(app_url('admin/monthly_kpi_reports.php')) ?>">Monthly KPI submissions</a>
            <a href="<?= e(app_url('admin/monthly_reports.php')) ?>">Monthly department reports</a>
            <a href="<?= e(app_url('admin/monthly_report_review.php')) ?>">Statistical review</a>
            <a href="#admin-saved-reports">Saved reports</a>
            <a href="#admin-reports">Reports and exports</a>
            <a href="#admin-gallery">Gallery</a>
            <a href="#admin-videos">Event videos</a>
            <a href="#admin-school-branding">School branding</a>
            <a href="#admin-homepage-background">Homepage background</a>
            <a href="<?= e(app_url('admin/departments.php')) ?>">Manage departments</a>
        </nav>

        <section class="row g-4 mb-4 fade-rise" id="admin-evaluation">
            <div class="col-12">
                <div class="form-panel h-100">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h2 class="h5 mb-0">Staff Evaluation Scores</h2>
                        <?php if ($availableMonthsQuery): ?>
                        <form method="get" action="dashboard.php" class="d-flex gap-2">
                            <select name="eval_month" class="form-select form-select-sm" required>
                                <?php foreach (range(1, 12) as $m): ?>
                                    <option value="<?= $m ?>" <?= ((int)$filterMonth === $m) ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 10)) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="eval_year" class="form-select form-select-sm" required>
                                <?php
                                $years = array_unique(array_column($availableMonthsQuery, 'year'));
                                foreach ($years as $y): ?>
                                    <option value="<?= e((string)$y) ?>" <?= ((int)$filterYear === (int)$y) ? 'selected' : '' ?>><?= e((string)$y) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-sm btn-primary">Filter</button>
                            <a href="dashboard.php#admin-evaluation" class="btn btn-sm btn-outline-secondary">Clear</a>
                        </form>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($availableMonthsQuery && $filterMonth && $filterYear): ?>
                    <div class="mb-3 d-flex gap-2 justify-content-end">
                        <a href="<?= e(app_url("exports/staff_evaluation_report.php?format=csv&month={$filterMonth}&year={$filterYear}")) ?>" class="btn btn-sm btn-outline-success">Save CSV</a>
                        <a href="<?= e(app_url("exports/staff_evaluation_report.php?format=pdf&month={$filterMonth}&year={$filterYear}")) ?>" class="btn btn-sm btn-outline-danger">Save PDF</a>
                        <a href="<?= e(app_url("exports/print_staff_evaluation.php?month={$filterMonth}&year={$filterYear}")) ?>" target="_blank" class="btn btn-sm btn-outline-secondary">Preview report</a>
                    </div>
                    <?php endif; ?>

                    <?php if ($staffEvalRows): ?>
                    <div class="table-wrap">
                        <table class="table table-sm table-striped align-middle mb-0">
                            <thead>
                            <tr>
                                <th>#</th>
                                <th style="width: 50px;" class="text-center">Photo</th>
                                <th>Month/Year</th>
                                <th>Department</th>
                                <th>Name</th>
                                <th class="text-end">Lead (/25)</th>
                                <th class="text-end">Attitude (/20)</th>
                                <th class="text-end">Innov (/15)</th>
                                <th class="text-end">Interpers (/20)</th>
                                <th class="text-end">Learn (/15)</th>
                                <th class="text-end">Productivity (/30)</th>
                                <th class="text-end">Punctual (/10)</th>
                                <th class="text-end fw-bold">Grand Total</th>
                                <th class="text-end">Average</th>
                                <th>HOD Final Remarks</th>
                                <th class="text-end">Action</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($staffEvalRows as $index => $row): ?>
                                <?php
                                $criteriaCount = (int) $row['criteria_count'];
                                $avgScore = $criteriaCount > 0 ? round($row['grand_total'] / $criteriaCount, 1) : 0;
                                $monthYear = format_month_year((int) $row['month'], (int) $row['year']); ?>
                                <tr>
                                    <td><?= e((string) ($index + 1)) ?></td>
                                    <td class="text-center">
                                        <img src="<?= e(upload_url('staff', $row['staff_photo'])) ?>" alt="<?= e($row['staff_name']) ?>" style="width: 100px; height: 100px; object-fit: cover; border-radius: 6px; border: 1px solid rgba(29, 107, 56, 0.2); display: inline-block;">
                                    </td>
                                    <td><?= e($monthYear) ?></td>
                                    <td><?= e($row['department_name']) ?></td>
                                    <td><strong><?= e($row['staff_name']) ?></strong><br><small class="text-muted">(<?= e($row['staff_rank']) ?>)</small></td>
                                    <td class="text-end"><?= e((string) $row['leadership_total']) ?></td>
                                    <td class="text-end"><?= e((string) $row['positive_attitude_total']) ?></td>
                                    <td class="text-end"><?= e((string) $row['innovation_total']) ?></td>
                                    <td class="text-end"><?= e((string) $row['interpersonal_skills_total']) ?></td>
                                    <td class="text-end"><?= e((string) $row['willingness_to_learn_total']) ?></td>
                                    <td class="text-end"><?= e((string) $row['productivity_total']) ?></td>
                                    <td class="text-end"><?= e((string) $row['punctuality_total']) ?></td>
                                    <td class="text-end fw-bold"><?= e((string) $row['grand_total']) ?></td>
                                    <td class="text-end"><?= e((string) $avgScore) ?></td>
                                    <td class="small"><?= e($row['final_remarks'] ?? '') ?></td>
                                    <td class="text-end"><a class="btn btn-outline-primary btn-sm" href="<?= e(app_url('admin/detail.php?submission_id=' . $row['submission_id'])) ?>">View</a></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <p class="text-muted mb-0">No staff evaluation data recorded yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="row g-4 mb-4 fade-rise" id="admin-saved-reports">
            <div class="col-12">
                <div class="form-panel">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h2 class="h5 mb-0">Saved Reports</h2>
                    </div>
                    <?php if ($savedReports): ?>
                    <div class="table-wrap">
                        <table class="table table-sm table-striped align-middle mb-0">
                            <thead>
                            <tr>
                                <th>Report Month/Year</th>
                                <th>Format</th>
                                <th>Date Generated</th>
                                <th class="text-end">Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($savedReports as $report): ?>
                                <?php $rMonthName = date('F', mktime(0, 0, 0, (int)$report['month'], 10)); ?>
                                <tr>
                                    <td><?= e($rMonthName) ?> <?= e((string)$report['year']) ?></td>
                                    <td><span class="badge text-bg-<?= $report['format'] === 'pdf' ? 'danger' : 'success' ?>"><?= strtoupper(e($report['format'])) ?></span></td>
                                    <td><?= e(format_datetime_human($report['created_at'])) ?></td>
                                    <td class="text-end">
                                        <a href="<?= e(app_url('exports/download_saved_report.php?id=' . (int) $report['id'])) ?>" class="btn btn-sm btn-outline-primary">Download</a>
                                        <form method="post" action="<?= e(app_url('actions/admin_delete_report.php')) ?>" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this report?');">
                                            <input type="hidden" name="id" value="<?= (int)$report['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <p class="text-muted mb-0">No reports have been saved yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="row g-4 mb-4 fade-rise">
            <div class="col-xl-7">
                <div class="form-panel h-100">
                    <h2 class="h5" id="admin-submission-window">Submission Window</h2>
                    <p class="small text-muted mb-3">Set when departments can submit Staff of the Month entries.</p>
                    <form method="post" action="<?= e(app_url('actions/admin_update_submission_window.php')) ?>" class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="opens_at">Opens At</label>
                            <input type="datetime-local" id="opens_at" name="opens_at" class="form-control"
                                   value="<?= e(format_datetime_for_input($submissionSchedule['opens_at'] ?? null)) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="closes_at">Closes At</label>
                            <input type="datetime-local" id="closes_at" name="closes_at" class="form-control"
                                   value="<?= e(format_datetime_for_input($submissionSchedule['closes_at'] ?? null)) ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="submission_note">Note (Optional)</label>
                            <textarea id="submission_note" name="note" rows="2" class="form-control"><?= e($submissionSchedule['note'] ?? '') ?></textarea>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="is_enabled" name="is_enabled" <?= $scheduleEnabled ? 'checked' : '' ?>>
                                <label class="form-check-label" for="is_enabled">
                                    Enable submission window
                                </label>
                            </div>
                        </div>
                        <div class="col-12 d-grid">
                            <button type="submit" class="btn btn-primary">Save Submission Window</button>
                        </div>
                    </form>
                </div>
            </div>
            <div class="col-xl-5">
                <div class="form-panel h-100">
                    <h2 class="h5">Current Window Status</h2>
                    <p class="mb-2">
                        <span class="badge text-bg-<?= e(get_submission_window_badge_class($scheduleState)) ?>">
                            <?= e(get_submission_window_label($scheduleState)) ?>
                        </span>
                    </p>
                    <p class="small mb-1"><strong>Opens:</strong> <?= e(format_datetime_human($submissionSchedule['opens_at'] ?? null)) ?></p>
                    <p class="small mb-1"><strong>Closes:</strong> <?= e(format_datetime_human($submissionSchedule['closes_at'] ?? null)) ?></p>
                    <p class="small mb-1 text-muted">All times shown in Africa/Lagos.</p>
                    <p class="small mb-1"><strong>Last Updated:</strong> <?= e(format_datetime_human($submissionSchedule['updated_at'] ?? null)) ?></p>
                    <?php if (!empty($submissionSchedule['note'])): ?>
                        <p class="small mb-0"><strong>Note:</strong> <?= e($submissionSchedule['note']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="row g-4 mb-4 fade-rise">
            <div class="col-xl-6">
                <div class="form-panel h-100">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                        <div>
                            <h2 class="h5 mb-1" id="admin-winner">Select Monthly Winner</h2>
                            <p class="small text-muted mb-0">Pick one submission and add the reason clearly.</p>
                        </div>
                        <span class="badge text-bg-success">Award workflow</span>
                    </div>
                    <div class="row g-4">
                        <div class="col-lg-7">
                            <form method="post" action="<?= e(app_url('actions/admin_select_winner.php')) ?>" class="vstack gap-2">
                                <div>
                                    <label class="form-label" for="submission_id">Submission Candidate</label>
                                    <select id="submission_id" name="submission_id" class="form-select" required>
                                        <option value="">Choose candidate</option>
                                        <?php foreach ($winnerCandidates as $candidate): ?>
                                            <option value="<?= e((string) $candidate['id']) ?>">
                                                <?= e(format_month_year((int) $candidate['month'], (int) $candidate['year'])) ?> - <?= e($candidate['staff_name']) ?> (<?= e($candidate['department_name']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="form-label" for="winner_reasons">Winner Reasons</label>
                                    <textarea id="winner_reasons" name="winner_reasons" rows="4" class="form-control" placeholder="Enter reasons for selecting this monthly winner..." required></textarea>
                                </div>
                                <button class="btn btn-success" type="submit">Set Winner</button>
                            </form>
                        </div>
                        <div class="col-lg-5">
                            <div class="winner-guide card card-soft h-100">
                                <div class="card-body">
                                    <h3 class="h6 mb-2">Selection Tips</h3>
                                    <ul class="small text-muted mb-0 ps-3">
                                        <li>Choose the correct month and year.</li>
                                        <li>Use a clear, specific winner reason.</li>
                                        <li>Confirm the staff and department before saving.</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

<section class="row g-4 mb-4 fade-rise">
    <div class="col-12">
        <div class="form-panel h-100">
            <h2 class="h5" id="admin-videos">Event Videos</h2>
            <p class="small text-muted">Upload short event clips for the homepage and use a reference name for each video (MP4, WEBM, OGV, MOV. Max 25MB).</p>
            <form method="post" action="<?= e(app_url('actions/admin_manage_video.php')) ?>" enctype="multipart/form-data" class="row g-2 mb-3">
                <input type="hidden" name="action" value="create">
                <div class="col-sm-5 col-lg-5">
                    <input type="text" name="title" class="form-control" placeholder="Reference name" required>
                </div>
                <div class="col-sm-4 col-lg-4">
                    <input type="file" name="video" class="form-control" accept="video/mp4,video/webm,video/ogg,video/quicktime" required>
                </div>
                <div class="col-sm-3 col-lg-3 d-grid">
                    <button class="btn btn-primary btn-sm" type="submit">Upload Video</button>
                </div>
            </form>

            <div class="row g-3">
                <?php if (!$eventVideos): ?>
                    <div class="col-12"><p class="text-muted mb-0">No event videos uploaded.</p></div>
                <?php endif; ?>
                <?php foreach ($eventVideos as $video): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="card card-soft p-2 h-100">
                            <video controls preload="metadata" class="w-100 rounded mb-2" style="aspect-ratio: 16 / 9; background: #0e1114;">
                                <source src="<?= e(upload_url('videos', $video['video'])) ?>">
                                Your browser does not support video playback.
                            </video>
                            <p class="small fw-semibold mb-1">Reference: <?= e($video['title']) ?></p>
                            <p class="small text-muted mb-2"><?= e(format_datetime_human($video['uploaded_at'])) ?></p>
                            <form method="post" action="<?= e(app_url('actions/admin_manage_video.php')) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= e((string) $video['id']) ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm w-100 js-confirm" data-confirm="Delete this event video?">Delete</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

<section class="row g-4 mb-4 fade-rise">
    <div class="col-xl-6">
        <div class="form-panel h-100">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div>
                    <h2 class="h5 mb-1" id="admin-committee">Committee Members</h2>
                    <p class="small text-muted mb-0">Maintain the current committee and update photos or roles as needed.</p>
                </div>
                <span class="badge text-bg-primary">Team setup</span>
            </div>
            <div class="row g-4">
                <div class="col-lg-12">
                    <div class="table-wrap h-100">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                            <tr>
                                <th>Name</th>
                                <th>Role</th>
                                <th></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (!$committeeMembers): ?>
                                <tr><td colspan="3" class="text-muted">No committee members yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($committeeMembers as $member): ?>
                                <tr>
                                    <td><?= e($member['name']) ?></td>
                                    <td><?= e($member['role']) ?></td>
                                    <td class="text-end">
                                        <form method="post" action="<?= e(app_url('actions/admin_manage_committee.php')) ?>" class="d-inline">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= e((string) $member['id']) ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm js-confirm" data-confirm="Delete this committee member?">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="col-12">
                    <div class="card card-soft">
                        <div class="card-body">
                            <h3 class="h6 mb-3">Add Committee Member</h3>
                            <form method="post" action="<?= e(app_url('actions/admin_manage_committee.php')) ?>" enctype="multipart/form-data" class="row g-2 align-items-end">
                                <input type="hidden" name="action" value="create">
                                <div class="col-sm-6 col-lg-4">
                                    <input type="text" name="name" class="form-control" placeholder="Name" required>
                                </div>
                                <div class="col-sm-6 col-lg-3">
                                    <input type="text" name="role" class="form-control" placeholder="Role" required>
                                </div>
                                <div class="col-sm-8 col-lg-3">
                                    <input type="file" name="photo" class="form-control" accept="image/*" required>
                                </div>
                                <div class="col-sm-4 col-lg-2 d-grid">
                                    <button class="btn btn-primary btn-sm" type="submit">Add</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-6">
        <div class="form-panel h-100">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div>
                    <h2 class="h5" id="admin-gallery">Gallery Images</h2>
                    <p class="small text-muted mb-0">Upload gallery images and keep the homepage visual history current.</p>
                </div>
                <span class="badge text-bg-primary">Visual archive</span>
            </div>
            <div class="row g-4">
                <div class="col-12">
                    <div class="row g-2">
                        <?php if (!$galleryImages): ?>
                            <div class="col-12"><p class="text-muted mb-0">No gallery images uploaded.</p></div>
                        <?php endif; ?>
                        <?php foreach ($galleryImages as $image): ?>
                            <div class="col-6 col-md-4">
                                <div class="card card-soft p-2">
                                    <p class="small fw-semibold mb-2 text-break"><?= e($image['title'] ?: $image['image']) ?></p>
                                    <form method="post" action="<?= e(app_url('actions/admin_manage_gallery.php')) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= e((string) $image['id']) ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm w-100 js-confirm" data-confirm="Delete this gallery image?">Delete</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-12">
                    <div class="card card-soft">
                        <div class="card-body">
                            <h3 class="h6 mb-3">Upload Image</h3>
                            <form method="post" action="<?= e(app_url('actions/admin_manage_gallery.php')) ?>" enctype="multipart/form-data" class="row g-2 align-items-end">
                                <input type="hidden" name="action" value="create">
                                <div class="col-sm-6 col-lg-6">
                                    <input type="text" name="title" class="form-control" placeholder="Image title" required>
                                </div>
                                <div class="col-sm-6 col-lg-4">
                                    <input type="file" name="image[]" class="form-control" accept="image/*" multiple required>
                                </div>
                                <div class="col-12 col-lg-2 d-grid">
                                    <button class="btn btn-primary btn-sm" type="submit">Upload</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="admin-settings-grid mb-4 fade-rise" aria-label="Portal settings">
    <div class="admin-settings-item">
        <div class="form-panel admin-settings-panel">
            <h2 class="h5" id="admin-homepage-background">Homepage Background</h2>
            <p class="small text-muted">Choose colors for the app and homepage, then upload up to 10 pictures that rotate on the public homepage.</p>
            <form method="post" action="<?= e(app_url('actions/admin_update_homepage_background.php')) ?>" class="row g-2 align-items-end mb-4">
                <input type="hidden" name="action" value="save_colors">
                <div class="col-sm-6 col-md-5">
                    <label class="form-label" for="app_background_color">App background color</label>
                    <input type="color" id="app_background_color" name="app_background_color" class="form-control form-control-color" value="<?= e(normalize_hex_color((string) ($homepageSettings['app_background_color'] ?? '')) ?? '#f5f6e9') ?>">
                </div>
                <div class="col-sm-6 col-md-5">
                    <label class="form-label" for="homepage_background_color">Homepage background color</label>
                    <input type="color" id="homepage_background_color" name="homepage_background_color" class="form-control form-control-color" value="<?= e(normalize_hex_color((string) ($homepageSettings['homepage_background_color'] ?? '')) ?? '#f5f6e9') ?>">
                </div>
                <div class="col-12 col-md-2 d-grid">
                    <button type="submit" class="btn btn-outline-primary btn-sm">Save</button>
                </div>
            </form>
            <form method="post" action="<?= e(app_url('actions/admin_update_homepage_background.php')) ?>" enctype="multipart/form-data" class="vstack gap-2">
                <input type="hidden" name="action" value="upload_images">
                <div>
                    <label class="form-label" for="homepage_background_images">Homepage background pictures (<?= e((string) count($homepageBackgroundImages)) ?>/10)</label>
                    <input type="file" id="homepage_background_images" name="background_images[]" class="form-control" accept="image/jpeg,image/png,image/webp" multiple<?= count($homepageBackgroundImages) >= 10 ? ' disabled' : '' ?> required>
                    <p class="small text-muted mb-0 mt-1">JPG, PNG, or WEBP. Wide images work best; the homepage rotates through them every 7 seconds.</p>
                </div>
                <button type="submit" class="btn btn-primary"<?= count($homepageBackgroundImages) >= 10 ? ' disabled' : '' ?>>Add Background Pictures</button>
            </form>
            <?php if ($homepageBackgroundImages): ?>
                <div class="mt-3 dept-info-admin-preview">
                    <p class="small fw-semibold mb-2">Current Background Pictures</p>
                    <div class="row g-2">
                        <?php foreach ($homepageBackgroundImages as $backgroundImage): ?>
                            <div class="col-6">
                                <img src="<?= e(upload_url('gallery', $backgroundImage['image'])) ?>" alt="Homepage background preview" class="dept-info-admin-preview-image">
                                <form method="post" action="<?= e(app_url('actions/admin_update_homepage_background.php')) ?>" class="mt-1">
                                    <input type="hidden" name="action" value="delete_image">
                                    <input type="hidden" name="image_id" value="<?= e((string) $backgroundImage['id']) ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm w-100">Remove</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="admin-settings-item">
        <div class="form-panel admin-settings-panel">
            <h2 class="h5" id="admin-school-branding">School Branding</h2>
            <p class="small text-muted">Set the bold school name and optional badge shown at the top of the homepage.</p>
            <form method="post" action="<?= e(app_url('actions/admin_update_school_branding.php')) ?>" enctype="multipart/form-data" class="vstack gap-2">
                <div>
                    <label class="form-label" for="school_name">School name</label>
                    <input type="text" id="school_name" name="school_name" class="form-control" value="<?= e(normalize_text((string) ($homepageSettings['school_name'] ?? '')) ?: APP_NAME) ?>" required maxlength="120">
                </div>
                <div>
                    <label class="form-label" for="school_badge">School badge</label>
                    <input type="file" id="school_badge" name="school_badge" class="form-control" accept="image/jpeg,image/png,image/webp">
                    <p class="small text-muted mb-0 mt-1">Optional. JPG, PNG, or WEBP; a round FN badge is used until you upload one.</p>
                </div>
                <button type="submit" class="btn btn-primary">Save School Branding</button>
            </form>
            <?php if (!empty($homepageSettings['school_badge'])): ?>
                <div class="mt-3 d-flex align-items-center gap-2">
                    <img src="<?= e(upload_url('gallery', $homepageSettings['school_badge'])) ?>" alt="Current school badge" class="school-identity-badge">
                    <form method="post" action="<?= e(app_url('actions/admin_update_school_branding.php')) ?>">
                        <input type="hidden" name="school_name" value="<?= e(normalize_text((string) ($homepageSettings['school_name'] ?? '')) ?: APP_NAME) ?>">
                        <input type="hidden" name="remove_badge" value="1">
                        <button type="submit" class="btn btn-outline-danger btn-sm">Remove Badge</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="admin-settings-item">
        <div class="form-panel admin-settings-panel">
            <h2 class="h5" id="admin-dept-info">Department Vision, Mission, Objectives</h2>
            <form method="post" action="<?= e(app_url('actions/admin_update_department_info.php')) ?>" enctype="multipart/form-data" class="vstack gap-2">
                <div>
                    <label class="form-label" for="vision">Vision</label>
                    <textarea id="vision" name="vision" rows="2" class="form-control" required><?= e($departmentInfo['vision'] ?? '') ?></textarea>
                </div>
                <div>
                    <label class="form-label" for="mission">Mission</label>
                    <textarea id="mission" name="mission" rows="2" class="form-control" required><?= e($departmentInfo['mission'] ?? '') ?></textarea>
                </div>
                <div>
                    <label class="form-label" for="objectives">Objectives</label>
                    <textarea id="objectives" name="objectives" rows="3" class="form-control" required><?= e($departmentInfo['objectives'] ?? '') ?></textarea>
                </div>
                <div>
                    <label class="form-label" for="department_info_image">Department Picture</label>
                    <input type="file" id="department_info_image" name="image" class="form-control" accept="image/*">
                    <p class="small text-muted mb-0 mt-1">Optional. Upload a picture to appear in the Department Information cards.</p>
                </div>
                <button type="submit" class="btn btn-success">Save Department Info</button>
            </form>
            <div class="mt-3 dept-info-admin-preview">
                <p class="small fw-semibold mb-2">Current Picture</p>
                <div class="dept-info-admin-preview-box">
                    <img src="<?= e(!empty($departmentInfo['image']) ? upload_url('gallery', $departmentInfo['image']) : app_url('assets/img/placeholder.svg')) ?>" alt="Department information preview" class="dept-info-admin-preview-image">
                </div>
            </div>
        </div>
    </div>
    <div class="admin-settings-item">
        <div class="form-panel admin-settings-panel">
            <h2 class="h5" id="admin-notifications">Notifications and Reminders</h2>
            <form method="post" action="<?= e(app_url('actions/admin_send_notification.php')) ?>" class="vstack gap-2">
                <div>
                    <label class="form-label" for="department_target">Recipient</label>
                    <select id="department_target" name="department_target" class="form-select">
                        <option value="all">All departments</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= e((string) $dept['id']) ?>"><?= e($dept['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="subject">Subject</label>
                    <input id="subject" name="subject" type="text" class="form-control" required>
                </div>
                <div>
                    <label class="form-label" for="message">Message</label>
                    <textarea id="message" name="message" class="form-control" rows="4" required></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Send Notification</button>
            </form>
        </div>
    </div>
</section>

<section class="row g-4 mb-4 fade-rise">
    <div class="col-12">
        <div class="form-panel admin-reports-panel">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div>
                    <h2 class="h5 mb-1" id="admin-reports">Reports and Export</h2>
                    <p class="small text-muted mb-0">Generate reports that include staff names and departments only.</p>
                </div>
                <span class="badge text-bg-primary">Export tools</span>
            </div>
            <div class="row g-3 align-items-stretch">
                <div class="col-lg-6">
                    <div class="card card-soft h-100">
                        <div class="card-body">
                            <h3 class="h6 mb-2">Export Options</h3>
                            <div class="d-grid gap-2">
                                <a class="btn btn-outline-primary" href="<?= e(app_url('exports/report.php?format=csv')) ?>">Export Excel (CSV)</a>
                                <a class="btn btn-outline-primary" href="<?= e(app_url('exports/report.php?format=pdf')) ?>" target="_blank" rel="noopener">Export PDF Report</a>
                                <a class="btn btn-outline-secondary" href="<?= e(app_url('exports/print_report.php')) ?>" target="_blank" rel="noopener">Preview Summary Report</a>
                                <a class="btn btn-outline-secondary" href="<?= e(app_url('admin/department_reports.php')) ?>">Review Department KPI and Event Reports</a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card card-soft h-100">
                        <div class="card-body">
                            <h3 class="h6 mb-2">What’s Included</h3>
                            <ul class="small text-muted mb-0 ps-3">
                                <li>Department names and submission totals.</li>
                                <li>Winner summaries with staff details.</li>
                                <li>Printable output for meetings and filing.</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

    </main>
</div>
<?php
render_footer();
