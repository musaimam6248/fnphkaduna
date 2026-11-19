<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/image.php';

require_login('admin');

if (!is_post()) {
    redirect('admin/dashboard.php');
}

$action = normalize_text($_POST['action'] ?? '');
$pdo = db();
$createdPhoto = null;

try {
    if ($action === 'create') {
        $name = normalize_text($_POST['name'] ?? '');
        $role = normalize_text($_POST['role'] ?? '');
        $photoFile = $_FILES['photo'] ?? [];

        if ($name === '' || $role === '') {
            throw new InvalidArgumentException('Name and role are required.');
        }

        $photoName = upload_and_resize_image($photoFile, 'committee', 700, 700);
        $createdPhoto = $photoName;

        $stmt = $pdo->prepare(
            'INSERT INTO committee (name, role, photo) VALUES (:name, :role, :photo)'
        );
        $stmt->execute([
            ':name' => $name,
            ':role' => $role,
            ':photo' => $photoName,
        ]);
        $createdPhoto = null;

        set_flash('success', 'Committee member added.');
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            throw new InvalidArgumentException('Select a valid committee member.');
        }

        $fetchStmt = $pdo->prepare('SELECT photo FROM committee WHERE id = :id LIMIT 1');
        $fetchStmt->execute([':id' => $id]);
        $member = $fetchStmt->fetch();
        if (!$member) {
            throw new RuntimeException('Committee member not found.');
        }

        $deleteStmt = $pdo->prepare('DELETE FROM committee WHERE id = :id');
        $deleteStmt->execute([':id' => $id]);
        delete_image_file('committee', $member['photo'] ?? null);

        set_flash('success', 'Committee member removed.');
    } else {
        throw new InvalidArgumentException('Unsupported committee action.');
    }
} catch (Throwable $exception) {
    if ($createdPhoto !== null) {
        delete_image_file('committee', $createdPhoto);
    }
    set_flash('error', 'Could not update committee: ' . $exception->getMessage());
}

redirect('admin/dashboard.php');
