<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_login('admin');

if (!is_post()) {
    redirect('admin/dashboard.php');
}

$opensAtRaw = normalize_text($_POST['opens_at'] ?? '');
$closesAtRaw = normalize_text($_POST['closes_at'] ?? '');
$note = normalize_text($_POST['note'] ?? '');
$isEnabled = isset($_POST['is_enabled']) ? 1 : 0;

$openDate = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $opensAtRaw);
$closeDate = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $closesAtRaw);

if (!$openDate || !$closeDate) {
    set_flash('error', 'Provide valid open and close date/time values.');
    redirect('admin/dashboard.php');
}

if ($openDate >= $closeDate) {
    set_flash('error', 'Close time must be later than open time.');
    redirect('admin/dashboard.php');
}

$openDb = $openDate->format('Y-m-d H:i:s');
$closeDb = $closeDate->format('Y-m-d H:i:s');
$admin = current_user();
$adminId = $admin ? (int) $admin['id'] : null;

try {
    $stmt = db()->prepare(
        "INSERT INTO submission_schedule (id, opens_at, closes_at, is_enabled, note, updated_by, updated_at)
         VALUES (1, :opens_at, :closes_at, :is_enabled, :note, :updated_by, CURRENT_TIMESTAMP)
         ON CONFLICT(id) DO UPDATE SET
             opens_at = excluded.opens_at,
             closes_at = excluded.closes_at,
             is_enabled = excluded.is_enabled,
             note = excluded.note,
             updated_by = excluded.updated_by,
             updated_at = CURRENT_TIMESTAMP"
    );
    $stmt->execute([
        ':opens_at' => $openDb,
        ':closes_at' => $closeDb,
        ':is_enabled' => $isEnabled,
        ':note' => $note !== '' ? $note : null,
        ':updated_by' => $adminId,
    ]);

    set_flash('success', 'Submission window updated.');
} catch (Throwable $exception) {
    set_flash('error', 'Could not update submission window: ' . $exception->getMessage());
}

redirect('admin/dashboard.php');
