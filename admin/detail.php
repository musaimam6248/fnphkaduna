<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';

require_login('admin');

$pdo = db();
$submissionId = (int) ($_GET['submission_id'] ?? 0);

if ($submissionId <= 0) {
    set_flash('error', 'Invalid submission selected.');
    redirect('admin/dashboard.php');
}

$stmt = $pdo->prepare(
    "SELECT s.id, s.month, s.year, s.score, s.final_remarks, s.reason_one, s.reason_two, s.status, s.created_at,
            st.name AS staff_name, st.rank AS staff_rank, st.photo AS staff_photo,
            d.name AS department_name, d.hod AS department_hod
     FROM submission s
     INNER JOIN staff st ON st.id = s.staff_id
     INNER JOIN department d ON d.id = s.department_id
     WHERE s.id = :id
     LIMIT 1"
);
$stmt->execute([':id' => $submissionId]);
$submission = $stmt->fetch();

if (!$submission) {
    set_flash('error', 'Submission record not found.');
    redirect('admin/dashboard.php');
}

$evalStmt = $pdo->prepare(
    "SELECT category, criterion_key, criterion_label, score
     FROM performance_evaluation
     WHERE submission_id = :submission_id
     ORDER BY category ASC, id ASC"
);
$evalStmt->execute([':submission_id' => $submissionId]);
$evalRows = $evalStmt->fetchAll();

$evalByCategory = [];
$totalScore = 0;
foreach ($evalRows as $row) {
    $evalByCategory[$row['category']][] = $row;
    $totalScore += (int) $row['score'];
}
$criteriaCount = count($evalRows);
$averageScore = $criteriaCount > 0 ? round($totalScore / $criteriaCount, 2) : 0;

render_header('Submission Detail');
?>
<div class="mb-4 fade-rise">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h1 class="h3 mb-1">Staff Evaluation Details</h1>
            <p class="text-muted mb-0">
                <?= e(format_month_year((int) $submission['month'], (int) $submission['year'])) ?> nomination breakdown
            </p>
        </div>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(app_url('admin/dashboard.php')) ?>">
            &larr; Back to Dashboard
        </a>
    </div>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card card-soft h-100">
                <div class="card-body p-3 p-md-4 text-center">
                    <img src="<?= e(upload_url('staff', $submission['staff_photo'])) ?>"
                         alt="<?= e($submission['staff_name']) ?>"
                         class="staff-photo mx-auto mb-3"
                         style="max-width: 220px; object-fit: cover;">
                    <h2 class="h5 mb-1"><?= e($submission['staff_name']) ?></h2>
                    <p class="badge text-bg-primary mb-2"><?= e($submission['staff_rank']) ?></p>
                    <p class="text-muted small mb-3"><?= e($submission['department_name']) ?> (HOD: <?= e($submission['department_hod']) ?>)</p>

                    <div class="row g-2 text-start pt-2 border-top">
                        <div class="col-6">
                            <span class="small text-muted d-block">Grand Total</span>
                            <span class="h5 mb-0 fw-bold"><?= e((string) $totalScore) ?></span>
                        </div>
                        <div class="col-6">
                            <span class="small text-muted d-block">Average</span>
                            <span class="h5 mb-0 fw-bold"><?= e((string) $averageScore) ?> / 5</span>
                        </div>
                    </div>

                    <?php if (!empty($submission['final_remarks'])): ?>
                        <div class="mt-3 text-start p-3 bg-light rounded">
                            <span class="small fw-bold text-muted d-block mb-1">HOD Final Remarks:</span>
                            <p class="small mb-0"><?= e($submission['final_remarks']) ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card card-soft h-100">
                <div class="card-body p-3 p-md-4">
                    <h2 class="h5 mb-3">Evaluation Score Breakdown</h2>

                    <?php if (!$evalByCategory): ?>
                        <div class="alert alert-secondary">No detailed performance evaluation records available for this submission.</div>
                    <?php else: ?>
                        <div class="vstack gap-3">
                            <?php foreach ($evalByCategory as $category => $items): ?>
                                <?php
                                $categorySum = array_sum(array_column($items, 'score'));
                                $categoryMax = count($items) * 5;
                                ?>
                                <div class="border rounded p-3">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <h3 class="h6 mb-0 text-capitalize fw-bold" style="color: var(--brand-700);">
                                            <?= e(str_replace('_', ' ', $category)) ?>
                                        </h3>
                                        <span class="badge text-bg-secondary">
                                            <?= e((string) $categorySum) ?> / <?= e((string) $categoryMax) ?>
                                        </span>
                                    </div>
                                    <div class="table-wrap">
                                        <table class="table table-sm mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Criterion</th>
                                                    <th class="text-end" style="width: 80px;">Score</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($items as $item): ?>
                                                    <tr>
                                                        <td><?= e($item['criterion_label']) ?></td>
                                                        <td class="text-end fw-semibold"><?= e((string) $item['score']) ?> / 5</td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
render_footer();
