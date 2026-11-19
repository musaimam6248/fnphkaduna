<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/image.php';

require_login('admin');

if (!is_post()) {
    redirect('admin/dashboard.php');
}

$schoolName = normalize_text((string) ($_POST['school_name'] ?? ''));
if ($schoolName === '' || strlen($schoolName) > 120) {
    set_flash('error', 'Enter a school name of up to 120 characters.');
    redirect('admin/dashboard.php#admin-school-branding');
}

$pdo = db();
$current = $pdo->query('SELECT school_badge FROM homepage_settings WHERE id = 1 LIMIT 1')->fetch() ?: [];
$newBadgeName = null;

try {
    if (($_POST['remove_badge'] ?? '') === '1') {
        $pdo->prepare('UPDATE homepage_settings SET school_name = :school_name, school_badge = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = 1')
            ->execute([':school_name' => $schoolName]);
        delete_image_file('gallery', $current['school_badge'] ?? null);
        set_flash('success', 'School badge removed.');
    } else {
        $badgeName = upload_and_resize_image($_FILES['school_badge'] ?? [], 'gallery', 500, 500);
        $newBadgeName = $badgeName;
        $sql = 'UPDATE homepage_settings SET school_name = :school_name, updated_at = CURRENT_TIMESTAMP';
        $params = [':school_name' => $schoolName];
        if ($badgeName !== null) {
            $sql .= ', school_badge = :school_badge';
            $params[':school_badge'] = $badgeName;
        }
        $sql .= ' WHERE id = 1';
        $pdo->prepare($sql)->execute($params);
        if ($badgeName !== null) {
            $newBadgeName = null;
            delete_image_file('gallery', $current['school_badge'] ?? null);
        }
        set_flash('success', 'School branding updated.');
    }
} catch (Throwable $exception) {
    if ($newBadgeName !== null) {
        delete_image_file('gallery', $newBadgeName);
    }
    set_flash('error', 'Could not update school branding: ' . $exception->getMessage());
}

redirect('admin/dashboard.php#admin-school-branding');
