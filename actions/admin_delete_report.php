<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_login('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admin/dashboard.php');
}

$id = (int)($_POST['id'] ?? 0);

if ($id > 0) {
    $pdo = db();
    $fetchStmt = $pdo->prepare('SELECT file_path FROM saved_report WHERE id = :id LIMIT 1');
    $fetchStmt->execute([':id' => $id]);
    $report = $fetchStmt->fetch();
    if ($report) {
        $relativePath = (string) ($report['file_path'] ?? '');
        if (!str_starts_with($relativePath, 'uploads/reports/') || basename($relativePath) !== substr($relativePath, strlen('uploads/reports/'))) {
            set_flash('error', 'The saved report path is invalid, so it was not deleted.');
            redirect('admin/dashboard.php#admin-saved-reports');
        }

        $reportsDirectory = realpath(UPLOAD_PATH . '/reports');
        $fullPath = realpath(BASE_PATH . '/' . $relativePath);
        if ($fullPath !== false && $reportsDirectory !== false && dirname($fullPath) === $reportsDirectory && is_file($fullPath)) {
            if (!@unlink($fullPath)) {
                set_flash('error', 'Could not remove the report file. Check the reports folder permissions.');
                redirect('admin/dashboard.php#admin-saved-reports');
            }
        }

        $deleteStmt = $pdo->prepare('DELETE FROM saved_report WHERE id = :id');
        $deleteStmt->execute([':id' => $id]);
    }
}

redirect('admin/dashboard.php#admin-saved-reports');
