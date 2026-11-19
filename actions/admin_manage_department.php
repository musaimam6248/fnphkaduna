<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_login('admin');

if (!is_post()) {
    redirect('admin/dashboard.php');
}

$action = normalize_text($_POST['action'] ?? '');
$departmentId = (int) ($_POST['department_id'] ?? 0);

if ($action === 'create') {
    $departmentName = normalize_text($_POST['department_name'] ?? '');
    $hodName = normalize_text($_POST['hod_name'] ?? '');
    $username = normalize_text($_POST['username'] ?? '');
    $email = normalize_text($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
    if ($password !== $confirmPassword) {
        set_flash('error', 'Password confirmation does not match.');
        redirect('admin/departments.php#register-department');
    }
    if (strlen($password) < 8) {
        set_flash('error', 'Password must be at least 8 characters.');
        redirect('admin/departments.php#register-department');
    }
    try {
        register_department_user($departmentName, $hodName, $username, $password, $email);
        set_flash('success', 'Department / Unit account registered successfully.');
    } catch (Throwable $exception) {
        set_flash('error', 'Unable to register department / unit: ' . $exception->getMessage());
    }
    redirect('admin/departments.php#register-department');
}

if ($action !== 'delete') {
    set_flash('error', 'Unsupported department action.');
    redirect('admin/dashboard.php');
}

if ($departmentId <= 0) {
    set_flash('error', 'Select a valid department.');
    redirect('admin/dashboard.php');
}

$pdo = db();

try {
    $departmentStmt = $pdo->prepare('SELECT id, name FROM department WHERE id = :id LIMIT 1');
    $departmentStmt->execute([':id' => $departmentId]);
    $department = $departmentStmt->fetch();

    if (!$department) {
        throw new RuntimeException('Department not found.');
    }

    $deleteStmt = $pdo->prepare('DELETE FROM department WHERE id = :id');
    $deleteStmt->execute([':id' => $departmentId]);

    set_flash('success', 'Department deleted: ' . ($department['name'] ?? ''));
} catch (Throwable $exception) {
    set_flash('error', 'Could not update department: ' . $exception->getMessage());
}

redirect('admin/dashboard.php');
