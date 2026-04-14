<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';

function authStart(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => false,
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
function attemptLogin(string $email, string $password): array|string {
    $db   = Database::get();
    $stmt = $db->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([trim($email)]);
    $user = $stmt->fetch();

    if (!$user) {
        return 'Invalid email or password.';
    }
    if (!password_verify($password, $user['password'])) {
        return 'Invalid email or password.';
    }
    if ($user['status'] === 'pending') {
        return 'Your account is awaiting admin approval.';
    }
    if ($user['status'] === 'rejected') {
        return 'Your account request was rejected.';
    }

    authStart();
    $_SESSION['auth_user'] = [
        'id'    => $user['id'],
        'email' => $user['email'],
        'role'  => $user['role'],
    ];
    session_regenerate_id(true);
    return $user;
}

/**
 * Register a new user (status = pending, requires admin approval).
 * Returns true on success or an error string on failure.
 */
function registerUser(string $email, string $password): true|string {
    $email = trim($email);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Please enter a valid email address.';
    }
    if (strlen($password) < 6) {
        return 'Password must be at least 6 characters.';
    }

    $db   = Database::get();
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        return 'An account with that email already exists.';
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $db->prepare("
        INSERT INTO users (email, password, role, status)
        VALUES (?, ?, 'user', 'pending')
    ")->execute([$email, $hash]);

    return true;
}

function logout(): void {
    authStart();
    $_SESSION = [];
    session_destroy();
}
