<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_login('admin');

if (!is_post()) {
    redirect('admin/dashboard.php');
}

$departmentTarget = normalize_text($_POST['department_target'] ?? 'all');
$subject = normalize_text($_POST['subject'] ?? '');
$message = normalize_text($_POST['message'] ?? '');

if ($subject === '' || $message === '') {
    set_flash('error', 'Subject and message are required.');
    redirect('admin/dashboard.php');
}

$pdo = db();

$departmentRows = [];
if ($departmentTarget === 'all') {
    $departmentRows = $pdo->query(
        "SELECT d.id, u.email
         FROM department d
         LEFT JOIN users u ON u.department_id = d.id AND u.role = 'department'
         ORDER BY d.name ASC"
    )->fetchAll();
} else {
    $targetId = (int) $departmentTarget;
    $stmt = $pdo->prepare(
        "SELECT d.id, u.email
         FROM department d
         LEFT JOIN users u ON u.department_id = d.id AND u.role = 'department'
         WHERE d.id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $targetId]);
    $row = $stmt->fetch();
    if ($row) {
        $departmentRows[] = $row;
    }
}

if (!$departmentRows) {
    set_flash('error', 'No department recipients were found.');
    redirect('admin/dashboard.php');
}

$notificationText = $subject . ': ' . $message;

try {
    $pdo->beginTransaction();

    $notifyStmt = $pdo->prepare('INSERT INTO notifications (department_id, message) VALUES (:department_id, :message)');
    $emailLogStmt = $pdo->prepare(
        'INSERT INTO email_logs (recipient_email, subject, body, status)
         VALUES (:recipient_email, :subject, :body, :status)'
    );

    $sentCount = 0;
    foreach ($departmentRows as $departmentRow) {
        $departmentId = (int) $departmentRow['id'];
        $email = normalize_text((string) ($departmentRow['email'] ?? ''));

        $notifyStmt->execute([
            ':department_id' => $departmentId,
            ':message' => $notificationText,
        ]);

        $status = 'no_email';
        if ($email !== '') {
            $mailSent = @mail($email, $subject, $message);
            $status = $mailSent ? 'sent' : 'failed';
            if ($mailSent) {
                $sentCount++;
            }
        }

        $emailLogStmt->execute([
            ':recipient_email' => $email !== '' ? $email : 'n/a',
            ':subject' => $subject,
            ':body' => $message,
            ':status' => $status,
        ]);
    }

    $pdo->commit();
    set_flash('success', 'Notification sent to ' . count($departmentRows) . ' department(s). Email delivered to ' . $sentCount . ' recipient(s).');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    set_flash('error', 'Could not send notification: ' . $exception->getMessage());
}

redirect('admin/dashboard.php');
