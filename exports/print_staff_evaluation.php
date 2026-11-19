<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_login('admin');

$month = (int)($_GET['month'] ?? 0);
$year = (int)($_GET['year'] ?? 0);

if ($month < 1 || $month > 12 || $year < 2000) {
    redirect('admin/dashboard.php');
}

$pdo = db();

$stmt = $pdo->prepare("
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
     WHERE s.month = :month AND s.year = :year
     GROUP BY s.id, st.name, st.rank, st.photo, d.name, s.month, s.year, s.final_remarks
     ORDER BY grand_total DESC
");
$stmt->execute(['month' => $month, 'year' => $year]);
$rows = $stmt->fetchAll();

$monthName = date('F', mktime(0, 0, 0, $month, 10));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>FNPH Kaduna - Staff Evaluation Scores (<?= e($monthName) ?> <?= e((string)$year) ?>)</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; color: #0b1d33; }
        .report-header { display: flex; align-items: center; gap: 16px; margin-bottom: 18px; border-bottom: 3px solid #1d6b38; padding-bottom: 12px; }
        .report-header img { height: 60px; width: auto; object-fit: contain; }
        .hospital-title { margin: 0; font-size: 19px; color: #174526; text-transform: uppercase; font-weight: 800; }
        .report-subtitle { margin: 3px 0 0; font-size: 14px; color: #1d6b38; font-weight: 600; }
        .meta { margin: 4px 0 0; color: #555; font-size: 12px; }
        table { border-collapse: collapse; width: 100%; font-size: 11px; margin-top: 10px; }
        th, td { border: 1px solid #aab69a; padding: 6px 8px; vertical-align: middle; text-align: left; }
        th { background: #f5f6e9; color: #174526; font-weight: bold; }
        tr:nth-child(even) { background: #f9fbfd; }
        .text-center { text-align: center; }
        .text-end { text-align: right; }
        .fw-bold { font-weight: bold; }
        .report-tools { display:flex; gap:10px; margin: 0 0 12px; }
        .report-tools a,.report-tools button { border:1px solid #1d6b38; border-radius:7px; padding:8px 12px; background:#fff; color:#174526; font-weight:700; text-decoration:none; cursor:pointer; }
        .report-tools button { background:#1d6b38; color:#fff; }
        @media print {
            body { margin: 0; }
            .report-tools { display:none; }
        }
    </style>
</head>
<body>
<div class="report-tools"><a class="btn btn-outline-secondary" href="<?= e(app_url('admin/dashboard.php#admin-evaluation')) ?>">Back to evaluations</a><button class="btn btn-primary" type="button" onclick="window.print()">Print this report</button></div>
<div class="report-header">
    <img src="<?= e(app_url('assets/fnph_logo.png')) ?>" alt="FNPH Logo">
    <div>
        <h1 class="hospital-title">Federal Neuro-Psychiatric Hospital, Kaduna</h1>
        <h2 class="report-subtitle">Staff Evaluation Scores - <?= e($monthName) ?> <?= e((string)$year) ?></h2>
        <p class="meta">Generated on <?= e(date('d M Y, h:i A')) ?></p>
    </div>
</div>

<table>
    <thead>
    <tr>
        <th style="width:3%">#</th>
        <th style="width:6%">Photo</th>
        <th style="width:12%">Department</th>
        <th style="width:15%">Name</th>
        <th style="width:5%" class="text-end">Lead</th>
        <th style="width:5%" class="text-end">Attitude</th>
        <th style="width:5%" class="text-end">Innov</th>
        <th style="width:5%" class="text-end">Interpers</th>
        <th style="width:5%" class="text-end">Learn</th>
        <th style="width:5%" class="text-end">Prod</th>
        <th style="width:5%" class="text-end">Punct</th>
        <th style="width:7%" class="text-end fw-bold">Total</th>
        <th style="width:5%" class="text-end">Avg</th>
        <th style="width:15%">HOD Remarks</th>
    </tr>
    </thead>
    <tbody>
    <?php if (!$rows): ?>
        <tr><td colspan="14" class="text-center text-muted">No staff records recorded for this month.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $index => $row): ?>
        <?php
        $criteriaCount = (int) $row['criteria_count'];
        $avgScore = $criteriaCount > 0 ? round($row['grand_total'] / $criteriaCount, 1) : 0;
        ?>
        <tr>
            <td><?= $index + 1 ?></td>
            <td style="text-align: center;">
            <img src="<?= e(upload_url('staff', $row['staff_photo'])) ?>" alt="<?= e($row['staff_name']) ?>" style="width: 100px; height: 100px; object-fit: cover; border-radius: 6px; border: 1px solid #aab69a; display: inline-block;">
            </td>
            <td><?= e($row['department_name']) ?></td>
            <td><strong><?= e($row['staff_name']) ?></strong><br><small style="color: #666;"><?= e($row['staff_rank']) ?></small></td>
            <td class="text-end"><?= e((string)$row['leadership_total']) ?></td>
            <td class="text-end"><?= e((string)$row['positive_attitude_total']) ?></td>
            <td class="text-end"><?= e((string)$row['innovation_total']) ?></td>
            <td class="text-end"><?= e((string)$row['interpersonal_skills_total']) ?></td>
            <td class="text-end"><?= e((string)$row['willingness_to_learn_total']) ?></td>
            <td class="text-end"><?= e((string)$row['productivity_total']) ?></td>
            <td class="text-end"><?= e((string)$row['punctuality_total']) ?></td>
            <td class="text-end fw-bold"><?= e((string)$row['grand_total']) ?></td>
            <td class="text-end"><?= e((string)$avgScore) ?></td>
            <td><?= e($row['final_remarks'] ?? '') ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

</body>
</html>
