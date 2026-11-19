<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/image.php';

require_login('admin');

$format = strtolower(normalize_text($_GET['format'] ?? 'csv'));
$month = (int)($_GET['month'] ?? 0);
$year = (int)($_GET['year'] ?? 0);

if ($month < 1 || $month > 12 || $year < 2000) {
    redirect('admin/dashboard.php');
}

$pdo = db();

$stmt = $pdo->prepare("
    SELECT s.id AS submission_id, st.name AS staff_name, st.rank AS staff_rank,
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
     GROUP BY s.id, st.name, st.rank, d.name, s.month, s.year, s.final_remarks
     ORDER BY grand_total DESC
");
$stmt->execute(['month' => $month, 'year' => $year]);
$rows = $stmt->fetchAll();

$monthName = date('F', mktime(0, 0, 0, $month, 10));

if ($format === 'csv') {
    $filename = "staff-evaluation-{$monthName}-{$year}-" . time() . '-' . bin2hex(random_bytes(4)) . ".csv";
    ensure_upload_directories();
    $filepath = __DIR__ . '/../uploads/reports/' . $filename;
    $dbpath = 'uploads/reports/' . $filename;

    $output = @fopen($filepath, 'wb');
    if ($output === false) {
        set_flash('error', 'Could not create the CSV report file. Check the reports folder permissions.');
        redirect('admin/dashboard.php#admin-evaluation');
    }
    if (fputcsv($output, [
        '#', 'Department', 'Name', 'Rank', 'Leadership', 'Attitude', 'Innovation', 'Interpersonal', 'Learning', 'Productivity', 'Punctuality', 'Grand Total', 'Average', 'HOD Remarks'
    ], ',', '"', '\\') === false) {
        fclose($output);
        @unlink($filepath);
        set_flash('error', 'Could not write the CSV report file.');
        redirect('admin/dashboard.php#admin-evaluation');
    }

    foreach ($rows as $index => $row) {
        $criteriaCount = (int)$row['criteria_count'];
        $avgScore = $criteriaCount > 0 ? round($row['grand_total'] / $criteriaCount, 1) : 0;

        if (fputcsv($output, [
            $index + 1,
            csv_report_value($row['department_name'] ?? ''),
            csv_report_value($row['staff_name'] ?? ''),
            csv_report_value($row['staff_rank'] ?? ''),
            $row['leadership_total'] ?? 0,
            $row['positive_attitude_total'] ?? 0,
            $row['innovation_total'] ?? 0,
            $row['interpersonal_skills_total'] ?? 0,
            $row['willingness_to_learn_total'] ?? 0,
            $row['productivity_total'] ?? 0,
            $row['punctuality_total'] ?? 0,
            $row['grand_total'] ?? 0,
            $avgScore,
            csv_report_value($row['final_remarks'] ?? '')
        ], ',', '"', '\\') === false) {
            fclose($output);
            @unlink($filepath);
            set_flash('error', 'Could not write the CSV report file.');
            redirect('admin/dashboard.php#admin-evaluation');
        }
    }
    fclose($output);

    try {
        $stmt = $pdo->prepare("INSERT INTO saved_report (month, year, format, file_path) VALUES (?, ?, ?, ?)");
        $stmt->execute([$month, $year, 'csv', $dbpath]);
    } catch (Throwable $exception) {
        @unlink($filepath);
        set_flash('error', 'Could not save the CSV report: ' . $exception->getMessage());
        redirect('admin/dashboard.php#admin-evaluation');
    }

    redirect('admin/dashboard.php#admin-saved-reports');
}

if ($format !== 'pdf') {
    redirect('admin/dashboard.php');
}

$lines = [];
$lines[] = "STAFF EVALUATION SCORES - {$monthName} {$year}";
$lines[] = 'Generated: ' . date('Y-m-d H:i:s');
$lines[] = str_repeat('-', 72);

if (!$rows) {
    $lines[] = 'No submission records found for this month.';
} else {
    foreach ($rows as $index => $row) {
        $criteriaCount = (int) ($row['criteria_count'] ?? 0);
        $average = $criteriaCount > 0 ? round((float) $row['grand_total'] / $criteriaCount, 1) : 0;
        $lines[] = ($index + 1) . '. ' . ($row['staff_name'] ?? '') . ' (' . ($row['department_name'] ?? '') . ')';
        $lines[] = '   Leadership: ' . ($row['leadership_total'] ?? 0) . ' | Attitude: ' . ($row['positive_attitude_total'] ?? 0) . ' | Innovation: ' . ($row['innovation_total'] ?? 0);
        $lines[] = '   Interpersonal: ' . ($row['interpersonal_skills_total'] ?? 0) . ' | Learning: ' . ($row['willingness_to_learn_total'] ?? 0) . ' | Productivity: ' . ($row['productivity_total'] ?? 0) . ' | Punctuality: ' . ($row['punctuality_total'] ?? 0);
        $lines[] = '   Grand total: ' . ($row['grand_total'] ?? 0) . ' | Average per criterion: ' . $average;
        if (trim((string) ($row['final_remarks'] ?? '')) !== '') {
            $lines[] = '   HOD remarks: ' . (string) $row['final_remarks'];
        }
        $lines[] = '';
    }
}

$pdfBinary = build_simple_pdf($lines);
$filename = "staff-evaluation-{$monthName}-{$year}-" . time() . '-' . bin2hex(random_bytes(4)) . ".pdf";
ensure_upload_directories();
$filepath = __DIR__ . '/../uploads/reports/' . $filename;
$dbpath = 'uploads/reports/' . $filename;

if (@file_put_contents($filepath, $pdfBinary, LOCK_EX) === false) {
    set_flash('error', 'Could not create the PDF report file. Check the reports folder permissions.');
    redirect('admin/dashboard.php#admin-evaluation');
}

try {
    $stmt = $pdo->prepare("INSERT INTO saved_report (month, year, format, file_path) VALUES (?, ?, ?, ?)");
    $stmt->execute([$month, $year, 'pdf', $dbpath]);
} catch (Throwable $exception) {
    @unlink($filepath);
    set_flash('error', 'Could not save the PDF report: ' . $exception->getMessage());
    redirect('admin/dashboard.php#admin-evaluation');
}

redirect('admin/dashboard.php#admin-saved-reports');

function pdf_escape(string $text): string
{
    $text = str_replace('\\', '\\\\', $text);
    $text = str_replace('(', '\\(', $text);
    $text = str_replace(')', '\\)', $text);
    return preg_replace('/[^\x20-\x7E]/', ' ', $text) ?? '';
}

function csv_report_value(mixed $value): string
{
    $text = (string) $value;
    return preg_match('/^[\s\x00-\x1F]*[=+\-@]/', $text) ? "'" . $text : $text;
}

function pdf_wrap_text(string $text, int $lineLength): array
{
    return explode("\n", wordwrap($text, $lineLength, "\n", true));
}

function build_simple_pdf(array $lines): string
{
    $maxLinesPerPage = 45;
    $expandedLines = [];
    foreach ($lines as $line) {
        foreach (pdf_wrap_text((string) $line, 92) as $wrappedLine) {
            $expandedLines[] = $wrappedLine;
        }
    }
    $chunks = array_chunk($expandedLines, $maxLinesPerPage);
    if (!$chunks) {
        $chunks = [[]];
    }

    $objects = [];
    $pageRefs = [];
    $fontObjectNumber = 3 + (count($chunks) * 2);

    $catalogObjectNumber = 1;
    $pagesObjectNumber = 2;

    foreach ($chunks as $index => $chunkLines) {
        $pageObjectNumber = 3 + ($index * 2);
        $contentObjectNumber = $pageObjectNumber + 1;
        $pageRefs[] = $pageObjectNumber . ' 0 R';

        $content = "BT\n/F1 10 Tf\n50 790 Td\n";
        foreach ($chunkLines as $lineIndex => $lineText) {
            if ($lineIndex > 0) {
                $content .= "0 -16 Td\n";
            }
            $content .= '(' . pdf_escape((string) $lineText) . ") Tj\n";
        }
        $content .= "ET";

        $objects[$pageObjectNumber] = '<< /Type /Page /Parent ' . $pagesObjectNumber . ' 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 ' . $fontObjectNumber . ' 0 R >> >> /Contents ' . $contentObjectNumber . ' 0 R >>';
        $objects[$contentObjectNumber] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
    }

    $objects[$catalogObjectNumber] = '<< /Type /Catalog /Pages ' . $pagesObjectNumber . ' 0 R >>';
    $objects[$pagesObjectNumber] = '<< /Type /Pages /Kids [' . implode(' ', $pageRefs) . '] /Count ' . count($pageRefs) . ' >>';
    $objects[$fontObjectNumber] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

    ksort($objects);

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $number => $body) {
        $offsets[$number] = strlen($pdf);
        $pdf .= $number . " 0 obj\n" . $body . "\nendobj\n";
    }

    $xrefStart = strlen($pdf);
    $maxObject = max(array_keys($objects));
    $pdf .= "xref\n0 " . ($maxObject + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= $maxObject; $i++) {
        $offset = $offsets[$i] ?? 0;
        $pdf .= sprintf('%010d 00000 n ', $offset) . "\n";
    }

    $pdf .= "trailer\n<< /Size " . ($maxObject + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n" . $xrefStart . "\n%%EOF";

    return $pdf;
}
