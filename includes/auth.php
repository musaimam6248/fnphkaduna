<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT u.*, d.name AS department_name, d.hod
         FROM users u
         LEFT JOIN department d ON d.id = u.department_id
         WHERE u.id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => (int) $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        unset($_SESSION['user_id']);
        return null;
    }

    return $user;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function has_role(string $role): bool
{
    $user = current_user();
    return $user !== null && $user['role'] === $role;
}

function require_login(?string $role = null): void
{
    $user = current_user();
    if (!$user) {
        set_flash('error', 'Please sign in to continue.');
        redirect('login.php');
    }

    if ($role !== null && $user['role'] !== $role) {
        set_flash('error', 'You do not have access to that page.');
        if ($user['role'] === 'admin') {
            redirect('admin/dashboard.php');
        }
        redirect('department/dashboard.php');
    }
}

function login_user(string $username, string $password): bool
{
    $stmt = db()->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
    $stmt->execute([':username' => normalize_text($username)]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['user_id'] = (int) $user['id'];
    return true;
}

function logout_user(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    session_destroy();
}

function register_department_user(
    string $departmentName,
    string $hodName,
    string $username,
    string $password,
    string $email = ''
): void {
    $departmentName = normalize_text($departmentName);
    $hodName = normalize_text($hodName);
    $username = normalize_text($username);
    $email = normalize_text($email);

    if ($departmentName === '' || $hodName === '' || $username === '' || $password === '') {
        throw new InvalidArgumentException('Please fill in all required registration fields.');
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $deptStmt = $pdo->prepare(
            'INSERT INTO department (name, hod, password_plain, password_hash)
             VALUES (:name, :hod, :password_plain, :password_hash)'
        );
        $deptStmt->execute([
            ':name' => $departmentName,
            ':hod' => $hodName,
            ':password_plain' => $password,
            ':password_hash' => $passwordHash,
        ]);

        $departmentId = (int) $pdo->lastInsertId();

        $userStmt = $pdo->prepare(
            'INSERT INTO users (username, email, password_hash, role, department_id)
             VALUES (:username, :email, :password_hash, :role, :department_id)'
        );
        $userStmt->execute([
            ':username' => $username,
            ':email' => $email !== '' ? $email : null,
            ':password_hash' => $passwordHash,
            ':role' => 'department',
            ':department_id' => $departmentId,
        ]);

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function current_department_id(): ?int
{
    $user = current_user();
    if (!$user || $user['role'] !== 'department') {
        return null;
    }

    $departmentId = $user['department_id'] ?? null;
    if ($departmentId === null || $departmentId === '') {
        return null;
    }

    $departmentId = (int) $departmentId;
    return $departmentId > 0 ? $departmentId : null;
}

function reset_department_password_with_hod_key(string $username, string $hodName, string $newPassword): bool
{
    $username = normalize_text($username);
    $hodName = normalize_text($hodName);

    if ($username === '' || $hodName === '' || $newPassword === '') {
        throw new InvalidArgumentException('Username, HOD name, and new password are required.');
    }

    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT u.id AS user_id, d.id AS department_id
         FROM users u
         INNER JOIN department d ON d.id = u.department_id
         WHERE u.role = 'department'
           AND LOWER(u.username) = LOWER(:username)
           AND LOWER(d.hod) = LOWER(:hod)
         LIMIT 1"
    );
    $stmt->execute([
        ':username' => $username,
        ':hod' => $hodName,
    ]);
    $record = $stmt->fetch();

    if (!$record) {
        return false;
    }

    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $pdo->beginTransaction();

    try {
        $updateUserStmt = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
        $updateUserStmt->execute([
            ':password_hash' => $passwordHash,
            ':id' => (int) $record['user_id'],
        ]);

        $updateDeptStmt = $pdo->prepare('UPDATE department SET password_plain = :password_plain, password_hash = :password_hash WHERE id = :id');
        $updateDeptStmt->execute([
            ':password_plain' => $newPassword,
            ':password_hash' => $passwordHash,
            ':id' => (int) $record['department_id'],
        ]);

        $pdo->commit();
        return true;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
