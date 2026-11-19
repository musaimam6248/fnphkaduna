<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/image.php';

require_login('admin');

if (!is_post()) {
    redirect('admin/dashboard.php');
}

$vision = normalize_text($_POST['vision'] ?? '');
$mission = normalize_text($_POST['mission'] ?? '');
$objectives = normalize_text($_POST['objectives'] ?? '');
$imageFile = $_FILES['image'] ?? [];

if ($vision === '' || $mission === '' || $objectives === '') {
    set_flash('error', 'Vision, mission, and objectives are required.');
    redirect('admin/dashboard.php');
}

$pdo = db();

try {
    $current = $pdo->query('SELECT id, image FROM department_info ORDER BY updated_at DESC LIMIT 1')->fetch();
    $imageName = null;
    if (($imageFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $imageName = upload_and_resize_image($imageFile, 'gallery', 900, 900);
    }

    if ($current) {
        $sql = 'UPDATE department_info
                SET vision = :vision, mission = :mission, objectives = :objectives, updated_at = CURRENT_TIMESTAMP';
        $params = [
            ':vision' => $vision,
            ':mission' => $mission,
            ':objectives' => $objectives,
            ':id' => (int) $current['id'],
        ];
        if ($imageName !== null) {
            $sql .= ', image = :image';
            $params[':image'] = $imageName;
        }
        $sql .= ' WHERE id = :id';
        $updateStmt = $pdo->prepare($sql);
        $updateStmt->execute($params);
        if ($imageName !== null && !empty($current['image'])) {
            delete_image_file('gallery', $current['image']);
        }
    } else {
        $insertStmt = $pdo->prepare(
            'INSERT INTO department_info (vision, mission, objectives, image)
             VALUES (:vision, :mission, :objectives, :image)'
        );
        $insertStmt->execute([
            ':vision' => $vision,
            ':mission' => $mission,
            ':objectives' => $objectives,
            ':image' => $imageName,
        ]);
    }

    set_flash('success', 'Department information updated.');
} catch (Throwable $exception) {
    if (!empty($imageName)) {
        delete_image_file('gallery', $imageName);
    }
    set_flash('error', 'Could not update department information: ' . $exception->getMessage());
}

redirect('admin/dashboard.php');
