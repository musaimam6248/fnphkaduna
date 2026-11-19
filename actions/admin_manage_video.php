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

try {
    if ($action === 'create') {
        $title = normalize_text($_POST['title'] ?? '');
        $videoFile = $_FILES['video'] ?? [];

        if ($title === '') {
            throw new InvalidArgumentException('Video title is required.');
        }

        $videoName = upload_video_clip($videoFile, 'videos', 26214400);
        if ($videoName === null) {
            throw new InvalidArgumentException('Please upload a video file.');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO event_video (video, title) VALUES (:video, :title)'
        );
        $stmt->execute([
            ':video' => $videoName,
            ':title' => $title,
        ]);

        set_flash('success', 'Video added successfully.');
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            throw new InvalidArgumentException('Select a valid video.');
        }

        $fetchStmt = $pdo->prepare('SELECT video FROM event_video WHERE id = :id LIMIT 1');
        $fetchStmt->execute([':id' => $id]);
        $video = $fetchStmt->fetch();
        if (!$video) {
            throw new RuntimeException('Event video not found.');
        }

        $deleteStmt = $pdo->prepare('DELETE FROM event_video WHERE id = :id');
        $deleteStmt->execute([':id' => $id]);
        delete_image_file('videos', $video['video'] ?? null);

        set_flash('success', 'Video deleted successfully.');
    } else {
        throw new InvalidArgumentException('Unsupported video action.');
    }
} catch (Throwable $exception) {
    set_flash('error', 'Could not update video library: ' . $exception->getMessage());
}

redirect('admin/dashboard.php');
