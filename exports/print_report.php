<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_login('admin');

$departmentId = (int) ($_GET['department_id'] ?? 0);
$month = (int) ($_GET['month'] ?? 0);
$year = (int) ($_GET['year'] ?? 0);
$where = [];
$params = [];
if ($departmentId > 0) {
    $where[] = 's.department_id = :department_id';
    $params[':department_id'] = $departmentId;
}
if ($month >= 1 && $month <= 12) {
    $where[] = 's.month = :month';
    $params[':month'] = $month;
}
if ($year >= 2000 && $year <= 2200) {
    $where[] = 's.year = :year';
    $params[':year'] = $year;
}
$stmt = db()->prepare(
    "SELECT s.month, s.year, s.score, d.name AS department_name,
            st.name AS staff_name, st.rank AS staff_rank, st.photo AS staff_photo
     FROM submission s
     INNER JOIN department d ON d.id = s.department_id
     INNER JOIN staff st ON st.id = s.staff_id
     " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
     ORDER BY s.year DESC, s.month DESC, d.name ASC"
);
$stmt->execute($params);
$submissions = $stmt->fetchAll();
$periodLabel = $month >= 1 && $month <= 12 && $year >= 2000
    ? format_month_year($month, $year)
    : ($year >= 2000 ? (string) $year : ($month >= 1 && $month <= 12 ? month_label($month) . ' — all years' : 'All reporting periods'));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>FNPH Kaduna - Department Staff Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; color: #0b1d33; }
        .report-header { display: flex; align-items: center; gap: 16px; margin-bottom: 18px; border-bottom: 3px solid #1d6b38; padding-bottom: 12px; }
        .report-header img { height: 60px; width: auto; object-fit: contain; }
        .hospital-title { margin: 0; font-size: 19px; color: #174526; text-transform: uppercase; font-weight: 800; }
        .report-subtitle { margin: 3px 0 0; font-size: 14px; color: #1d6b38; font-weight: 600; }
        .meta { margin: 4px 0 0; color: #555; font-size: 12px; }
        table { border-collapse: collapse; width: 100%; font-size: 12px; margin-top: 10px; }
        th, td { border: 1px solid #aab69a; padding: 8px 10px; vertical-align: top; text-align: left; }
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
<div class="report-tools"><a class="btn btn-outline-secondary" href="<?= e(app_url('admin/department_reports.php')) ?>">Back to reports</a><button class="btn btn-primary" type="button" onclick="window.print()">Print this report</button></div>
<div class="report-header">
    <img src="<?= e(app_url('assets/fnph_logo.png')) ?>" alt="FNPH Logo">
    <div>
        <h1 class="hospital-title">Federal Neuro-Psychiatric Hospital, Kaduna</h1>
        <h2 class="report-subtitle">Monthly Department Staff Selections — <?= e($periodLabel) ?></h2>
        <p class="meta">Generated on <?= e(date('d M Y, h:i A')) ?></p>
    </div>
</div>

<table>
    <thead>
    <tr>
        <th>Period</th>
        <th style="width: 70px; text-align: center;">Photo</th>
        <th>Department</th>
        <th>Staff Name</th>
        <th>Rank</th>
        <th class="text-end">Evaluation score</th>
    </tr>
    </thead>
    <tbody>
    <?php if (!$submissions): ?>
        <tr><td colspan="6" class="text-center text-muted">No staff selections were recorded for this period.</td></tr>
    <?php endif; ?>
    <?php foreach ($submissions as $index => $sub): ?>
        <tr>
            <td><?= e(format_month_year((int) $sub['month'], (int) $sub['year'])) ?></td>
            <td style="text-align: center;">
                <img src="<?= e(upload_url('staff', $sub['staff_photo'])) ?>" alt="<?= e($sub['staff_name']) ?>" style="width: 100px; height: 100px; object-fit: cover; border-radius: 6px; border: 1px solid #aab69a; display: inline-block;">
            </td>
            <td class="fw-bold"><?= e($sub['department_name']) ?></td>
            <td><strong><?= e($sub['staff_name']) ?></strong></td>
            <td><?= e($sub['staff_rank'] ?? '') ?></td>
            <td class="text-end"><?= e((string) $sub['score']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

</body>
</html>
