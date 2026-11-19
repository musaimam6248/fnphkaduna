<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/image.php';
require_login('department');

if (!is_post()) {
    redirect('department/annual_event_report.php');
}

$departmentId = current_department_id();
$year = (int) ($_POST['report_year'] ?? 0);
$status = (string) ($_POST['status'] ?? 'draft');
$postedEvents = $_POST['events'] ?? [];
if ($departmentId === null || $year < 2000 || $year > 2200 || !in_array($status, ['draft', 'submitted'], true)) {
    set_flash('error', 'Choose a valid year and submission status.');
    redirect('department/annual_event_report.php');
}
if (!is_array($postedEvents) || count($postedEvents) < 1 || count($postedEvents) > 100) {
    set_flash('error', 'Add between 1 and 100 events before saving the annual report.');
    redirect('department/annual_event_report.php?year=' . $year);
}

$events = [];
foreach ($postedEvents as $postedEvent) {
    if (!is_array($postedEvent)) {
        continue;
    }
    $event = [
        'event_name' => normalize_text((string) ($postedEvent['event_name'] ?? '')),
        'event_date_from' => trim((string) ($postedEvent['event_date_from'] ?? '')),
        'event_date_to' => trim((string) ($postedEvent['event_date_to'] ?? '')),
        'purpose' => normalize_text((string) ($postedEvent['purpose'] ?? '')),
        'planned_activities' => normalize_text((string) ($postedEvent['planned_activities'] ?? '')),
        'responsible_officer' => normalize_text((string) ($postedEvent['responsible_officer'] ?? '')),
        'expected_outcomes' => normalize_text((string) ($postedEvent['expected_outcomes'] ?? '')),
        'evidence' => normalize_text((string) ($postedEvent['evidence'] ?? '')),
    ];
    if (implode('', $event) === '') {
        continue;
    }
    $fromDate = DateTimeImmutable::createFromFormat('!Y-m-d', $event['event_date_from']);
    $toDate = DateTimeImmutable::createFromFormat('!Y-m-d', $event['event_date_to']);
    if ($event['event_name'] === '' || !$fromDate || !$toDate || $fromDate->format('Y-m-d') !== $event['event_date_from'] || $toDate->format('Y-m-d') !== $event['event_date_to'] || $event['event_date_from'] > $event['event_date_to'] || (int) substr($event['event_date_from'], 0, 4) !== $year || (int) substr($event['event_date_to'], 0, 4) !== $year) {
        set_flash('error', 'Each event needs a name and valid dates within the selected report year.');
        redirect('department/annual_event_report.php?year=' . $year);
    }
    if ($event['purpose'] === '' || $event['planned_activities'] === '' || $event['responsible_officer'] === '' || $event['expected_outcomes'] === '') {
        set_flash('error', 'Complete purpose, planned activities, responsible officer, and expected outcomes for every event.');
        redirect('department/annual_event_report.php?year=' . $year);
    }
    if (strlen($event['event_name']) > 250 || strlen($event['purpose']) > 2000 || strlen($event['planned_activities']) > 4000 || strlen($event['responsible_officer']) > 250 || strlen($event['expected_outcomes']) > 4000 || strlen($event['evidence']) > 2000) {
        set_flash('error', 'One or more event fields are too long. Shorten the text and try again.');
        redirect('department/annual_event_report.php?year=' . $year);
    }
    $events[] = $event;
}
if (!$events) {
    set_flash('error', 'Add at least one complete event before saving the annual report.');
    redirect('department/annual_event_report.php?year=' . $year);
}

$pdo = db();
$existingStmt = $pdo->prepare('SELECT id, status, edit_approved_at, attachment_filename FROM department_annual_event_report WHERE department_id = :department_id AND report_year = :year LIMIT 1');
$existingStmt->execute([':department_id' => $departmentId, ':year' => $year]);
$existing = $existingStmt->fetch() ?: null;
if ($existing && $existing['status'] !== 'draft' && empty($existing['edit_approved_at'])) {
    set_flash('error', 'This annual event report is locked after submission. Ask an admin to approve an edit.');
    redirect('department/annual_event_report.php?year=' . $year);
}

$newFile = null;
try {
    $newFile = upload_annual_event_file($_FILES['report_file'] ?? []);
    $attachmentFilename = $newFile['filename'] ?? ($existing['attachment_filename'] ?? null);
    $attachmentOriginalName = $newFile['original_name'] ?? null;
    if (!$newFile && $existing) {
        $nameStmt = $pdo->prepare('SELECT attachment_original_name FROM department_annual_event_report WHERE id = :id');
        $nameStmt->execute([':id' => (int) $existing['id']]);
        $attachmentOriginalName = $nameStmt->fetchColumn() ?: null;
    }

    $pdo->beginTransaction();
    if ($existing) {
        $save = $pdo->prepare(
            "UPDATE department_annual_event_report
             SET status = :status, attachment_filename = :attachment_filename, attachment_original_name = :attachment_original_name,
                 submitted_at = CASE WHEN :submit_status = 'submitted' THEN CURRENT_TIMESTAMP ELSE NULL END,
                 reviewed_at = NULL, edit_approved_at = NULL, edit_approved_by = NULL, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND department_id = :department_id"
        );
        $save->execute([
            ':status' => $status,
            ':attachment_filename' => $attachmentFilename,
            ':attachment_original_name' => $attachmentOriginalName,
            ':submit_status' => $status,
            ':id' => (int) $existing['id'],
            ':department_id' => $departmentId,
        ]);
        $reportId = (int) $existing['id'];
    } else {
        $save = $pdo->prepare(
            "INSERT INTO department_annual_event_report (department_id, report_year, status, attachment_filename, attachment_original_name, submitted_at)
             VALUES (:department_id, :year, :status, :attachment_filename, :attachment_original_name,
                     CASE WHEN :submit_status = 'submitted' THEN CURRENT_TIMESTAMP ELSE NULL END)"
        );
        $save->execute([
            ':department_id' => $departmentId,
            ':year' => $year,
            ':status' => $status,
            ':attachment_filename' => $attachmentFilename,
            ':attachment_original_name' => $attachmentOriginalName,
            ':submit_status' => $status,
        ]);
        $reportId = (int) $pdo->lastInsertId();
    }

    $pdo->prepare('DELETE FROM department_annual_event_item WHERE report_id = :id')->execute([':id' => $reportId]);
    $insert = $pdo->prepare(
        'INSERT INTO department_annual_event_item
            (report_id, sort_order, event_name, event_date_from, event_date_to, purpose, planned_activities, responsible_officer, expected_outcomes, evidence)
         VALUES (:report_id, :sort_order, :event_name, :event_date_from, :event_date_to, :purpose, :planned_activities, :responsible_officer, :expected_outcomes, :evidence)'
    );
    foreach ($events as $sortOrder => $event) {
        $insert->execute([':report_id' => $reportId, ':sort_order' => $sortOrder] + $event);
    }
    $pdo->commit();

    if ($newFile && !empty($existing['attachment_filename']) && $existing['attachment_filename'] !== $newFile['filename']) {
        delete_annual_event_file((string) $existing['attachment_filename']);
    }
    set_flash('success', $status === 'submitted' ? 'Annual event report submitted.' : 'Annual event report saved as a draft.');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($newFile) {
        delete_annual_event_file($newFile['filename']);
    }
    set_flash('error', 'Could not save the annual event report: ' . $exception->getMessage());
}

redirect('department/annual_event_report.php?year=' . $year);
