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

if ($staffId <= 0 || $month < 1 || $month > 12 || $year < 2000) {
    set_flash('error', 'Provide valid submission details.');
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
    $message = 'Submission window is currently ' . strtolower(get_submission_window_label($scheduleState)) . '. ';
    $message .= 'Open: ' . format_datetime_human($submissionSchedule['opens_at'] ?? null) . ' | ';
    $message .= 'Close: ' . format_datetime_human($submissionSchedule['closes_at'] ?? null);
    set_flash('error', $message);
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

try {
    $existingSubmissionStmt = $pdo->prepare(
        'SELECT id FROM submission WHERE department_id = :department_id AND month = :month AND year = :year LIMIT 1'
    );
    $existingSubmissionStmt->execute([
        ':department_id' => $departmentId,
        ':month' => $month,
        ':year' => $year,
    ]);
    if ($existingSubmissionStmt->fetch()) {
        set_flash('error', 'Your department has already selected one staff member for this month.');
        redirect('department/dashboard.php');
    }

    $submitStmt = $pdo->prepare(
        "INSERT INTO submission (department_id, staff_id, month, year, score, status)
         VALUES (:department_id, :staff_id, :month, :year, 0, 'pending')"
    );
    $submitStmt->execute([
        ':department_id' => $departmentId,
        ':staff_id' => $staffId,
        ':month' => $month,
        ':year' => $year,
    ]);

    set_flash('success', 'Selection submitted. Only one staff member can be chosen per month.');
} catch (Throwable $exception) {
    set_flash('error', 'Could not submit selection: ' . $exception->getMessage());
}

redirect('department/dashboard.php');
