<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login('department');

if (!is_post()) {
    redirect('department/annual_plan.php');
}

$departmentId = current_department_id();
$planYear = (int) ($_POST['plan_year'] ?? 0);
$periodFrom = trim((string) ($_POST['period_from'] ?? ''));
$periodTo = trim((string) ($_POST['period_to'] ?? ''));
$status = (string) ($_POST['status'] ?? 'draft');
$postedItems = $_POST['items'] ?? [];

if ($departmentId === null || $planYear < 2000 || $planYear > 2200 || !in_array($status, ['draft', 'submitted'], true)) {
    set_flash('error', 'Provide a valid annual plan year and status.');
    redirect('department/annual_plan.php');
}
$fromDate = DateTimeImmutable::createFromFormat('!Y-m-d', $periodFrom);
$toDate = DateTimeImmutable::createFromFormat('!Y-m-d', $periodTo);
if (!$fromDate || !$toDate || $fromDate->format('Y-m-d') !== $periodFrom || $toDate->format('Y-m-d') !== $periodTo || $periodFrom > $periodTo) {
    set_flash('error', 'Enter a valid plan period with an end date on or after the start date.');
    redirect('department/annual_plan.php?year=' . $planYear);
}
if (!is_array($postedItems) || count($postedItems) < 1 || count($postedItems) > 100) {
    set_flash('error', 'An annual plan must have between 1 and 100 KPI rows.');
    redirect('department/annual_plan.php?year=' . $planYear);
}

$items = [];
foreach ($postedItems as $postedItem) {
    if (!is_array($postedItem)) {
        continue;
    }
    $item = [
        'id' => (int) ($postedItem['id'] ?? 0),
        'kra' => normalize_text((string) ($postedItem['kra'] ?? '')),
        'smart_objective' => normalize_text((string) ($postedItem['smart_objective'] ?? '')),
        'kpi' => normalize_text((string) ($postedItem['kpi'] ?? '')),
        'target' => normalize_text((string) ($postedItem['target'] ?? '')),
        'target_value' => trim((string) ($postedItem['target_value'] ?? '')),
        'unit' => normalize_text((string) ($postedItem['unit'] ?? '')),
        'direction' => (string) ($postedItem['direction'] ?? 'higher'),
        'verification' => normalize_text((string) ($postedItem['verification'] ?? '')),
    ];
    if ($item['kra'] === '' && $item['smart_objective'] === '' && $item['kpi'] === '' && $item['target'] === '' && $item['verification'] === '') {
        continue;
    }
    if ($item['kra'] === '' || $item['smart_objective'] === '' || $item['kpi'] === '' || $item['target'] === '') {
        set_flash('error', 'Complete KRA, SMART objective, KPI, and target for every row.');
        redirect('department/annual_plan.php?year=' . $planYear);
    }
    if (!in_array($item['direction'], ['higher', 'lower'], true) || ($item['target_value'] !== '' && (!is_numeric($item['target_value']) || !is_finite((float) $item['target_value']))) || strlen($item['unit']) > 80) {
        set_flash('error', 'Enter a valid numeric target, unit, and preferred direction for every KPI.');
        redirect('department/annual_plan.php?year=' . $planYear);
    }
    if (strlen($item['kra']) > 250 || strlen($item['smart_objective']) > 1000 || strlen($item['kpi']) > 500 || strlen($item['target']) > 250 || strlen($item['verification']) > 1000) {
        set_flash('error', 'One or more KPI fields are too long. Shorten the text and try again.');
        redirect('department/annual_plan.php?year=' . $planYear);
    }
    $items[] = $item;
}
if (!$items) {
    set_flash('error', 'Add at least one complete KPI row before saving the plan.');
    redirect('department/annual_plan.php?year=' . $planYear);
}

$pdo = db();
$findPlan = $pdo->prepare('SELECT id, status, edit_approved_at FROM department_annual_plan WHERE department_id = :department_id AND plan_year = :plan_year LIMIT 1');
$findPlan->execute([':department_id' => $departmentId, ':plan_year' => $planYear]);
$existingPlan = $findPlan->fetch() ?: null;
if ($existingPlan && $existingPlan['status'] === 'submitted' && empty($existingPlan['edit_approved_at'])) {
    set_flash('error', 'This annual plan is locked after submission. Ask an admin to approve an edit before changing it.');
    redirect('department/annual_plan.php?year=' . $planYear);
}
try {
    $pdo->beginTransaction();
    $planId = (int) ($existingPlan['id'] ?? 0);

    if ($planId > 0) {
        $updatePlan = $pdo->prepare(
            "UPDATE department_annual_plan
             SET period_from = :period_from, period_to = :period_to, status = :status,
                 submitted_at = CASE WHEN :submission_status = 'submitted' THEN CURRENT_TIMESTAMP ELSE NULL END,
                 edit_approved_at = NULL, edit_approved_by = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND department_id = :department_id"
        );
        $updatePlan->execute([
            ':period_from' => $periodFrom,
            ':period_to' => $periodTo,
            ':status' => $status,
            ':submission_status' => $status,
            ':id' => $planId,
            ':department_id' => $departmentId,
        ]);
    } else {
        $insertPlan = $pdo->prepare(
            "INSERT INTO department_annual_plan (department_id, plan_year, period_from, period_to, status, submitted_at)
             VALUES (:department_id, :plan_year, :period_from, :period_to, :status, CASE WHEN :submission_status = 'submitted' THEN CURRENT_TIMESTAMP ELSE NULL END)"
        );
        $insertPlan->execute([
            ':department_id' => $departmentId,
            ':plan_year' => $planYear,
            ':period_from' => $periodFrom,
            ':period_to' => $periodTo,
            ':status' => $status,
            ':submission_status' => $status,
        ]);
        $planId = (int) $pdo->lastInsertId();
    }

    $retainedIds = [];
    $updateItem = $pdo->prepare(
        'UPDATE department_annual_plan_item
         SET sort_order = :sort_order, kra = :kra, smart_objective = :smart_objective, kpi = :kpi, target = :target, target_value = :target_value, unit = :unit, direction = :direction, verification = :verification
         WHERE id = :id AND plan_id = :plan_id'
    );
    $insertItem = $pdo->prepare(
        'INSERT INTO department_annual_plan_item (plan_id, sort_order, kra, smart_objective, kpi, target, target_value, unit, direction, verification)
         VALUES (:plan_id, :sort_order, :kra, :smart_objective, :kpi, :target, :target_value, :unit, :direction, :verification)'
    );
    foreach ($items as $sortOrder => $item) {
        $values = [
            ':plan_id' => $planId,
            ':sort_order' => $sortOrder,
            ':kra' => $item['kra'],
            ':smart_objective' => $item['smart_objective'],
            ':kpi' => $item['kpi'],
            ':target' => $item['target'],
            ':target_value' => $item['target_value'] === '' ? null : (float) $item['target_value'],
            ':unit' => $item['unit'],
            ':direction' => $item['direction'],
            ':verification' => $item['verification'],
        ];
        if ($item['id'] > 0) {
            $values[':id'] = $item['id'];
            $updateItem->execute($values);
            if ($updateItem->rowCount() === 0) {
                $checkItem = $pdo->prepare('SELECT id FROM department_annual_plan_item WHERE id = :id AND plan_id = :plan_id LIMIT 1');
                $checkItem->execute([':id' => $item['id'], ':plan_id' => $planId]);
                if (!$checkItem->fetchColumn()) {
                    throw new RuntimeException('A KPI row did not belong to this department plan.');
                }
            }
            $retainedIds[] = $item['id'];
        } else {
            $insertItem->execute($values);
            $retainedIds[] = (int) $pdo->lastInsertId();
        }
    }

    $idList = implode(',', array_map('intval', $retainedIds));
    $pdo->exec('DELETE FROM department_annual_plan_item WHERE plan_id = ' . $planId . ' AND id NOT IN (' . $idList . ')');
    $pdo->commit();
    set_flash('success', $status === 'submitted' ? 'Annual plan submitted.' : 'Annual plan saved as a draft.');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    set_flash('error', 'Could not save the annual plan: ' . $exception->getMessage());
}

redirect('department/annual_plan.php?year=' . $planYear);
