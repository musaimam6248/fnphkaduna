<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_login('department');

if (!is_post()) {
    redirect('department/dashboard.php');
}

$departmentId = current_department_id();
if ($departmentId === null) {
    set_flash('error', 'Department account could not be loaded.');
    redirect('department/dashboard.php');
}

$staffId = (int) ($_POST['staff_id'] ?? 0);
$month = (int) ($_POST['month'] ?? 0);
$year = (int) ($_POST['year'] ?? 0);
$evaluationData = $_POST['evaluation'] ?? [];

if ($staffId <= 0 || $month < 1 || $month > 12 || $year < 2000 || empty($evaluationData)) {
    set_flash('error', 'Provide valid submission details and complete every evaluation.');
    redirect('department/dashboard.php');
}

$pdo = db();
$submissionSchedule = $pdo->query(
    'SELECT opens_at, closes_at, is_enabled FROM submission_schedule WHERE id = 1 LIMIT 1'
)->fetch();
$scheduleEnabled = $submissionSchedule ? ((int) $submissionSchedule['is_enabled'] === 1) : false;
$scheduleState = get_submission_window_state(
    $submissionSchedule['opens_at'] ?? null,
    $submissionSchedule['closes_at'] ?? null,
    $scheduleEnabled
);

if ($scheduleState !== 'open') {
    set_flash('error', 'Submission window is currently ' . strtolower(get_submission_window_label($scheduleState)) . '.');
    redirect('department/dashboard.php');
}

$staffStmt = $pdo->prepare(
    'SELECT id FROM staff WHERE id = :staff_id AND department_id = :department_id LIMIT 1'
);
$staffStmt->execute([
    ':staff_id' => $staffId,
    ':department_id' => $departmentId,
]);

if (!$staffStmt->fetch()) {
    set_flash('error', 'The selected staff member does not belong to your department.');
    redirect('department/dashboard.php');
}

$finalRemarks = normalize_text($_POST['final_remarks'] ?? '');

$totalScore = 0;
$categoryScores = [];
$validatedData = [];

foreach ($evaluationData as $category => $criteria) {
    if (!is_array($criteria)) {
        continue;
    }
    $categoryTotal = 0;
    $criterionCount = 0;
    foreach ($criteria as $key => $score) {
        $score = (int) $score;
        if ($score < 1 || $score > 5) {
            set_flash('error', 'Invalid score for a criterion in ' . $category . '. Scores must be 1 to 5.');
            redirect('department/dashboard.php');
        }
        $validatedData[$category][$key] = $score;
        $categoryTotal += $score;
        $criterionCount++;
    }
    $categoryScores[$category] = [
        'total' => $categoryTotal,
        'count' => $criterionCount,
    ];
    $totalScore += $categoryTotal;
}

$criterionLabelsMap = [
    'leadership_quality' => 'Leadership Quality / Management of Staff',
    'team_player' => 'Team Player',
    'work_efficiency' => 'Work Efficiency',
    'responsibility' => 'High Degree of Responsibility',
    'reliability' => 'Reliability',
    'appearance' => 'Appearance',
    'sense_of_responsibility' => 'Sense of Responsibility',
    'loyalty' => 'Loyalty to the Organization',
    'honesty' => 'Honesty',
    'creativity' => 'Creativity',
    'helping_coworkers' => 'Helping Co-workers Towards Institutional Goals',
    'new_ideas' => 'New Ideas',
    'excellent_skills' => 'Demonstration of Excellent Skills',
    'work_under_pressure' => 'Ability to Work Under Pressure',
    'quality_of_work' => 'Quality of Work',
    'interpersonal_relationship' => 'Interpersonal Relationship',
    'attitude_to_work' => 'Attitude to Work',
    'drive_to_learn' => 'Drive and Determination to Learn',
    'cooperation' => 'Cooperation and Following Directions',
    'resource_utilization' => 'Resource Utilization',
    'achievement' => 'Achievement',
    'quality_of_work_p' => 'Quality of Work',
    'work_speed_accuracy' => 'Work Speed and Accuracy',
    'oral_expression' => 'Oral Expression',
    'paper_expression' => 'Paper Expression',
    'punctuality_at_work' => 'Punctuality at Work',
    'attendance_at_duty_post' => 'Attendance at Duty Post',
];

try {
    $pdo->beginTransaction();

    $submitStmt = $pdo->prepare(
        "INSERT INTO submission (department_id, staff_id, month, year, reason_one, reason_two, score, final_remarks, status)
         VALUES (:department_id, :staff_id, :month, :year, '', '', :score, :final_remarks, 'pending')
         ON CONFLICT(department_id, month, year) DO UPDATE SET
            staff_id = excluded.staff_id,
            score = excluded.score,
            final_remarks = excluded.final_remarks,
            status = 'pending',
            created_at = CURRENT_TIMESTAMP"
    );
    $submitStmt->execute([
        ':department_id' => $departmentId,
        ':staff_id' => $staffId,
        ':month' => $month,
        ':year' => $year,
        ':score' => $totalScore,
        ':final_remarks' => $finalRemarks,
    ]);

    $fetchIdStmt = $pdo->prepare(
        'SELECT id FROM submission WHERE department_id = :department_id AND month = :month AND year = :year LIMIT 1'
    );
    $fetchIdStmt->execute([
        ':department_id' => $departmentId,
        ':month' => $month,
        ':year' => $year,
    ]);
    $submissionId = (int) $fetchIdStmt->fetchColumn();

    $deleteEvalStmt = $pdo->prepare(
        'DELETE FROM performance_evaluation WHERE submission_id = :submission_id'
    );
    $deleteEvalStmt->execute([':submission_id' => $submissionId]);

    $insertEvalStmt = $pdo->prepare(
        "INSERT INTO performance_evaluation (submission_id, category, criterion_key, criterion_label, score)
         VALUES (:submission_id, :category, :criterion_key, :criterion_label, :score)
         ON CONFLICT(submission_id, category, criterion_key) DO UPDATE SET
            score = excluded.score,
            criterion_label = excluded.criterion_label"
    );

    foreach ($validatedData as $category => $criteria) {
        foreach ($criteria as $key => $score) {
            $label = $criterionLabelsMap[$key] ?? $key;
            $insertEvalStmt->execute([
                ':submission_id' => $submissionId,
                ':category' => $category,
                ':criterion_key' => $key,
                ':criterion_label' => $label,
                ':score' => $score,
            ]);
        }
    }

    $pdo->commit();
    $avgScore = count($criterionLabelsMap) > 0 ? round($totalScore / 27, 1) : 0;
    set_flash('success', 'Evaluation submitted successfully.');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    set_flash('error', 'Could not submit evaluation: ' . $exception->getMessage());
}

redirect('department/dashboard.php');
