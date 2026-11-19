<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/image.php';
require_login('department');

if (!is_post()) {
    redirect('department/monthly_kpi.php');
}

$departmentId = current_department_id();
$submissionMode = (string) ($_POST['submission_mode'] ?? 'kpi');
$month = (int) ($_POST['report_month'] ?? 0);
$year = (int) ($_POST['report_year'] ?? 0);
$formReturnUrl = 'department/monthly_kpi.php?month=' . $month . '&year=' . $year;
$periodFrom = trim((string) ($_POST['period_from'] ?? ''));
$periodTo = trim((string) ($_POST['period_to'] ?? ''));
$narrative = sanitize_rich_text_html((string) ($_POST['narrative_html'] ?? ''));
$postedUpdates = $_POST['kpi_updates'] ?? [];

if (!in_array($submissionMode, ['kpi', 'monthly_report'], true)) {
    set_flash('error', 'Choose whether to submit KPI data or a monthly report.');
    redirect('department/monthly_kpi.php');
}
$returnPage = $submissionMode === 'monthly_report' ? 'monthly_report.php' : 'monthly_kpi.php';
$formReturnUrl = 'department/' . $returnPage . '?month=' . $month . '&year=' . $year;
$formReturnUrl .= '&mode=' . rawurlencode($submissionMode);

if ($departmentId === null || $month < 1 || $month > 12 || $year < 2000 || $year > 2200) {
    set_flash('error', 'Choose a valid month and year for the report.');
    redirect('department/monthly_kpi.php');
}
$fromDate = DateTimeImmutable::createFromFormat('!Y-m-d', $periodFrom);
$toDate = DateTimeImmutable::createFromFormat('!Y-m-d', $periodTo);
if (!$fromDate || !$toDate || $fromDate->format('Y-m-d') !== $periodFrom || $toDate->format('Y-m-d') !== $periodTo || $periodFrom > $periodTo) {
    set_flash('error', 'Enter a valid reporting period with an end date on or after the start date.');
    redirect($formReturnUrl);
}

$pdo = db();
$planItems = [];
if ($submissionMode === 'kpi') {
    $planStmt = $pdo->prepare('SELECT id FROM department_annual_plan WHERE department_id = :department_id AND plan_year = :plan_year AND status = \'submitted\' LIMIT 1');
    $planStmt->execute([':department_id' => $departmentId, ':plan_year' => $year]);
    $planId = (int) ($planStmt->fetchColumn() ?: 0);
    if ($planId > 0) {
        $planItemsStmt = $pdo->prepare('SELECT id, sort_order, kra, smart_objective, kpi, target, target_value, unit, direction FROM department_annual_plan_item WHERE plan_id = :plan_id ORDER BY sort_order, id');
        $planItemsStmt->execute([':plan_id' => $planId]);
        $planItems = $planItemsStmt->fetchAll();
    }
}
if ($submissionMode === 'kpi' && !is_array($postedUpdates)) {
    set_flash('error', 'The monthly KPI updates were not submitted correctly.');
    redirect($formReturnUrl);
}
if (!is_array($postedUpdates)) {
    $postedUpdates = [];
}

$validatedUpdates = [];
foreach ($submissionMode === 'kpi' ? $planItems : [] as $item) {
    $itemId = (int) $item['id'];
    $posted = $postedUpdates[$itemId] ?? $postedUpdates[(string) $itemId] ?? null;
    if (!is_array($posted)) {
        set_flash('error', 'Complete the KPI updates for every row in the annual plan.');
        redirect($formReturnUrl);
    }
    $achievement = normalize_text((string) ($posted['achievement'] ?? ''));
    $kra = normalize_text((string) ($posted['kra'] ?? $item['kra']));
    $smartObjective = normalize_text((string) ($posted['smart_objective'] ?? $item['smart_objective']));
    $kpi = normalize_text((string) ($posted['kpi'] ?? $item['kpi']));
    $target = normalize_text((string) ($posted['target'] ?? $item['target']));
    $evidence = normalize_text((string) ($posted['evidence'] ?? ''));
    $commentary = '';
    $nextStep = '';
    $percentageInput = trim((string) ($posted['percentage'] ?? ''));
    $actualInput = trim((string) ($posted['actual_value'] ?? ''));
    $actualValue = null;
    $varianceValue = null;
    if ($item['target_value'] !== null) {
        if ($actualInput === '' || !is_numeric($actualInput) || !is_finite((float) $actualInput)) {
            set_flash('error', 'Enter a numeric actual value for every KPI with a numeric target.');
            redirect($formReturnUrl);
        }
        $actualValue = (float) $actualInput;
        $targetValue = (float) $item['target_value'];
        if ($item['direction'] === 'lower') {
            $varianceValue = $targetValue - $actualValue;
            $percentage = $actualValue == 0.0 ? ($targetValue == 0.0 ? 100.0 : 1000.0) : ($targetValue / $actualValue) * 100;
        } else {
            $varianceValue = $actualValue - $targetValue;
            $percentage = $targetValue == 0.0 ? ($actualValue >= 0.0 ? 100.0 : 0.0) : ($actualValue / $targetValue) * 100;
        }
        // Keep persisted achievement consistent with the 0–1000% range shown in the form.
        $percentage = min(1000.0, max(0.0, $percentage));
        $achievement = sprintf('%g', $actualValue) . ($item['unit'] !== '' ? ' ' . $item['unit'] : '');
    } else {
        if ($achievement === '' || !is_numeric($percentageInput)) {
            set_flash('error', 'Enter an achievement and completion percentage for every KPI with a text target.');
            redirect($formReturnUrl);
        }
        $percentage = (float) $percentageInput;
        $varianceValue = $percentage - 100.0;
    }
    if ($kra === '' || $smartObjective === '' || $kpi === '' || $target === '' || $achievement === '' || strlen($kra) > 250 || strlen($smartObjective) > 1000 || strlen($kpi) > 500 || strlen($target) > 250 || strlen($achievement) > 2000 || strlen($evidence) > 2000 || !is_finite($percentage)) {
        set_flash('error', 'Complete all KPI details and enter a valid achievement percentage for every row.');
        redirect($formReturnUrl);
    }
    if ($percentage < 0 || $percentage > 1000) {
        set_flash('error', 'Achievement percentages must be between 0 and 1000.');
        redirect($formReturnUrl);
    }
    $validatedUpdates[] = [
        'annual_plan_item_id' => $itemId,
        'form_key' => (string) $itemId,
        'sort_order' => (int) $item['sort_order'],
        'kra' => $kra,
        'smart_objective' => $smartObjective,
        'kpi' => $kpi,
        'target' => $target,
        'target_value' => $item['target_value'],
        'unit' => $item['unit'],
        'direction' => $item['direction'],
        'actual_value' => $actualValue,
        'variance_value' => $varianceValue,
        'achievement' => $achievement,
        'percentage' => $percentage,
        'status' => $percentage >= 100 ? 'on_track' : ($percentage >= 80 ? 'at_risk' : 'off_track'),
        'evidence' => $evidence,
        'commentary' => $commentary,
        'next_step' => $nextStep,
    ];
}

$extraRowCount = 0;
foreach ($submissionMode === 'kpi' ? $postedUpdates : [] as $rowKey => $posted) {
    if (!is_string($rowKey) || !str_starts_with($rowKey, 'new_')) {
        continue;
    }
    if (!is_array($posted)) {
        set_flash('error', 'An added KPI row was not submitted correctly.');
        redirect($formReturnUrl);
    }
    $extraRowCount++;
    if ($extraRowCount > 50) {
        set_flash('error', 'A monthly report can contain up to 50 additional KPI rows.');
        redirect($formReturnUrl);
    }
    $kra = normalize_text((string) ($posted['kra'] ?? ''));
    $smartObjective = normalize_text((string) ($posted['smart_objective'] ?? ''));
    $kpi = normalize_text((string) ($posted['kpi'] ?? ''));
    $target = normalize_text((string) ($posted['target'] ?? ''));
    $achievement = normalize_text((string) ($posted['achievement'] ?? ''));
    $evidence = normalize_text((string) ($posted['evidence'] ?? ''));
    $commentary = '';
    $nextStep = '';
    $percentageInput = trim((string) ($posted['percentage'] ?? ''));
    if ($kra === '' || $smartObjective === '' || $kpi === '' || $target === '' || $achievement === '' || !is_numeric($percentageInput)) {
        set_flash('error', 'Complete each added KPI row and enter its achievement percentage.');
        redirect($formReturnUrl);
    }
    $percentage = (float) $percentageInput;
    $varianceValue = $percentage - 100.0;
    if (strlen($kra) > 250 || strlen($smartObjective) > 1000 || strlen($kpi) > 500 || strlen($target) > 250 || strlen($achievement) > 2000 || strlen($evidence) > 2000 || !is_finite($percentage) || $percentage < 0 || $percentage > 1000) {
        set_flash('error', 'Check the text lengths and percentage for each added KPI row.');
        redirect($formReturnUrl);
    }
    $validatedUpdates[] = [
        'annual_plan_item_id' => null,
        'form_key' => $rowKey,
        'sort_order' => count($validatedUpdates),
        'kra' => $kra,
        'smart_objective' => $smartObjective,
        'kpi' => $kpi,
        'target' => $target,
        'target_value' => null,
        'unit' => '',
        'direction' => 'higher',
        'actual_value' => null,
        'variance_value' => $varianceValue,
        'achievement' => $achievement,
        'percentage' => $percentage,
        'status' => $percentage >= 100 ? 'on_track' : ($percentage >= 80 ? 'at_risk' : 'off_track'),
        'evidence' => $evidence,
        'commentary' => $commentary,
        'next_step' => $nextStep,
    ];
}

if ($submissionMode === 'kpi' && !$validatedUpdates) {
    set_flash('error', 'Add at least one complete KPI measure before submitting the monthly report.');
    redirect($formReturnUrl);
}

$existingStmt = $pdo->prepare('SELECT id, status, edit_approved_at, attachment_filename, attachment_original_name, narrative_html FROM department_monthly_report WHERE department_id = :department_id AND report_month = :month AND report_year = :year LIMIT 1');
$existingStmt->execute([':department_id' => $departmentId, ':month' => $month, ':year' => $year]);
$existingReport = $existingStmt->fetch() ?: null;
if ($submissionMode === 'kpi' && $existingReport && empty($existingReport['edit_approved_at'])) {
    set_flash('error', 'This monthly KPI report is locked after submission. Ask an admin to approve an edit before changing it.');
    redirect($formReturnUrl);
}
if ($submissionMode === 'monthly_report' && $existingReport && empty($existingReport['edit_approved_at']) && ($existingReport['review_status'] ?? '') !== 'returned' && (trim(strip_tags((string) ($existingReport['narrative_html'] ?? ''))) !== '' || !empty($existingReport['attachment_filename']))) {
    set_flash('error', 'The monthly report details are locked after submission. Ask an admin to approve an edit before changing them.');
    redirect($formReturnUrl);
}
$oldKpiFiles = [];
if ($existingReport && $submissionMode === 'kpi') {
    $oldKpiFilesStmt = $pdo->prepare('SELECT id, annual_plan_item_id, achievement_attachment_filename, evidence_attachment_filename FROM department_monthly_kpi_update WHERE report_id = :report_id');
    $oldKpiFilesStmt->execute([':report_id' => (int) $existingReport['id']]);
    foreach ($oldKpiFilesStmt->fetchAll() as $oldKpiFile) {
        $formKey = !empty($oldKpiFile['annual_plan_item_id']) ? (string) $oldKpiFile['annual_plan_item_id'] : 'new_saved_' . (int) $oldKpiFile['id'];
        $oldKpiFiles[$formKey] = $oldKpiFile;
    }
}
$newAttachment = null;
$oldAttachment = $existingReport['attachment_filename'] ?? null;
$newKpiFiles = [];

try {
    $newAttachment = upload_monthly_report_file($_FILES['report_file'] ?? []);
    $attachmentFilename = $newAttachment['filename'] ?? $oldAttachment;
    $attachmentOriginalName = $newAttachment['original_name'] ?? ($existingReport['attachment_original_name'] ?? null);
    $savedNarrative = trim(strip_tags($narrative)) !== '' ? $narrative : ($existingReport['narrative_html'] ?? '');
    if ($submissionMode === 'monthly_report' && trim(strip_tags((string) $savedNarrative)) === '' && $newAttachment === null && empty($oldAttachment)) {
        throw new InvalidArgumentException('Enter the monthly report narrative or attach a report file.');
    }
    foreach ($validatedUpdates as &$update) {
        $oldFile = $oldKpiFiles[$update['form_key']] ?? [];
        foreach (['achievement', 'evidence'] as $fileKind) {
            $files = $_FILES[$fileKind . '_files'] ?? [];
            $file = [];
            foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $part) {
                $file[$part] = is_array($files[$part] ?? null) ? ($files[$part][$update['form_key']] ?? null) : null;
            }
            if (!isset($file['error'])) {
                $file['error'] = UPLOAD_ERR_NO_FILE;
            }
            $uploadedFile = upload_monthly_report_file($file);
            $filenameKey = $fileKind . '_attachment_filename';
            $originalKey = $fileKind . '_attachment_original_name';
            $update[$filenameKey] = $uploadedFile['filename'] ?? ($oldFile[$filenameKey] ?? null);
            $update[$originalKey] = $uploadedFile['original_name'] ?? ($oldFile[$originalKey] ?? null);
            if ($uploadedFile !== null) {
                $newKpiFiles[] = $uploadedFile['filename'];
            }
        }
    }
    unset($update);
    $pdo->beginTransaction();
    $saveReport = $pdo->prepare(
        "INSERT INTO department_monthly_report
            (department_id, report_month, report_year, period_from, period_to, narrative_html, attachment_filename, attachment_original_name, status, review_status, submitted_at, reviewed_at)
         VALUES
            (:department_id, :report_month, :report_year, :period_from, :period_to, :narrative_html, :attachment_filename, :attachment_original_name, 'submitted', 'pending', CURRENT_TIMESTAMP, NULL)
         ON CONFLICT(department_id, report_month, report_year) DO UPDATE SET
            period_from = excluded.period_from,
            period_to = excluded.period_to,
            narrative_html = excluded.narrative_html,
            attachment_filename = excluded.attachment_filename,
            attachment_original_name = excluded.attachment_original_name,
            status = 'submitted',
            review_status = 'pending',
            submitted_at = CURRENT_TIMESTAMP,
            reviewed_at = NULL,
            edit_approved_at = NULL,
            edit_approved_by = NULL,
            updated_at = CURRENT_TIMESTAMP"
    );
    $saveReport->execute([
        ':department_id' => $departmentId,
        ':report_month' => $month,
        ':report_year' => $year,
        ':period_from' => $periodFrom,
        ':period_to' => $periodTo,
        ':narrative_html' => $savedNarrative,
        ':attachment_filename' => $attachmentFilename,
        ':attachment_original_name' => $attachmentOriginalName,
    ]);

    $reportIdStmt = $pdo->prepare('SELECT id FROM department_monthly_report WHERE department_id = :department_id AND report_month = :month AND report_year = :year LIMIT 1');
    $reportIdStmt->execute([':department_id' => $departmentId, ':month' => $month, ':year' => $year]);
    $reportId = (int) $reportIdStmt->fetchColumn();
    if ($submissionMode === 'kpi') {
        $pdo->prepare('DELETE FROM department_monthly_kpi_update WHERE report_id = :report_id')->execute([':report_id' => $reportId]);

        $insertUpdate = $pdo->prepare(
        'INSERT INTO department_monthly_kpi_update
            (report_id, annual_plan_item_id, sort_order, kra, smart_objective, kpi, target, target_value, unit, direction, achievement, achievement_percent, actual_value, variance_value, status, evidence, achievement_attachment_filename, achievement_attachment_original_name, evidence_attachment_filename, evidence_attachment_original_name, commentary, next_step)
         VALUES
            (:report_id, :annual_plan_item_id, :sort_order, :kra, :smart_objective, :kpi, :target, :target_value, :unit, :direction, :achievement, :achievement_percent, :actual_value, :variance_value, :status, :evidence, :achievement_attachment_filename, :achievement_attachment_original_name, :evidence_attachment_filename, :evidence_attachment_original_name, :commentary, :next_step)'
        );
        foreach ($validatedUpdates as $update) {
            $insertUpdate->execute([
            ':report_id' => $reportId,
            ':annual_plan_item_id' => $update['annual_plan_item_id'],
            ':sort_order' => $update['sort_order'],
            ':kra' => $update['kra'],
            ':smart_objective' => $update['smart_objective'],
            ':kpi' => $update['kpi'],
            ':target' => $update['target'],
            ':target_value' => $update['target_value'],
            ':unit' => $update['unit'],
            ':direction' => $update['direction'],
            ':achievement' => $update['achievement'],
            ':achievement_percent' => $update['percentage'],
            ':actual_value' => $update['actual_value'],
            ':variance_value' => $update['variance_value'],
            ':status' => $update['status'],
            ':evidence' => $update['evidence'],
            ':achievement_attachment_filename' => $update['achievement_attachment_filename'],
            ':achievement_attachment_original_name' => $update['achievement_attachment_original_name'],
            ':evidence_attachment_filename' => $update['evidence_attachment_filename'],
            ':evidence_attachment_original_name' => $update['evidence_attachment_original_name'],
            ':commentary' => $update['commentary'],
            ':next_step' => $update['next_step'],
            ]);
        }
    }

    $pdo->commit();
    $keptKpiFiles = [];
    foreach ($validatedUpdates as $update) {
        foreach (['achievement_attachment_filename', 'evidence_attachment_filename'] as $fileKey) {
            if (!empty($update[$fileKey])) {
                $keptKpiFiles[] = $update[$fileKey];
            }
        }
    }
    foreach ($oldKpiFiles as $oldFile) {
        foreach (['achievement_attachment_filename', 'evidence_attachment_filename'] as $fileKey) {
            if (!empty($oldFile[$fileKey]) && !in_array($oldFile[$fileKey], $keptKpiFiles, true)) {
                delete_monthly_report_file((string) $oldFile[$fileKey]);
            }
        }
    }
    if ($newAttachment !== null && $oldAttachment && $oldAttachment !== $newAttachment['filename']) {
        delete_monthly_report_file((string) $oldAttachment);
    }
    $submittedLabel = $submissionMode === 'kpi' ? 'KPI submission' : 'Monthly report';
    set_flash('success', $submittedLabel . ' submitted for ' . format_month_year($month, $year) . '.');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($newAttachment !== null) {
        delete_monthly_report_file($newAttachment['filename']);
    }
    foreach ($newKpiFiles as $newKpiFile) {
        delete_monthly_report_file($newKpiFile);
    }
    set_flash('error', 'Could not submit the monthly report: ' . $exception->getMessage());
}

redirect($formReturnUrl);
