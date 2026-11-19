<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login('admin');

$month = (int) ($_GET['month'] ?? date('n'));
$year = (int) ($_GET['year'] ?? date('Y'));
$departmentId = max(0, (int) ($_GET['department_id'] ?? 0));
if ($month < 1 || $month > 12 || $year < 2000 || $year > 2200) {
    http_response_code(400);
    exit('Choose a valid reporting period.');
}

$stmt = db()->prepare(
    "SELECT d.name AS department_name, d.hod, r.report_month, r.report_year,
            r.period_from, r.period_to, r.status AS report_status, r.review_status,
            k.kra, k.smart_objective, k.kpi, k.target, k.target_value, k.unit,
            k.achievement, k.actual_value, k.achievement_percent, k.evidence,
            k.variance_value, k.status AS target_status
     FROM department_monthly_report r
     INNER JOIN department d ON d.id = r.department_id
     INNER JOIN department_monthly_kpi_update k ON k.report_id = r.id
     WHERE r.report_month = :month AND r.report_year = :year
       AND r.status IN ('submitted', 'reviewed') AND r.review_status <> 'returned'
       AND (:department_filter = 0 OR r.department_id = :department_match)
     ORDER BY d.name, k.sort_order, k.id"
);
$stmt->execute([
    ':month' => $month,
    ':year' => $year,
    ':department_filter' => $departmentId,
    ':department_match' => $departmentId,
]);

$filename = sprintf('monthly-kpi-%04d-%02d%s.csv', $year, $month, $departmentId > 0 ? '-department-' . $departmentId : '');
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
$output = fopen('php://output', 'wb');
if ($output === false) {
    http_response_code(500);
    exit('Could not prepare the Excel-compatible KPI file.');
}
fwrite($output, "\xEF\xBB\xBF");
fputcsv($output, [
    'Department / Unit', 'Head of Department', 'Month', 'Year', 'Period From', 'Period To',
    'Report Status', 'Review Status', 'KRA', 'SMART Objectives', 'KPI', 'Target',
    'Target Value', 'Unit', 'Achievement', 'Actual Value', 'Achievement %', 'Evidence',
    'Variance', 'KPI Status',
], ',', '"', '');

while ($row = $stmt->fetch()) {
    fputcsv($output, array_map('excel_safe_csv_value', [
        $row['department_name'], $row['hod'], month_label((int) $row['report_month']),
        $row['report_year'], $row['period_from'], $row['period_to'], $row['report_status'],
        $row['review_status'], $row['kra'], $row['smart_objective'], $row['kpi'], $row['target'],
        $row['target_value'], $row['unit'], $row['achievement'], $row['actual_value'],
        $row['achievement_percent'], $row['evidence'], $row['variance_value'],
        ucwords(str_replace('_', ' ', (string) $row['target_status'])),
    ]), ',', '"', '');
}
fclose($output);
exit;

function excel_safe_csv_value(mixed $value): string|int|float
{
    if (!is_string($value)) {
        return $value ?? '';
    }
    return preg_match('/^\s*[=+@\-]/u', $value) === 1 ? "'" . $value : $value;
}
