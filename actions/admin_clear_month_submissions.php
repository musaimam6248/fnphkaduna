<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_login('admin');

if (!is_post()) {
    redirect('admin/dashboard.php');
}

$month = (int) ($_POST['month'] ?? 0);
$year = (int) ($_POST['year'] ?? 0);

if ($month < 1 || $month > 12 || $year < 2000) {
    set_flash('error', 'Choose a valid month and year first.');
    redirect('admin/dashboard.php');
}

$pdo = db();

try {
    $stmt = $pdo->prepare('DELETE FROM submission WHERE month = :month AND year = :year');
    $stmt->execute([
        ':month' => $month,
        ':year' => $year,
    ]);

    $count = $stmt->rowCount();
    set_flash('success', 'Cleared ' . $count . ' submission(s) for ' . format_month_year($month, $year) . '.');
} catch (Throwable $exception) {
    set_flash('error', 'Unable to clear submissions: ' . $exception->getMessage());
}

redirect('admin/dashboard.php');
