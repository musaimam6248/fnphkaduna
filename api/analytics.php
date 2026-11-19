<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = db();

$submissionsByDepartment = $pdo->query(
    "SELECT d.name AS department_name, COUNT(s.id) AS total_submissions
     FROM department d
     LEFT JOIN submission s ON s.department_id = d.id
     GROUP BY d.id
     ORDER BY total_submissions DESC, d.name ASC"
)->fetchAll();

$winsByDepartment = $pdo->query(
    "SELECT d.name AS department_name, COUNT(w.id) AS total_wins
     FROM department d
     LEFT JOIN staff st ON st.department_id = d.id
     LEFT JOIN winner w ON w.staff_id = st.id
     GROUP BY d.id
     ORDER BY total_wins DESC, d.name ASC"
)->fetchAll();

$submissionTrend = $pdo->query(
    "SELECT year, month, COUNT(*) AS total_submissions, COALESCE(SUM(score), 0) AS total_score
     FROM submission
     GROUP BY year, month
     ORDER BY year DESC, month DESC
     LIMIT 12"
)->fetchAll();

$submissionTrend = array_reverse($submissionTrend);
$trendRows = array_map(
    static fn($row) => [
        'label' => format_month_year((int) $row['month'], (int) $row['year']),
        'value' => (int) $row['total_submissions'],
        'score' => (int) $row['total_score'],
    ],
    $submissionTrend
);

$scoreTrendRows = $pdo->query(
    "SELECT year, month, COALESCE(SUM(score), 0) AS total_score
     FROM submission
     GROUP BY year, month
     ORDER BY year DESC, month DESC
     LIMIT 12"
)->fetchAll();
$scoreTrendRows = array_reverse($scoreTrendRows);
$scoreTrendLabels = array_map(static fn($row) => format_month_year((int) $row['month'], (int) $row['year']), $scoreTrendRows);
$scoreTrendValues = array_map(static fn($row) => (int) $row['total_score'], $scoreTrendRows);

$evalCategoryTotals = $pdo->query(
    "SELECT pe.category, SUM(pe.score) AS total_score, COUNT(pe.id) AS count
     FROM performance_evaluation pe
     GROUP BY pe.category
     ORDER BY pe.category"
)->fetchAll();

echo json_encode([
    'submissions_by_department' => $submissionsByDepartment,
    'wins_by_department' => $winsByDepartment,
    'submission_trend' => $trendRows,
    'score_trend_labels' => $scoreTrendLabels,
    'score_trend_values' => $scoreTrendValues,
    'eval_category_totals' => $evalCategoryTotals,
]);
