<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/image.php';

require_login('admin');

if (!is_post()) {
    redirect('admin/dashboard.php');
}

$pdo = db();
$action = normalize_text($_POST['action'] ?? '');

try {
    if ($action === 'save_colors') {
        $appColor = normalize_hex_color((string) ($_POST['app_background_color'] ?? ''));
        $homepageColor = normalize_hex_color((string) ($_POST['homepage_background_color'] ?? ''));
        if ($appColor === null || $homepageColor === null) {
            throw new InvalidArgumentException('Please select valid background colors.');
        }

        $pdo->prepare('UPDATE homepage_settings SET app_background_color = :app_color, homepage_background_color = :homepage_color, updated_at = CURRENT_TIMESTAMP WHERE id = 1')
            ->execute([':app_color' => $appColor, ':homepage_color' => $homepageColor]);
        set_flash('success', 'App and homepage background colors updated.');
    } elseif ($action === 'upload_images') {
        $imageFiles = $_FILES['background_images'] ?? [];
        if (!isset($imageFiles['name']) || !is_array($imageFiles['name'])) {
            throw new InvalidArgumentException('Please choose one or more background pictures.');
        }

        $existingCount = (int) $pdo->query('SELECT COUNT(*) FROM homepage_background_image')->fetchColumn();
        $selectedCount = count(array_filter($imageFiles['name'], static fn($name) => (string) $name !== ''));
        if ($selectedCount === 0) {
            throw new InvalidArgumentException('Please choose one or more background pictures.');
        }
        if ($existingCount + $selectedCount > 10) {
            throw new InvalidArgumentException('You can keep a maximum of 10 homepage background pictures. Remove one before adding more.');
        }

        $nextSortOrder = $existingCount + 1;
        $insert = $pdo->prepare('INSERT INTO homepage_background_image (image, sort_order) VALUES (:image, :sort_order)');
        foreach ($imageFiles['name'] as $index => $unusedName) {
            $singleFile = [
                'name' => $imageFiles['name'][$index] ?? '',
                'type' => $imageFiles['type'][$index] ?? '',
                'tmp_name' => $imageFiles['tmp_name'][$index] ?? '',
                'error' => $imageFiles['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $imageFiles['size'][$index] ?? 0,
            ];
            $imageName = upload_and_resize_image($singleFile, 'gallery', 1920, 1080);
            if ($imageName === null) {
                continue;
            }
            $insert->execute([':image' => $imageName, ':sort_order' => $nextSortOrder++]);
        }
        set_flash('success', 'Homepage background pictures updated.');
    } elseif ($action === 'delete_image') {
        $imageId = (int) ($_POST['image_id'] ?? 0);
        $imageStmt = $pdo->prepare('SELECT image FROM homepage_background_image WHERE id = :id LIMIT 1');
        $imageStmt->execute([':id' => $imageId]);
        $image = $imageStmt->fetch();
        if (!$image) {
            throw new RuntimeException('Homepage background picture not found.');
        }
        $pdo->prepare('DELETE FROM homepage_background_image WHERE id = :id')->execute([':id' => $imageId]);
        delete_image_file('gallery', $image['image']);
        set_flash('success', 'Homepage background picture removed.');
    } else {
        throw new InvalidArgumentException('Unsupported homepage background action.');
    }
} catch (Throwable $exception) {
    set_flash('error', 'Could not update homepage background: ' . $exception->getMessage());
}

redirect('admin/dashboard.php#admin-homepage-background');
