<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/image.php';

require_login('department');

if (!is_post()) {
    redirect('department/dashboard.php');
}

$departmentId = current_department_id();
if ($departmentId === null) {
    set_flash('error', 'Department account could not be loaded.');
    redirect('department/dashboard.php');
}

$staffName = normalize_text($_POST['staff_name'] ?? '');
$staffRank = normalize_text($_POST['staff_rank'] ?? '');
$staffPhotoFile = $_FILES['staff_photo'] ?? [];

if ($staffName === '' || $staffRank === '') {
    set_flash('error', 'Staff name and rank are required.');
    redirect('department/dashboard.php');
}

try {
    $photoName = upload_and_resize_image($staffPhotoFile, 'staff', 700, 700);
    try {
        $stmt = db()->prepare(
            'INSERT INTO staff (department_id, name, rank, photo)
             VALUES (:department_id, :name, :rank, :photo)'
        );
        $stmt->execute([
            ':department_id' => $departmentId,
            ':name' => $staffName,
            ':rank' => $staffRank,
            ':photo' => $photoName,
        ]);
    } catch (Throwable $exception) {
        delete_image_file('staff', $photoName);
        throw $exception;
    }

    set_flash('success', 'Staff member added.');
} catch (Throwable $exception) {
    set_flash('error', 'Could not add staff member: ' . $exception->getMessage());
}

redirect('department/dashboard.php');
