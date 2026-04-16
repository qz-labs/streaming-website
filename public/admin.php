<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Auth.php';

requireAdmin();

$db      = Database::get();
$message = '';
$msgType = 'success';

// ── Handle POST actions ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Approve / reject a pending user
    if ($action === 'approve' || $action === 'reject') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $status = $action === 'approve' ? 'approved' : 'rejected';
        $db->prepare("UPDATE users SET status = ? WHERE id = ? AND role = 'user'")
           ->execute([$status, $userId]);
        $message = $action === 'approve' ? 'User approved.' : 'User rejected.';
    }

    // Delete a user
    if ($action === 'delete') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $db->prepare("DELETE FROM users WHERE id = ? AND role = 'user'")->execute([$userId]);
        $message = 'User deleted.';
    }

    // Change admin credentials
    if ($action === 'update_admin') {
        $newUsername = trim($_POST['new_username'] ?? '');
        $newPassword = $_POST['new_password']      ?? '';
        $confirm     = $_POST['confirm_password']  ?? '';

        if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $newUsername)) {
            $message = 'Username must be 3–30 characters (letters, numbers, underscores only).';
            $msgType = 'error';
        } elseif ($newPassword !== '' && strlen($newPassword) < 6) {
            $message = 'New password must be at least 6 characters.';
            $msgType = 'error';
        } elseif ($newPassword !== '' && $newPassword !== $confirm) {
            $message = 'Passwords do not match.';
            $msgType = 'error';
        } else {
            $adminId = currentUser()['id'];
            // Check username not taken by someone else
            $taken = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1");
            $taken->execute([$newUsername, $adminId]);
            if ($taken->fetch()) {
                $message = 'That username is already taken.';
                $msgType = 'error';
            } else {
                if ($newPassword !== '') {
                    $hash = password_hash($newPassword, PASSWORD_BCRYPT);
                    $db->prepare("UPDATE users SET username = ?, password = ? WHERE id = ?")
                       ->execute([$newUsername, $hash, $adminId]);
                } else {
                    $db->prepare("UPDATE users SET username = ? WHERE id = ?")
                       ->execute([$newUsername, $adminId]);
                }
                // Refresh session
                $_SESSION['auth_user']['username'] = $newUsername;
                $message = 'Admin credentials updated successfully.';
            }
        }
    }

    // Change a user's role
    if ($action === 'set_role') {
        $userId  = (int)($_POST['user_id'] ?? 0);
        $newRole = $_POST['new_role'] ?? '';
        if (in_array($newRole, ['user', 'admin'], true) && $userId !== currentUser()['id']) {
            $db->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$newRole, $userId]);
            $message = 'User role updated.';
        }
    }
}

// ── Fetch data ─────────────────────────────────────────────────────────────────
$pending  = $db->query("SELECT * FROM users WHERE status = 'pending' ORDER BY created_at ASC")->fetchAll();
$approved = $db->query("SELECT * FROM users WHERE status = 'approved' ORDER BY role ASC, created_at DESC")->fetchAll();
$rejected = $db->query("SELECT * FROM users WHERE status = 'rejected' ORDER BY created_at DESC")->fetchAll();
$admin    = currentUser();
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Admin Panel – <?= htmlspecialchars(SITE_NAME) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;900&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg:        #141414;
      --bg-card:   #1f1f1f;
      --bg-sub:    #2a2a2a;
      --red:       #E50914;
      --red-hover: #f40612;
      --green:     #27ae60;
      --text:      #ffffff;
      --muted:     #b3b3b3;
      --input-bg:  #333;
      --radius:    6px;
    }
    body {
      background: var(--bg);
      color: var(--text);
      font-family: 'Inter', Arial, sans-serif;
      min-height: 100vh;
    }

    /* Top bar */
    .topbar {
      background: rgba(0,0,0,0.92);
      padding: 0 4%;
      height: 64px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      position: sticky;
      top: 0;
      z-index: 100;
      border-bottom: 1px solid #2a2a2a;
    }
    .topbar__logo { font-size: 1.4rem; font-weight: 900; color: var(--red); text-decoration: none; }
    .topbar__title { font-size: 1rem; font-weight: 600; color: var(--muted); }
    .topbar__actions { display: flex; gap: 0.75rem; align-items: center; }
    .btn { padding: 0.45rem 1rem; border-radius: var(--radius); font-size: 0.875rem; font-weight: 600; cursor: pointer; border: none; font-family: inherit; text-decoration: none; display: inline-block; }
    .btn-ghost { background: transparent; border: 1px solid #444; color: var(--muted); }
    .btn-ghost:hover { border-color: #888; color: var(--text); }
    .btn-red { background: var(--red); color: #fff; }
    .btn-red:hover { background: var(--red-hover); }
    .btn-green { background: var(--green); color: #fff; }
    .btn-green:hover { background: #2ecc71; }
    .btn-danger { background: #8b0000; color: #ff8080; border: 1px solid #c00; }
    .btn-danger:hover { background: #a00; }
    .btn-sm { padding: 0.3rem 0.7rem; font-size: 0.8rem; }

    /* Layout */
    .container { max-width: 960px; margin: 0 auto; padding: 2rem 1.5rem; }

    /* Alert */
    .alert { padding: 0.85rem 1.1rem; border-radius: var(--radius); font-size: 0.9rem; margin-bottom: 1.5rem; }
    .alert-success { background: rgba(39,174,96,0.15); border: 1px solid var(--green); color: #5dbb78; }
    .alert-error   { background: rgba(229,9,20,0.15);  border: 1px solid var(--red);   color: #ff6b6b; }

    /* Section */
    .section { margin-bottom: 2.5rem; }
    .section__header {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      margin-bottom: 1rem;
      border-bottom: 1px solid #2a2a2a;
      padding-bottom: 0.6rem;
    }
    .section__title { font-size: 1.1rem; font-weight: 700; }
    .badge {
      background: var(--red);
      color: #fff;
      font-size: 0.72rem;
      font-weight: 700;
      padding: 0.15rem 0.5rem;
      border-radius: 99px;
    }
    .badge-gray { background: #444; }
    .badge-green { background: var(--green); }

    /* Table */
    .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .user-table { width: 100%; border-collapse: collapse; min-width: 600px; }
    .user-table th, .user-table td {
      text-align: left;
      padding: 0.7rem 0.9rem;
      font-size: 0.875rem;
      border-bottom: 1px solid #2a2a2a;
    }
    .user-table th { color: var(--muted); font-weight: 600; }
    .user-table tr:last-child td { border-bottom: none; }
    .user-table .actions { display: flex; gap: 0.5rem; }
    .date-cell { color: var(--muted); font-size: 0.8rem; }
    .empty-row td { color: var(--muted); text-align: center; padding: 1.5rem; font-style: italic; }

    .card { background: var(--bg-card); border-radius: 10px; padding: 1.5rem; }

    /* Settings form */
    .settings-grid { display: grid; gap: 1.1rem; }
    .field label { display: block; font-size: 0.83rem; color: var(--muted); margin-bottom: 0.35rem; font-weight: 500; }
    .field input {
      width: 100%;
      padding: 0.7rem 0.9rem;
      background: var(--input-bg);
      border: 1px solid #444;
      border-radius: var(--radius);
      color: var(--text);
      font-size: 0.95rem;
      font-family: inherit;
      outline: none;
      transition: border-color 0.2s;
    }
    .field input:focus { border-color: var(--red); }
    .hint { font-size: 0.78rem; color: var(--muted); margin-top: 0.3rem; }
    .form-footer { margin-top: 1.25rem; }
  </style>
</head>
<body>

<div class="topbar">
  <a class="topbar__logo" href="<?= BASE_URL ?>/"><?= htmlspecialchars(SITE_NAME) ?></a>
  <span class="topbar__title">Admin Panel</span>
  <div class="topbar__actions">
    <a href="<?= BASE_URL ?>/" class="btn btn-ghost">← Back to Site</a>
    <a href="<?= BASE_URL ?>/logout.php" class="btn btn-ghost">Sign Out</a>
  </div>
</div>

<div class="container">

  <?php if ($message): ?>
    <div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>

  <!-- ── Pending Requests ─────────────────────────────────────────────────── -->
  <div class="section">
    <div class="section__header">
      <span class="section__title">Pending Account Requests</span>
      <?php if (count($pending) > 0): ?>
        <span class="badge"><?= count($pending) ?></span>
      <?php endif; ?>
    </div>
    <div class="card">
      <div class="table-wrap">
      <table class="user-table">
        <thead>
          <tr>
            <th>Username</th>
            <th>Requested</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($pending)): ?>
            <tr class="empty-row"><td colspan="3">No pending requests</td></tr>
          <?php else: ?>
            <?php foreach ($pending as $u): ?>
            <tr>
              <td><?= htmlspecialchars($u['username']) ?></td>
              <td class="date-cell"><?= htmlspecialchars($u['created_at']) ?></td>
              <td>
                <div class="actions">
                  <form method="POST">
                    <input type="hidden" name="action"  value="approve">
                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                    <button type="submit" class="btn btn-green btn-sm">Approve</button>
                  </form>
                  <form method="POST">
                    <input type="hidden" name="action"  value="reject">
                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                    <button type="submit" class="btn btn-danger btn-sm">Reject</button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
      </div>
    </div>
  </div>

  <!-- ── Approved Users ──────────────────────────────────────────────────── -->
  <div class="section">
    <div class="section__header">
      <span class="section__title">Approved Users</span>
      <span class="badge badge-green"><?= count($approved) ?></span>
    </div>
    <div class="card">
      <div class="table-wrap">
      <table class="user-table">
        <thead>
          <tr>
            <th>Username</th>
            <th>Role</th>
            <th>Joined</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($approved)): ?>
            <tr class="empty-row"><td colspan="4">No approved users yet</td></tr>
          <?php else: ?>
            <?php foreach ($approved as $u): ?>
            <?php $isSelf = (int)$u['id'] === (int)$admin['id']; ?>
            <tr>
              <td><?= htmlspecialchars($u['username']) ?></td>
              <td><?= $u['role'] === 'admin' ? '<strong>Admin</strong>' : 'User' ?></td>
              <td class="date-cell"><?= htmlspecialchars($u['created_at']) ?></td>
              <td>
                <div class="actions">
                  <?php if (!$isSelf): ?>
                    <?php if ($u['role'] === 'user'): ?>
                    <form method="POST" onsubmit="return confirm('Promote <?= htmlspecialchars($u['username']) ?> to admin?')">
                      <input type="hidden" name="action"   value="set_role">
                      <input type="hidden" name="user_id"  value="<?= (int)$u['id'] ?>">
                      <input type="hidden" name="new_role" value="admin">
                      <button type="submit" class="btn btn-green btn-sm">Make Admin</button>
                    </form>
                    <form method="POST" onsubmit="return confirm('Revoke access for <?= htmlspecialchars($u['username']) ?>?')">
                      <input type="hidden" name="action"  value="reject">
                      <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                      <button type="submit" class="btn btn-danger btn-sm">Revoke</button>
                    </form>
                    <?php else: ?>
                    <form method="POST" onsubmit="return confirm('Demote <?= htmlspecialchars($u['username']) ?> to regular user?')">
                      <input type="hidden" name="action"   value="set_role">
                      <input type="hidden" name="user_id"  value="<?= (int)$u['id'] ?>">
                      <input type="hidden" name="new_role" value="user">
                      <button type="submit" class="btn btn-ghost btn-sm">Demote</button>
                    </form>
                    <?php endif; ?>
                    <form method="POST" onsubmit="return confirm('Delete <?= htmlspecialchars($u['username']) ?> permanently?')">
                      <input type="hidden" name="action"  value="delete">
                      <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                      <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                    </form>
                  <?php else: ?>
                    <span style="color:var(--muted);font-size:0.8rem">(you)</span>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
      </div>
    </div>
  </div>

  <!-- ── Rejected Users ─────────────────────────────────────────────────── -->
  <?php if (!empty($rejected)): ?>
  <div class="section">
    <div class="section__header">
      <span class="section__title">Rejected Users</span>
      <span class="badge badge-gray"><?= count($rejected) ?></span>
    </div>
    <div class="card">
      <div class="table-wrap">
      <table class="user-table">
        <thead>
          <tr>
            <th>Username</th>
            <th>Requested</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rejected as $u): ?>
          <tr>
            <td><?= htmlspecialchars($u['username']) ?></td>
            <td class="date-cell"><?= htmlspecialchars($u['created_at']) ?></td>
            <td>
              <div class="actions">
                <form method="POST">
                  <input type="hidden" name="action"  value="approve">
                  <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                  <button type="submit" class="btn btn-green btn-sm">Approve</button>
                </form>
                <form method="POST" onsubmit="return confirm('Delete this user permanently?')">
                  <input type="hidden" name="action"  value="delete">
                  <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                  <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Admin Credentials ──────────────────────────────────────────────── -->
  <div class="section">
    <div class="section__header">
      <span class="section__title">Admin Credentials</span>
    </div>
    <div class="card">
      <form method="POST" class="settings-grid">
        <input type="hidden" name="action" value="update_admin">

        <div class="field">
          <label for="new_username">Admin Username</label>
          <input
            type="text"
            id="new_username"
            name="new_username"
            required
            value="<?= htmlspecialchars($admin['username']) ?>"
          >
        </div>

        <div class="field">
          <label for="new_password">New Password</label>
          <input
            type="password"
            id="new_password"
            name="new_password"
            autocomplete="new-password"
            placeholder="Leave blank to keep current password"
          >
          <div class="hint">Minimum 6 characters. Leave blank to keep your current password.</div>
        </div>

        <div class="field">
          <label for="confirm_password">Confirm New Password</label>
          <input
            type="password"
            id="confirm_password"
            name="confirm_password"
            autocomplete="new-password"
            placeholder="Repeat new password"
          >
        </div>

        <div class="form-footer">
          <button type="submit" class="btn btn-red">Save Credentials</button>
        </div>
      </form>
    </div>
  </div>

</div><!-- /container -->
</body>
</html>
