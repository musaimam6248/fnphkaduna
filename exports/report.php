<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_login('admin');

$format = strtolower(normalize_text($_GET['format'] ?? 'csv'));
$pdo = db();

$rows = $pdo->query(
    "SELECT d.name AS department_name, st.name AS staff_name
     FROM submission s
     INNER JOIN department d ON d.id = s.department_id
     INNER JOIN staff st ON st.id = s.staff_id
     GROUP BY d.name, st.name
     ORDER BY d.name ASC, st.name ASC"
)->fetchAll();

if ($format === 'csv') {
    $filename = 'fnph-kaduna-report-' . date('Ymd-His') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fputcsv($output, [
        'Department',
        'Staff Name',
    ], ',', '"', '\\');

    foreach ($rows as $row) {
        fputcsv($output, [
            $row['department_name'] ?? '',
            $row['staff_name'] ?? '',
        ], ',', '"', '\\');
    }
    fclose($output);
    exit;
}

if ($format !== 'pdf') {
    redirect('admin/dashboard.php');
}

$lines = [];
$lines[] = 'FEDERAL NEURO-PSYCHIATRIC HOSPITAL, KADUNA - STAFF REPORT';
$lines[] = 'Generated: ' . date('Y-m-d H:i:s');
$lines[] = str_repeat('-', 72);

if (!$rows) {
    $lines[] = 'No submission records found.';
} else {
    foreach ($rows as $index => $row) {
        $lines[] = ($index + 1) . '. ' .
            ($row['staff_name'] ?? '') .
            ' | Department: ' . ($row['department_name'] ?? '');
    }
}

$pdfBinary = build_simple_pdf($lines);
$filename = 'fnph-kaduna-report-' . date('Ymd-His') . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo $pdfBinary;
exit;

function pdf_wrap_text(string $text, int $lineLength): array
{
    $wrapped = wordwrap($text, $lineLength, "\n", true);
    $lines = explode("\n", $wrapped);
    return array_values(array_filter($lines, static fn($line) => trim($line) !== ''));
}

function pdf_escape(string $text): string
{
    $text = str_replace('\\', '\\\\', $text);
    $text = str_replace('(', '\\(', $text);
    $text = str_replace(')', '\\)', $text);
    return preg_replace('/[^\x20-\x7E]/', ' ', $text) ?? '';
}

function build_simple_pdf(array $lines): string
{
    $maxLinesPerPage = 45;
    $chunks = array_chunk($lines, $maxLinesPerPage);
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
            $content .= '(' . pdf_escape($lineText) . ") Tj\n";
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
