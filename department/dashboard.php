<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';

require_login('department');

$user = current_user();
$departmentId = current_department_id();
if ($departmentId === null) {
    set_flash('error', 'Department account context was not found.');
    redirect('logout.php');
}

$pdo = db();

$staffStmt = $pdo->prepare(
    'SELECT id, name, rank, photo, created_at
     FROM staff
     WHERE department_id = :department_id
     ORDER BY created_at DESC'
);
$staffStmt->execute([':department_id' => $departmentId]);
$staffMembers = $staffStmt->fetchAll();

$historyStmt = $pdo->prepare(
    "SELECT s.id, s.month, s.year, s.score, s.status, s.created_at, st.name AS staff_name
     FROM submission s
     INNER JOIN staff st ON st.id = s.staff_id
     WHERE s.department_id = :department_id
     ORDER BY s.year DESC, s.month DESC, s.created_at DESC"
);
$historyStmt->execute([':department_id' => $departmentId]);
$submissionHistory = $historyStmt->fetchAll();

$notificationStmt = $pdo->prepare(
    'SELECT id, message, created_at
     FROM notifications
     WHERE department_id = :department_id OR department_id IS NULL
     ORDER BY created_at DESC
     LIMIT 10'
);
$notificationStmt->execute([':department_id' => $departmentId]);
$notifications = $notificationStmt->fetchAll();

$submissionSchedule = $pdo->query(
    'SELECT opens_at, closes_at, is_enabled, note, updated_at FROM submission_schedule WHERE id = 1 LIMIT 1'
)->fetch();
$scheduleEnabled = $submissionSchedule ? ((int) $submissionSchedule['is_enabled'] === 1) : false;
$scheduleState = get_submission_window_state(
    $submissionSchedule['opens_at'] ?? null,
    $submissionSchedule['closes_at'] ?? null,
    $scheduleEnabled
);
$canSubmitSelection = $scheduleState === 'open';

$currentMonth = (int) date('n');
$currentYear = (int) date('Y');

render_header('Department Dashboard');
?>
<div class="department-dashboard-page">
<section class="department-portal-hero mb-4 fade-rise" aria-label="Department information">
    <div class="department-portal-mark" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none"><path d="M3.5 20.5h17M5.5 20.5V9.2L12 4l6.5 5.2v11.3M9 20.5v-6h6v6M8.2 10.5h.1m7.4 0h.1" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </div>
    <div class="department-portal-identity">
        <p class="department-portal-kicker mb-1">Department Portal <span aria-hidden="true">·</span> FNPH Kaduna</p>
        <h1 class="h3 mb-1"><?= e($user['department_name'] ?? 'Department') ?></h1>
        <p class="text-muted mb-0">Head of Department (HOD): <strong><?= e($user['hod'] ?? 'N/A') ?></strong><?php if (!empty($user['email'])): ?> <span class="department-contact-divider">·</span> Contact: <a href="mailto:<?= e($user['email']) ?>"><?= e($user['email']) ?></a><?php endif; ?></p>
    </div>
    <div class="department-window-status is-<?= e($scheduleState) ?>">
        <span class="department-window-status-dot" aria-hidden="true"></span>
        <span><strong>Nomination Window: <?= e(strtoupper(get_submission_window_label($scheduleState))) ?></strong><small><?= !empty($submissionSchedule['closes_at']) ? 'Closes ' . e(date('m/d/Y', strtotime((string) $submissionSchedule['closes_at']))) : 'Check submission schedule' ?></small></span>
    </div>
</section>

<nav class="department-portal-tabs mb-4 fade-rise" aria-label="Department dashboard sections">
    <a class="department-portal-tab" href="#performance-submission"><span aria-hidden="true">♙</span> Nominate Staff</a>
    <a class="department-portal-tab" href="#staff-roster"><span aria-hidden="true">♧</span> Staff Roster (<?= e((string) count($staffMembers)) ?>)</a>
    <a class="department-portal-tab" href="#submission-history"><span aria-hidden="true">◷</span> Nomination History (<?= e((string) count($submissionHistory)) ?>)</a>
    <a class="department-portal-tab" href="<?= e(app_url('department/monthly_kpi.php')) ?>"><span aria-hidden="true">◎</span> Monthly KPI</a>
    <a class="department-portal-tab" href="#department-notifications"><span aria-hidden="true">♧</span> Notifications (<?= e((string) count($notifications)) ?>)</a>
</nav>

<section class="department-dashboard-intro mb-4 fade-rise">
    <h2 class="h5 mb-1">Department Dashboard</h2>
    <p class="text-muted mb-0">Manage staff nominations, reporting, and department updates from one place.</p>
</section>

<section class="row g-3 mb-4 fade-rise">
    <div class="col-md-4">
        <div class="metric-card card">
            <div class="card-body">
                <p class="mb-1 text-muted">Staff Members</p>
                <p class="metric-number mb-0"><?= e((string) count($staffMembers)) ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="metric-card card">
            <div class="card-body">
                <p class="mb-1 text-muted">Submissions Sent</p>
                <p class="metric-number mb-0"><?= e((string) count($submissionHistory)) ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="metric-card card">
            <div class="card-body">
                <p class="mb-1 text-muted">Messages</p>
                <p class="metric-number mb-0"><?= e((string) count($notifications)) ?></p>
            </div>
        </div>
    </div>
</section>

<section class="row g-3 mb-4 fade-rise" aria-label="Department reporting">
    <div class="col-md-6">
        <div class="card card-soft h-100"><div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div><h2 class="h5 mb-1">Monthly KPI</h2><p class="small text-muted mb-0">Submit this month’s achievements, variance, evidence, and next steps.</p></div>
            <a class="btn btn-primary" href="<?= e(app_url('department/monthly_kpi.php')) ?>">Submit monthly report</a>
        </div></div>
    </div>
    <div class="col-md-6">
        <div class="card card-soft h-100"><div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div><h2 class="h5 mb-1">Monthly Report</h2><p class="small text-muted mb-0">Review submitted reports and compare KPI achievement with the previous month.</p></div>
            <a class="btn btn-outline-primary" href="<?= e(app_url('department/monthly_report.php')) ?>">View reports</a>
        </div></div>
    </div>
</section>

<section class="mb-4 fade-rise">
    <div class="card card-soft">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                <h2 class="h5 mb-0">Submission Window</h2>
                <span class="badge text-bg-<?= e(get_submission_window_badge_class($scheduleState)) ?>">
                    <?= e(get_submission_window_label($scheduleState)) ?>
                </span>
            </div>
            <p class="mb-1"><strong>Opens:</strong> <?= e(format_datetime_human($submissionSchedule['opens_at'] ?? null)) ?></p>
            <p class="mb-1"><strong>Closes:</strong> <?= e(format_datetime_human($submissionSchedule['closes_at'] ?? null)) ?></p>
            <p class="mb-1 small text-muted">All times shown in Africa/Lagos.</p>
            <?php if (!empty($submissionSchedule['note'])): ?>
                <p class="mb-0 text-muted small"><strong>Note:</strong> <?= e($submissionSchedule['note']) ?></p>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="row g-4 mb-4 fade-rise" id="staff-submission">
    <div class="col-12">
        <div class="form-panel">
            <h2 class="h5">Staff Submission Form</h2>
            <p class="small text-muted">Add staff name, rank, and photo for your department.</p>
            <form action="<?= e(app_url('actions/department_add_staff.php')) ?>" method="post" enctype="multipart/form-data" class="row g-3">
                <div class="col-12">
                    <label class="form-label" for="staff_name">Staff Name</label>
                    <input type="text" id="staff_name" name="staff_name" class="form-control" required>
                </div>
                <div class="col-12">
                    <label class="form-label" for="staff_rank">Rank</label>
                    <input type="text" id="staff_rank" name="staff_rank" class="form-control" required>
                </div>
                <div class="col-12">
                    <label class="form-label" for="staff_photo">Photo Upload</label>
                    <input type="file" id="staff_photo" name="staff_photo" class="form-control" accept="image/*" required>
                </div>
                <div class="col-12 d-grid">
                    <button type="submit" class="btn btn-primary">Save Staff Member</button>
                </div>
            </form>
        </div>
    </div>
</section>

<section class="mb-5 fade-rise">
    <div class="form-panel performance-submission-panel" id="performance-submission">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3 performance-submission-header">
            <div>
                <h2 class="h5 mb-1">Performance Submission</h2>
                <p class="small text-muted mb-0">Select a staff member, rate each criterion, and add your final remarks.</p>
            </div>
            <span class="badge text-bg-primary align-self-start performance-submission-badge">One submission per month</span>
        </div>
        <div class="performance-submission-grid">
            <?php
            $existingSubmissionStmt = $pdo->prepare(
                'SELECT s.id, s.staff_id, s.month, s.year, st.name AS staff_name, st.rank AS staff_rank, s.status, s.final_remarks
                 FROM submission s
                 INNER JOIN staff st ON st.id = s.staff_id
                 WHERE s.department_id = :department_id
                   AND s.month = :month
                   AND s.year = :year
                 LIMIT 1'
            );
            $existingSubmissionStmt->execute([
                ':department_id' => $departmentId,
                ':month' => $currentMonth,
                ':year' => $currentYear,
            ]);
            $existingSubmission = $existingSubmissionStmt->fetch();

            $savedRatings = [];
            if ($existingSubmission) {
                $evalStmt = $pdo->prepare(
                    'SELECT category, criterion_key, score FROM performance_evaluation WHERE submission_id = :submission_id'
                );
                $evalStmt->execute([':submission_id' => $existingSubmission['id']]);
                foreach ($evalStmt->fetchAll() as $evalRow) {
                    $savedRatings[$evalRow['category']][$evalRow['criterion_key']] = (int) $evalRow['score'];
                }
                unset(
                    $savedRatings['leadership'],
                    $savedRatings['positive_attitude'],
                    $savedRatings['innovation'],
                    $savedRatings['interpersonal_skills'],
                    $savedRatings['willingness_to_learn'],
                    $savedRatings['productivity'],
                    $savedRatings['punctuality']
                );
            }
            ?>
            <?php if (!$canSubmitSelection): ?>
                <div class="alert alert-warning border-0 shadow-sm">
                    Submission is currently <?= e(strtolower(get_submission_window_label($scheduleState))) ?>.
                    Departments can submit only when the window is open.
                </div>
            <?php elseif ($existingSubmission): ?>
                <div class="alert alert-info border-0 shadow-sm">
                    <strong>Submission already exists.</strong> You previously selected
                    <strong><?= e($existingSubmission['staff_name']) ?></strong> (<?= e($existingSubmission['staff_rank']) ?>)
                    for <?= e(format_month_year((int) $existingSubmission['month'], (int) $existingSubmission['year'])) ?>
                    with status <strong><?= e($existingSubmission['status']) ?></strong>.
                    You have already sent the staff for this month.
                </div>
            <?php endif; ?>
            <?php if (!$existingSubmission): ?>
            <form action="<?= e(app_url('actions/department_submit_evaluation.php')) ?>" method="post" class="row g-3">
                 <div class="col-md-6">
                     <label class="form-label" for="staff_id">Select Staff</label>
                     <select id="staff_id" name="staff_id" class="form-select form-select-lg" required <?= $canSubmitSelection ? '' : 'disabled' ?>>
                         <option value="">Choose a staff member</option>
                         <?php foreach ($staffMembers as $staff): ?>
                             <?php
                             $isStaffSelected = $existingSubmission && (int) $existingSubmission['staff_id'] === (int) $staff['id'];
                             ?>
                             <option value="<?= e((string) $staff['id']) ?>" <?= $isStaffSelected ? 'selected' : '' ?>>
                                 <?= e($staff['name']) ?> (<?= e($staff['rank']) ?>)
                             </option>
                         <?php endforeach; ?>
                     </select>
                 </div>
                 <div class="col-md-3">
                     <label class="form-label" for="month">Month</label>
                     <select id="month" name="month" class="form-select form-select-lg" required <?= $canSubmitSelection ? '' : 'disabled' ?>>
                         <?php for ($month = 1; $month <= 12; $month++): ?>
                             <option value="<?= e((string) $month) ?>" <?= $month === $currentMonth ? 'selected' : '' ?>>
                                 <?= e(month_label($month)) ?>
                             </option>
                         <?php endfor; ?>
                     </select>
                 </div>
                 <div class="col-md-3">
                     <label class="form-label" for="year">Year</label>
                     <input type="number" id="year" name="year" class="form-control form-control-lg" min="2024" value="<?= e((string) $currentYear) ?>" required <?= $canSubmitSelection ? '' : 'disabled' ?>>
                 </div>
                 <div class="col-12">
                       <div class="evaluation-intro card border-0 mb-3 performance-evaluation-intro">
                           <div class="card-body py-3 px-3 px-md-4">
                               <h3 class="h6 mb-1">Performance Evaluation</h3>
                               <p class="small text-muted mb-0">Rate each item from 1 to 5. Five means the strongest performance.</p>
                           </div>
                       </div>
                       <?php
                       $evaluationCriteria = [
                           [
                               'category' => 'leadership',
                               'label' => '1. LEADERSHIP',
                               'criteria' => [
                                   'leadership_quality' => 'Leadership Quality / Management of Staff',
                                   'team_player' => 'Team Player',
                                   'work_efficiency' => 'Work Efficiency',
                                   'responsibility' => 'High Degree of Responsibility',
                                   'reliability' => 'Reliability',
                               ],
                           ],
                           [
                               'category' => 'positive_attitude',
                               'label' => '2. POSITIVE ATTITUDE TO WORK',
                               'criteria' => [
                                   'appearance' => 'Appearance',
                                   'sense_of_responsibility' => 'Sense of Responsibility',
                                   'loyalty' => 'Loyalty to the Organization',
                                   'honesty' => 'Honesty',
                               ],
                           ],
                           [
                               'category' => 'innovation',
                               'label' => '3. INNOVATION',
                               'criteria' => [
                                   'creativity' => 'Creativity',
                                   'helping_coworkers' => 'Helping Co-workers Towards Institutional Goals',
                                   'new_ideas' => 'New Ideas',
                               ],
                           ],
                           [
                               'category' => 'interpersonal_skills',
                               'label' => '4. INTERPERSONAL SKILLS',
                               'criteria' => [
                                   'excellent_skills' => 'Demonstration of Excellent Skills',
                                   'work_under_pressure' => 'Ability to Work Under Pressure',
                                   'quality_of_work' => 'Quality of Work',
                                   'interpersonal_relationship' => 'Interpersonal Relationship',
                               ],
                           ],
                           [
                               'category' => 'willingness_to_learn',
                               'label' => '5. WILLINGNESS TO LEARN',
                               'criteria' => [
                                   'attitude_to_work' => 'Attitude to Work',
                                   'drive_to_learn' => 'Drive and Determination to Learn',
                                   'cooperation' => 'Cooperation and Following Directions',
                               ],
                           ],
                           [
                               'category' => 'productivity',
                               'label' => '6. PRODUCTIVITY',
                               'criteria' => [
                                   'resource_utilization' => 'Resource Utilization',
                                   'achievement' => 'Achievement',
                                   'quality_of_work_p' => 'Quality of Work',
                                   'work_speed_accuracy' => 'Work Speed and Accuracy',
                                   'oral_expression' => 'Oral Expression',
                                   'paper_expression' => 'Paper Expression',
                               ],
                           ],
                           [
                               'category' => 'punctuality',
                               'label' => '7. PUNCTUALITY',
                               'criteria' => [
                                   'punctuality_at_work' => 'Punctuality at Work',
                                   'attendance_at_duty_post' => 'Attendance at Duty Post',
                               ],
                           ],
                       ];
                       $totalCriteriaCount = array_sum(array_map(static fn($c) => count($c['criteria']), $evaluationCriteria));
                       ?>
                       <div class="evaluation-cards performance-evaluation-cards" id="evaluationForm">
                           <?php $rowNum = 0; ?>
                           <?php foreach ($evaluationCriteria as $catIndex => $category): ?>
                               <div class="evaluation-category-card card card-soft mb-3">
                                   <div class="card-body">
                                       <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                                           <div>
                                               <h4 class="h6 mb-1"><?= e($category['label']) ?></h4>
                                               <p class="small text-muted mb-0">Rate every item in this category from 1 to 5.</p>
                                           </div>
                                       </div>
                                       <div class="vstack gap-2">
                                           <?php foreach ($category['criteria'] as $key => $label): ?>
                                               <?php
                                               $rowNum++;
                                               $savedScore = $savedRatings[$category['category']][$key] ?? null;
                                               ?>
                                               <div class="evaluation-criterion-row" data-category="<?= e($category['category']) ?>">
                                                   <div class="evaluation-criterion-head">
                                                       <div class="evaluation-criterion-index"><?= e((string) $rowNum) ?></div>
                                                       <div class="evaluation-criterion-label-wrap">
                                                           <label class="eval-criterion-label mb-0" for="eval_<?= e($category['category']) ?>_<?= e($key) ?>_5"><?= e($label) ?></label>
                                                       </div>
                                                   </div>
                                                   <div class="evaluation-rating-group" role="radiogroup" aria-label="<?= e($label) ?>">
                                                       <?php for ($rating = 5; $rating >= 1; $rating--): ?>
                                                           <?php $isChecked = $savedScore === $rating; ?>
                                                           <div class="form-check form-check-inline">
                                                               <input class="form-check-input eval-rating" type="radio"
                                                                      name="evaluation[<?= e($category['category']) ?>][<?= e($key) ?>]"
                                                                      id="eval_<?= e($category['category']) ?>_<?= e($key) ?>_<?= e((string) $rating) ?>"
                                                                      value="<?= e((string) $rating) ?>"
                                                                      data-category="<?= e($category['category']) ?>"
                                                                      <?= $isChecked ? 'checked' : '' ?>
                                                                      <?= $canSubmitSelection ? '' : 'disabled' ?> required>
                                                               <label class="form-check-label small eval-rating-label"
                                                                      for="eval_<?= e($category['category']) ?>_<?= e($key) ?>_<?= e((string) $rating) ?>">
                                                                   <?= e((string) $rating) ?>
                                                               </label>
                                                           </div>
                                                       <?php endfor; ?>
                                                   </div>
                                               </div>
                                           <?php endforeach; ?>
                                       </div>
                                   </div>
                               </div>
                           <?php endforeach; ?>
                       </div>

                       <div class="mt-3 performance-remarks-block">
                           <h5 class="h6 mb-2">Final remarks</h5>
                           <div class="mb-2">
                               <label class="form-label small fw-semibold" for="final_remarks">Head of Department / Unit</label>
                               <textarea id="final_remarks" name="final_remarks" class="form-control" rows="3" placeholder="Enter final remarks by Head of Department / Unit..." <?= $canSubmitSelection ? '' : 'disabled' ?>><?= e($existingSubmission['final_remarks'] ?? '') ?></textarea>
                           </div>
                       </div>
                   </div>
                   <div class="col-12 mt-3 performance-submit-row">
                       <button type="submit" class="btn btn-success btn-lg w-100 rounded-pill performance-submit-btn" <?= $canSubmitSelection ? '' : 'disabled' ?>>Submit Evaluation</button>
                   </div>
             </form>
            <?php endif; ?>
        </div>
    </div>
</section>

<script>
(function() {
    var categories = ['leadership', 'positive_attitude', 'innovation', 'interpersonal_skills', 'willingness_to_learn', 'productivity', 'punctuality'];
    var totalCriteria = <?= e((string) $totalCriteriaCount) ?>;

    function calculate() {
        categories.forEach(function(cat) {
            var catTotal = 0;
            var checkedRadios = document.querySelectorAll('.eval-rating[data-category="' + cat + '"]:checked');
            checkedRadios.forEach(function(radio) {
                catTotal += parseInt(radio.value, 10);
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        var form = document.getElementById('evaluationForm');
        if (form) {
            form.addEventListener('change', calculate);
        }
        var radioInputs = document.querySelectorAll('.eval-rating');
        radioInputs.forEach(function(radio) {
            radio.addEventListener('change', calculate);
        });
        calculate();
    });
})();
</script>

<section class="mb-5 fade-rise" id="staff-roster">
    <h2 class="section-title h5">Current Staff List</h2>
    <?php if ($staffMembers): ?>
        <div class="row g-3">
            <?php foreach ($staffMembers as $staff): ?>
                <div class="col-sm-6 col-lg-3">
                    <div class="card card-soft h-100">
                        <img src="<?= e(upload_url('staff', $staff['photo'])) ?>" alt="<?= e($staff['name']) ?>" class="staff-photo">
                        <div class="card-body">
                            <h3 class="h6 mb-1"><?= e($staff['name']) ?></h3>
                            <p class="small text-muted mb-0"><?= e($staff['rank']) ?></p>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="alert alert-secondary">No staff has been added yet.</div>
    <?php endif; ?>
</section>

<section class="mb-5 fade-rise" id="submission-history">
    <h2 class="section-title h5">Submission History</h2>
    <div class="table-wrap card card-soft p-3">
        <table class="table align-middle mb-0">
            <thead>
            <tr>
                <th>Month/Year</th>
                <th>Staff</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$submissionHistory): ?>
                <tr><td colspan="2" class="text-muted">No submissions yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($submissionHistory as $item): ?>
                <tr>
                    <td><?= e(format_month_year((int) $item['month'], (int) $item['year'])) ?></td>
                    <td><?= e($item['staff_name']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="mb-3 fade-rise" id="department-notifications">
    <h2 class="section-title h5">Messages</h2>
    <?php if ($notifications): ?>
        <div class="list-group">
            <?php foreach ($notifications as $notification): ?>
                <div class="list-group-item">
                    <p class="mb-1"><?= e($notification['message']) ?></p>
                    <small class="text-muted"><?= e((new DateTime($notification['created_at']))->format('d M Y, h:i A')) ?></small>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="alert alert-secondary mb-0">No notifications yet.</div>
    <?php endif; ?>
</section>
</div>
<?php
render_footer();
