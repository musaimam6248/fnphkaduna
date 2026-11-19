<?php
declare(strict_types=1);

function monthly_report_statistical_rows(PDO $pdo, int $reportId): array
{
    $stmt = $pdo->prepare(
        "SELECT u.*, d.name AS department_name, d.hod AS responsible_unit,
                r.report_month, r.report_year, r.period_from, r.period_to,
                previous.achievement_percent AS previous_achievement_percent,
                previous_report.report_month AS previous_report_month,
                previous_report.report_year AS previous_report_year,
                (
                    SELECT review.comment
                    FROM department_monthly_report_review review
                    WHERE review.kpi_update_id = u.id AND review.action = 'comment'
                    ORDER BY review.id DESC LIMIT 1
                ) AS admin_measure_comment
         FROM department_monthly_report r
         INNER JOIN department d ON d.id = r.department_id
         INNER JOIN department_monthly_kpi_update u ON u.report_id = r.id
         LEFT JOIN department_monthly_kpi_update previous ON previous.id = (
             SELECT prior_update.id
             FROM department_monthly_kpi_update prior_update
             INNER JOIN department_monthly_report prior_report ON prior_report.id = prior_update.report_id
             WHERE prior_report.department_id = r.department_id
               AND (prior_report.report_year < r.report_year
                    OR (prior_report.report_year = r.report_year AND prior_report.report_month < r.report_month))
               AND prior_report.status IN ('submitted', 'reviewed')
               AND prior_report.review_status <> 'returned'
               AND (
                    (u.annual_plan_item_id IS NOT NULL AND prior_update.annual_plan_item_id = u.annual_plan_item_id)
                    OR (lower(trim(prior_update.kra)) = lower(trim(u.kra))
                        AND lower(trim(prior_update.kpi)) = lower(trim(u.kpi)))
               )
             ORDER BY prior_report.report_year DESC, prior_report.report_month DESC, prior_update.id DESC
             LIMIT 1
         )
         LEFT JOIN department_monthly_report previous_report ON previous_report.id = previous.report_id
         WHERE r.id = :report_id
         ORDER BY u.sort_order, u.id"
    );
    $stmt->execute([':report_id' => $reportId]);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $percent = is_numeric($row['achievement_percent'] ?? null) ? (float) $row['achievement_percent'] : null;
        $previousPercent = is_numeric($row['previous_achievement_percent'] ?? null) ? (float) $row['previous_achievement_percent'] : null;
        $trend = null;
        if ($percent !== null && $previousPercent !== null) {
            $difference = $percent - $previousPercent;
            $trend = abs($difference) < 0.05 ? 'unchanged' : ($difference > 0 ? 'improved' : 'declined');
        }

        $status = (string) ($row['status'] ?? '');
        if (!in_array($status, ['on_track', 'at_risk', 'off_track'], true)) {
            $status = $percent === null ? 'not_available' : ($percent >= 100 ? 'on_track' : ($percent >= 80 ? 'at_risk' : 'off_track'));
        }

        $row['achievement_percent_value'] = $percent;
        $row['previous_achievement_percent_value'] = $previousPercent;
        $row['change_percentage_points'] = $percent !== null && $previousPercent !== null ? $percent - $previousPercent : null;
        $row['trend'] = $trend;
        $row['statistical_status'] = $status;
        $row['improvement_required'] = $status !== 'on_track' || $trend === 'declined';
        $row['finding_label'] = match (true) {
            $status === 'off_track' => 'Below target',
            $status === 'at_risk' => 'Near target',
            $status === 'not_available' => 'Achievement unavailable',
            $trend === 'declined' => 'Declined from previous report',
            $trend === 'improved' => 'Improved from previous report',
            default => 'On target',
        };

        $details = [];
        if (is_numeric($row['actual_value'] ?? null) && is_numeric($row['target_value'] ?? null)) {
            $unit = trim((string) ($row['unit'] ?? ''));
            $suffix = $unit !== '' ? ' ' . $unit : '';
            $details[] = 'Actual: ' . number_format((float) $row['actual_value'], 2) . $suffix;
            $details[] = 'Target: ' . number_format((float) $row['target_value'], 2) . $suffix;
            if (is_numeric($row['variance_value'] ?? null)) {
                $details[] = 'Variance: ' . number_format((float) $row['variance_value'], 2) . $suffix;
            }
        } else {
            $details[] = 'Reported achievement: ' . trim((string) ($row['achievement'] ?? ''));
            $details[] = 'Target: ' . trim((string) ($row['target'] ?? ''));
        }
        if ($percent !== null) {
            $details[] = 'Achievement: ' . number_format($percent, 1) . '%';
        }
        if ($previousPercent !== null) {
            $details[] = 'Previous report: ' . number_format($previousPercent, 1) . '%';
            $details[] = 'Change: ' . number_format((float) $row['change_percentage_points'], 1) . ' percentage points';
        }
        if (trim((string) ($row['commentary'] ?? '')) !== '') {
            $details[] = 'Department commentary: ' . trim((string) $row['commentary']);
        }
        $row['statistical_problem'] = implode('. ', $details) . (count($details) ? '.' : '');
        $row['improvement_area'] = $row['improvement_required']
            ? trim((string) ($row['next_step'] ?? ''))
            : 'Continue monitoring this measure.';
    }
    unset($row);

    return $rows;
}

function monthly_report_statistical_summary(array $rows): array
{
    $summary = [
        'total' => count($rows),
        'on_track' => 0,
        'at_risk' => 0,
        'off_track' => 0,
        'not_available' => 0,
        'improvement_required' => 0,
        'declined' => 0,
        'improved' => 0,
    ];
    foreach ($rows as $row) {
        $status = $row['statistical_status'] ?? 'not_available';
        if (isset($summary[$status])) {
            $summary[$status]++;
        }
        if (!empty($row['improvement_required'])) {
            $summary['improvement_required']++;
        }
        if (($row['trend'] ?? null) === 'declined') {
            $summary['declined']++;
        } elseif (($row['trend'] ?? null) === 'improved') {
            $summary['improved']++;
        }
    }
    return $summary;
}
