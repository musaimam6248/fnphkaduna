<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/image.php';

require_login('admin');

if (!is_post()) {
    redirect('admin/dashboard.php');
}

$submissionId = (int) ($_POST['submission_id'] ?? 0);
$winnerReasons = normalize_text($_POST['winner_reasons'] ?? '');

if ($submissionId <= 0 || $winnerReasons === '') {
    set_flash('error', 'Select a submission and add the winner reasons.');
    redirect('admin/dashboard.php');
}

$pdo = db();
$submissionStmt = $pdo->prepare(
    "SELECT s.id, s.department_id, s.staff_id, s.month, s.year, st.name AS staff_name, st.photo AS staff_photo
     FROM submission s
     INNER JOIN staff st ON st.id = s.staff_id
     WHERE s.id = :id
     LIMIT 1"
);
$submissionStmt->execute([':id' => $submissionId]);
$submission = $submissionStmt->fetch();

if (!$submission) {
    set_flash('error', 'The selected submission was not found.');
    redirect('admin/dashboard.php');
}

$duplicateWinnerStmt = $pdo->prepare(
    'SELECT id FROM winner WHERE staff_id = :staff_id AND month = :month AND year = :year LIMIT 1'
);
$duplicateWinnerStmt->execute([
    ':staff_id' => (int) $submission['staff_id'],
    ':month' => (int) $submission['month'],
    ':year' => (int) $submission['year'],
]);
if ($duplicateWinnerStmt->fetch()) {
    set_flash('error', 'This staff member is already the winner for that month.');
    redirect('admin/dashboard.php');
}

$currentUser = current_user();
$adminId = $currentUser ? (int) $currentUser['id'] : null;
$winnerImage = null;

try {
    $pdo->beginTransaction();
    ensure_upload_directories();

    $existingWinnerStmt = $pdo->prepare('SELECT id, image FROM winner WHERE month = :month AND year = :year LIMIT 1');
    $existingWinnerStmt->execute([
        ':month' => (int) $submission['month'],
        ':year' => (int) $submission['year'],
    ]);
    $existingWinner = $existingWinnerStmt->fetch();

    $previousWinnerImage = $existingWinner['image'] ?? null;
    $sourcePhoto = $submission['staff_photo'] ?? '';
    if ($sourcePhoto !== '') {
        $sourcePath = UPLOAD_PATH . '/staff/' . $sourcePhoto;
        if (is_file($sourcePath)) {
            $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
            $extension = $extension !== '' ? $extension : 'jpg';
            $winnerImage = bin2hex(random_bytes(16)) . '.' . $extension;
            $destinationPath = UPLOAD_PATH . '/winners/' . $winnerImage;
            if (!copy($sourcePath, $destinationPath)) {
                $winnerImage = null;
            }
        }
    }

    $markReviewedStmt = $pdo->prepare(
        "UPDATE submission
         SET status = 'reviewed'
         WHERE month = :month AND year = :year AND status = 'winner'"
    );
    $markReviewedStmt->execute([
        ':month' => (int) $submission['month'],
        ':year' => (int) $submission['year'],
    ]);

    $winnerStmt = $pdo->prepare(
        "INSERT INTO winner (staff_id, submission_id, image, month, year, reasons, selected_by)
         VALUES (:staff_id, :submission_id, :image, :month, :year, :reasons, :selected_by)
         ON CONFLICT(month, year) DO UPDATE SET
            staff_id = excluded.staff_id,
            submission_id = excluded.submission_id,
            image = excluded.image,
            reasons = excluded.reasons,
            selected_by = excluded.selected_by,
            created_at = CURRENT_TIMESTAMP"
    );
    $winnerStmt->execute([
        ':staff_id' => (int) $submission['staff_id'],
        ':submission_id' => (int) $submission['id'],
        ':image' => $winnerImage,
        ':month' => (int) $submission['month'],
        ':year' => (int) $submission['year'],
        ':reasons' => $winnerReasons,
        ':selected_by' => $adminId,
    ]);

    $markWinnerStmt = $pdo->prepare("UPDATE submission SET status = 'winner' WHERE id = :id");
    $markWinnerStmt->execute([':id' => (int) $submission['id']]);

    $notifyStmt = $pdo->prepare('INSERT INTO notifications (department_id, message) VALUES (:department_id, :message)');
    $notifyStmt->execute([
        ':department_id' => (int) $submission['department_id'],
        ':message' => 'Congratulations! Your department staff "' . $submission['staff_name'] . '" has been selected as the monthly winner for ' .
            format_month_year((int) $submission['month'], (int) $submission['year']) . '.',
    ]);

    $pdo->commit();
    if (!empty($previousWinnerImage) && $previousWinnerImage !== $winnerImage) {
        delete_image_file('winners', (string) $previousWinnerImage);
    }
    set_flash('success', 'Monthly winner selected.');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (!empty($winnerImage)) {
        delete_image_file('winners', $winnerImage);
    }
    set_flash('error', 'Could not select winner: ' . $exception->getMessage());
}

redirect('admin/dashboard.php');
