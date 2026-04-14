<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Auth.php';

authStart();

// Already logged in → go home
if (isLoggedIn()) {
    header('Location: ' . BASE_URL . '/');
    exit;
}

$mode    = $_POST['mode'] ?? $_GET['mode'] ?? 'login'; // 'login' | 'register'
$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode     = $_POST['mode'] ?? 'login';
    $email    = $_POST['email']    ?? '';
    $password = $_POST['password'] ?? '';

    if ($mode === 'login') {
        $result = attemptLogin($email, $password);
        if (is_string($result)) {
            $error = $result;
        } else {
            header('Location: ' . BASE_URL . '/');
            exit;
        }
    } elseif ($mode === 'register') {
        $result = registerUser($email, $password);
        if ($result === true) {
            $success = 'Account request submitted! You will be able to log in once an admin approves your account.';
            $mode    = 'login';
        } else {
            $error = $result;
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars(SITE_NAME) ?> – <?= $mode === 'register' ? 'Create Account' : 'Sign In' ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;900&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg:       #141414;
      --bg-card:  #1f1f1f;
      --red:      #E50914;
      --red-hover:#f40612;
      --text:     #ffffff;
      --muted:    #b3b3b3;
      --input-bg: #333;
      --radius:   6px;
    }
    body {
      background: var(--bg);
      color: var(--text);
      font-family: 'Inter', Arial, sans-serif;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 1rem;
    }
    .logo {
      font-size: 2rem;
      font-weight: 900;
      color: var(--red);
      text-decoration: none;
      margin-bottom: 2rem;
      display: block;
      text-align: center;
      letter-spacing: -0.5px;
    }
    .card {
      background: var(--bg-card);
      border-radius: 10px;
      padding: 2.5rem 2rem;
      width: 100%;
      max-width: 420px;
      box-shadow: 0 8px 40px rgba(0,0,0,0.6);
    }
    .card h1 {
      font-size: 1.6rem;
      font-weight: 700;
      margin-bottom: 1.5rem;
    }
    .field { margin-bottom: 1.1rem; }
    .field label {
      display: block;
      font-size: 0.85rem;
      color: var(--muted);
      margin-bottom: 0.35rem;
      font-weight: 500;
    }
    .field input {
      width: 100%;
      padding: 0.75rem 1rem;
      background: var(--input-bg);
      border: 1px solid #444;
      border-radius: var(--radius);
      color: var(--text);
      font-size: 1rem;
      font-family: inherit;
      transition: border-color 0.2s;
      outline: none;
    }
    .field input:focus { border-color: var(--red); }
    .btn-primary {
      width: 100%;
      padding: 0.8rem 1rem;
      background: var(--red);
      color: #fff;
      font-size: 1rem;
      font-weight: 700;
      border: none;
      border-radius: var(--radius);
      cursor: pointer;
      transition: background 0.2s;
      margin-top: 0.5rem;
      font-family: inherit;
    }
    .btn-primary:hover { background: var(--red-hover); }
    .alert {
      padding: 0.75rem 1rem;
      border-radius: var(--radius);
      font-size: 0.9rem;
      margin-bottom: 1.25rem;
    }
    .alert-error   { background: rgba(229,9,20,0.15); border: 1px solid var(--red); color: #ff6b6b; }
    .alert-success { background: rgba(39,174,96,0.15); border: 1px solid #27ae60;  color: #5dbb78; }
    .toggle-link {
      text-align: center;
      margin-top: 1.4rem;
      font-size: 0.9rem;
      color: var(--muted);
    }
    .toggle-link a {
      color: var(--text);
      font-weight: 600;
      text-decoration: underline;
      cursor: pointer;
    }
    .toggle-link a:hover { color: var(--red); }
  </style>
</head>
<body>

  <a class="logo" href="<?= BASE_URL ?>/"><?= htmlspecialchars(SITE_NAME) ?></a>

  <div class="card">
    <h1><?= $mode === 'register' ? 'Create Account' : 'Sign In' ?></h1>

    <?php if ($error): ?>
      <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <form method="POST" action="<?= BASE_URL ?>/login.php" autocomplete="on">
      <input type="hidden" name="mode" value="<?= htmlspecialchars($mode) ?>">

      <div class="field">
        <label for="email">Email address</label>
        <input
          type="email"
          id="email"
          name="email"
          required
          autocomplete="email"
          value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
          placeholder="you@example.com"
        >
      </div>

      <div class="field">
        <label for="password">Password</label>
        <input
          type="password"
          id="password"
          name="password"
          required
          autocomplete="<?= $mode === 'register' ? 'new-password' : 'current-password' ?>"
          placeholder="<?= $mode === 'register' ? 'At least 6 characters' : '••••••••' ?>"
        >
      </div>

      <button type="submit" class="btn-primary">
        <?= $mode === 'register' ? 'Request Account' : 'Sign In' ?>
      </button>
    </form>

    <div class="toggle-link">
      <?php if ($mode === 'register'): ?>
        Already have an account?
        <a href="<?= BASE_URL ?>/login.php?mode=login">Sign in</a>
      <?php else: ?>
        Don't have an account?
        <a href="<?= BASE_URL ?>/login.php?mode=register">Create one</a>
      <?php endif; ?>
    </div>
  </div>

</body>
</html>
