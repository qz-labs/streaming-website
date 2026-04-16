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
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function currentUser(): ?array {
    authStart();
    return $_SESSION['auth_user'] ?? null;
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
    return $user;
}

/**
 * Register a new user (status = pending, requires admin approval).
 * Returns true on success or an error string on failure.
 */
function registerUser(string $username, string $password): true|string {
    $username = trim($username);

    if ($username === '' || !preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
        return 'Username must be 3–30 characters and may only contain letters, numbers, and underscores.';
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
