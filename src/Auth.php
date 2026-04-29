<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';

function authStart(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }
}

function csrfToken(): string
{
    authStart();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrf(): void
{
    authStart();
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES) . '">';
}

function currentUser(): ?array {
    authStart();
    return $_SESSION['auth_user'] ?? null;
}

function normalizeLoginUsername(string $username): string
{
    return mb_strtolower(trim($username), 'UTF-8');
}

function loginClientIp(): string
{
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return $ip !== '' ? $ip : 'unknown';
}

function loginLockoutStatus(string $username): array
{
    $normalized = normalizeLoginUsername($username);
    if ($normalized === '') {
        return ['locked' => false, 'remaining_minutes' => 0];
    }

    $stmt = Database::get()->prepare(
        "SELECT locked_until
         FROM login_attempts
         WHERE username_key = ? AND ip_address = ?
         LIMIT 1"
    );
    $stmt->execute([$normalized, loginClientIp()]);
    $row = $stmt->fetch();

    if (!$row || empty($row['locked_until'])) {
        return ['locked' => false, 'remaining_minutes' => 0];
    }

    $lockedUntil = strtotime((string)$row['locked_until']);
    if ($lockedUntil === false || $lockedUntil <= time()) {
        return ['locked' => false, 'remaining_minutes' => 0];
    }

    return [
        'locked' => true,
        'remaining_minutes' => (int)ceil(($lockedUntil - time()) / 60),
    ];
}

function recordFailedLoginAttempt(string $username): void
{
    $normalized = normalizeLoginUsername($username);
    if ($normalized === '') {
        $normalized = '(empty)';
    }

    Database::get()->prepare("
        INSERT INTO login_attempts (username_key, ip_address, attempts, locked_until)
        VALUES (?, ?, 1, NULL)
        ON DUPLICATE KEY UPDATE
            attempts = IF(locked_until IS NOT NULL AND locked_until <= NOW(), 1, attempts + 1),
            locked_until = CASE
                WHEN IF(locked_until IS NOT NULL AND locked_until <= NOW(), 1, attempts + 1) >= 5
                    THEN DATE_ADD(NOW(), INTERVAL 15 MINUTE)
                ELSE NULL
            END,
            updated_at = CURRENT_TIMESTAMP
    ")->execute([$normalized, loginClientIp()]);
}

function clearFailedLoginAttempts(string $username): void
{
    $normalized = normalizeLoginUsername($username);
    if ($normalized === '') {
        return;
    }

    Database::get()->prepare(
        "DELETE FROM login_attempts WHERE username_key = ? AND ip_address = ?"
    )->execute([$normalized, loginClientIp()]);
}

function isLoggedIn(): bool {
    return currentUser() !== null;
}

function isAdmin(): bool {
    $u = currentUser();
    return $u !== null && $u['role'] === 'admin';
}

/**
 * Call at the top of every protected page.
 * Redirects to login if the visitor is not authenticated.
 */
function requireLogin(): void {
    authStart();
    if (!isLoggedIn()) {
        $base = defined('BASE_URL') ? BASE_URL : '';
        header('Location: ' . $base . '/login.php');
        exit;
    }
}

/**
 * Call at the top of admin-only pages.
 * Redirects to home if the logged-in user is not an admin.
 */
function requireAdmin(): void {
    requireLogin();
    if (!isAdmin()) {
        $base = defined('BASE_URL') ? BASE_URL : '';
        header('Location: ' . $base . '/');
        exit;
    }
}

/**
 * Returns the user row on success, or an error string on failure.
 */
function attemptLogin(string $username, string $password): array|string {
    $db   = Database::get();
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([trim($username)]);
    $user = $stmt->fetch();

    if (!$user) {
        return 'Invalid username or password.';
    }
    if (!password_verify($password, $user['password'])) {
        return 'Invalid username or password.';
    }
    if ($user['status'] === 'pending') {
        return 'Your account is awaiting admin approval.';
    }
    if ($user['status'] === 'rejected') {
        return 'Your account request was rejected.';
    }

    authStart();
    $_SESSION['auth_user'] = [
        'id'       => $user['id'],
        'username' => $user['username'],
        'role'     => $user['role'],
    ];
    session_regenerate_id(true);
    clearFailedLoginAttempts($username);
    return $user;
}

/**
 * Register a new user (status = pending, requires admin approval).
 * Returns true on success or an error string on failure.
 */
function registerUser(string $username, string $password): true|string {
    $username = trim($username);

    if ($username === '' || !preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
        return 'Username must be 3-30 characters and may only contain letters, numbers, and underscores.';
    }

    if (strlen($password) < 6) {
        return 'Password must be at least 6 characters.';
    }

    $db   = Database::get();
    $stmt = $db->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        return 'That username is already taken.';
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $db->prepare("
        INSERT INTO users (username, password, role, status)
        VALUES (?, ?, 'user', 'pending')
    ")->execute([$username, $hash]);

    return true;
}

function logout(): void {
    authStart();
    $_SESSION = [];
    session_destroy();
}

