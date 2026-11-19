<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login('admin');

if (!is_post()) {
    redirect('admin/monthly_report_review.php');
}

$reportId = (int) ($_POST['report_id'] ?? 0);
$decision = (string) ($_POST['decision'] ?? '');
$overallCommentValue = $_POST['overall_comment'] ?? '';
$overallComment = is_string($overallCommentValue) ? trim($overallCommentValue) : '';
$departmentId = max(0, (int) ($_POST['department_id'] ?? 0));
$month = max(0, min(12, (int) ($_POST['month'] ?? 0)));
$year = (int) ($_POST['year'] ?? 0);
$filters = '&department_id=' . $departmentId . '&month=' . $month . '&year=' . $year;
$returnUrl = 'admin/monthly_report_review.php?report_id=' . $reportId . $filters;

if ($reportId < 1 || !in_array($decision, ['returned', 'reviewed'], true)) {
    set_flash('error', 'Choose a valid monthly report review action.');
    redirect('admin/monthly_report_review.php');
}
if ($decision === 'returned' && $overallComment === '') {
    set_flash('error', 'Add overall feedback before returning the report.');
    redirect($returnUrl);
}
if (mb_strlen($overallComment) > 5000) {
    set_flash('error', 'Overall feedback must be 5,000 characters or fewer.');
    redirect($returnUrl);
}

$pdo = db();
$admin = current_user();
$measureComments = $_POST['measure_comments'] ?? [];
if (!is_array($measureComments)) {
    $measureComments = [];
}

try {
    $pdo->beginTransaction();
    $reportStmt = $pdo->prepare(
        'SELECT r.id, r.department_id, r.report_month, r.report_year, d.name AS department_name
         FROM department_monthly_report r
         INNER JOIN department d ON d.id = r.department_id
         WHERE r.id = :id LIMIT 1'
    );
    $reportStmt->execute([':id' => $reportId]);
    $report = $reportStmt->fetch();
    if (!$report) {
        throw new RuntimeException('The monthly report could not be found.');
    }

    $validMeasureStmt = $pdo->prepare('SELECT id FROM department_monthly_kpi_update WHERE id = :id AND report_id = :report_id LIMIT 1');
    $saveMeasureComment = $pdo->prepare(
        "INSERT INTO department_monthly_report_review (report_id, kpi_update_id, admin_id, action, comment)
         VALUES (:report_id, :kpi_update_id, :admin_id, 'comment', :comment)"
    );
    foreach ($measureComments as $measureId => $commentValue) {
        $measureId = (int) $measureId;
        $comment = is_string($commentValue) ? trim($commentValue) : '';
        if ($measureId < 1 || $comment === '') {
            continue;
        }
        if (mb_strlen($comment) > 2000) {
            throw new RuntimeException('Each measure comment must be 2,000 characters or fewer.');
        }
        $validMeasureStmt->execute([':id' => $measureId, ':report_id' => $reportId]);
        if (!$validMeasureStmt->fetchColumn()) {
            throw new RuntimeException('A measure comment was attached to a row outside this report.');
        }
        $saveMeasureComment->execute([
            ':report_id' => $reportId,
            ':kpi_update_id' => $measureId,
            ':admin_id' => (int) $admin['id'],
            ':comment' => $comment,
        ]);
    }

    $history = $pdo->prepare(
        'INSERT INTO department_monthly_report_review (report_id, kpi_update_id, admin_id, action, comment)
         VALUES (:report_id, NULL, :admin_id, :action, :comment)'
    );
    $history->execute([
        ':report_id' => $reportId,
        ':admin_id' => (int) $admin['id'],
        ':action' => $decision,
        ':comment' => $overallComment,
    ]);

    if ($decision === 'returned') {
        $update = $pdo->prepare(
            "UPDATE department_monthly_report
             SET status = 'submitted', review_status = 'returned', reviewed_at = NULL,
                 edit_approved_at = CURRENT_TIMESTAMP, edit_approved_by = :admin_id,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id"
        );
        $update->execute([':admin_id' => (int) $admin['id'], ':id' => $reportId]);
        $notice = 'Monthly KPI report for ' . month_label((int) $report['report_month']) . ' ' . $report['report_year']
            . ' was returned for revision. Admin feedback: ' . $overallComment;
        $pdo->prepare('INSERT INTO notifications (department_id, message) VALUES (:department_id, :message)')
            ->execute([':department_id' => (int) $report['department_id'], ':message' => $notice]);
        $flashMessage = 'Report returned to ' . $report['department_name'] . ' for revision. The department was notified.';
    } else {
        $update = $pdo->prepare(
            "UPDATE department_monthly_report
             SET status = 'reviewed', review_status = 'reviewed', reviewed_at = CURRENT_TIMESTAMP,
                 edit_approved_at = NULL, edit_approved_by = NULL, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id"
        );
        $update->execute([':id' => $reportId]);
        $pdo->prepare('INSERT INTO notifications (department_id, message) VALUES (:department_id, :message)')
            ->execute([
                ':department_id' => (int) $report['department_id'],
                ':message' => 'Monthly KPI report for ' . month_label((int) $report['report_month']) . ' ' . $report['report_year'] . ' has been reviewed.',
            ]);
        $flashMessage = 'Comments saved and report marked as reviewed.';
    }

    $pdo->commit();
    set_flash('success', $flashMessage);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    set_flash('error', 'Could not save the monthly report review: ' . $exception->getMessage());
}

redirect($returnUrl);
