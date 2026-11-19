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
$createdImages = [];

try {
if ($action === 'create') {
        $title = normalize_text($_POST['title'] ?? '');
        $imageFiles = $_FILES['image'] ?? [];

        if ($title === '') {
            throw new InvalidArgumentException('Picture title is required.');
        }

        if (!isset($imageFiles['name']) || !is_array($imageFiles['name'])) {
            $imageName = upload_and_resize_image($imageFiles, 'gallery', 1200, 900);
            if ($imageName === null) {
                throw new InvalidArgumentException('Please choose at least one image to upload.');
            }
            $createdImages[] = $imageName;

            $stmt = $pdo->prepare(
                'INSERT INTO gallery (image, title) VALUES (:image, :title)'
            );
            $stmt->execute([
                ':image' => $imageName,
                ':title' => $title,
            ]);

            $createdImages = [];
            set_flash('success', 'Picture added to gallery.');
            redirect('admin/dashboard.php');
        }

        $uploadedCount = 0;
        $pdo->beginTransaction();
        foreach ($imageFiles['name'] as $index => $unusedName) {
            $singleFile = [
                'name' => $imageFiles['name'][$index] ?? '',
                'type' => $imageFiles['type'][$index] ?? '',
                'tmp_name' => $imageFiles['tmp_name'][$index] ?? '',
                'error' => $imageFiles['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $imageFiles['size'][$index] ?? 0,
            ];

            $imageName = upload_and_resize_image($singleFile, 'gallery', 1200, 900);
            if ($imageName === null) {
                continue;
            }
            $createdImages[] = $imageName;

            $stmt = $pdo->prepare(
                'INSERT INTO gallery (image, title) VALUES (:image, :title)'
            );
            $stmt->execute([
                ':image' => $imageName,
                ':title' => $title,
            ]);
            $uploadedCount++;
        }

        if ($uploadedCount === 0) {
            throw new InvalidArgumentException('Please choose at least one image to upload.');
        }
        $pdo->commit();

        set_flash('success', $uploadedCount === 1
            ? 'Picture added to gallery.'
            : $uploadedCount . ' pictures added to gallery.');
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            throw new InvalidArgumentException('Select a valid gallery picture.');
        }

        $fetchStmt = $pdo->prepare('SELECT image FROM gallery WHERE id = :id LIMIT 1');
        $fetchStmt->execute([':id' => $id]);
        $gallery = $fetchStmt->fetch();
        if (!$gallery) {
            throw new RuntimeException('Gallery image not found.');
        }

        $deleteStmt = $pdo->prepare('DELETE FROM gallery WHERE id = :id');
        $deleteStmt->execute([':id' => $id]);
        delete_image_file('gallery', $gallery['image'] ?? null);

        set_flash('success', 'Picture deleted from gallery.');
    } else {
        throw new InvalidArgumentException('Unsupported gallery action.');
    }
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    foreach ($createdImages as $imageName) {
        delete_image_file('gallery', $imageName);
    }
    set_flash('error', 'Could not update gallery: ' . $exception->getMessage());
}

redirect('admin/dashboard.php');
