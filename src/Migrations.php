<?php
declare(strict_types=1);

class Migrations
{
    /**
     * Run all pending SQL migrations in filename order.
     * Each file contains a single CREATE TABLE IF NOT EXISTS statement.
     * Safe to call on every page load - IF NOT EXISTS makes it idempotent.
     * Also seeds the default admin account if none exists.
     */
    public static function run(): void
    {
        $db  = Database::get();
        $dir = __DIR__ . '/../database';

        $files = glob($dir . '/*.sql');
        if (!$files) return;
        sort($files);

        // ── Migration guard: skip if nothing has changed since last run ──────
        $marker     = defined('CACHE_DIR') ? CACHE_DIR . '/.migrations_marker' : '';
        $currentSig = implode('|', array_map(fn($f) => basename($f) . ':' . filesize($f), $files));

        if ($marker !== '' && file_exists($marker) && file_get_contents($marker) === $currentSig) {
            return;
        }

        foreach ($files as $file) {
            $sql = file_get_contents($file);
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                if ($stmt !== '') {
                    $db->exec($stmt);
                }
            }
        }

        // Seed default admin if none exists yet
        $count = (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
        if ($count === 0) {
            $adminUsername = $_ENV['ADMIN_USERNAME'] ?? '';
            $adminPassword = $_ENV['ADMIN_PASSWORD'] ?? '';

            if (
                $adminUsername === ''
                || $adminPassword === ''
                || $adminPassword === 'change_me_to_something_strong'
            ) {
                error_log(
                    '[Migrations] Admin account NOT seeded: set ADMIN_USERNAME and replace '
                    . 'ADMIN_PASSWORD=change_me_to_something_strong in .env'
                );
            } else {
                $hash = password_hash($adminPassword, PASSWORD_BCRYPT);
                $db->prepare(
                    "INSERT INTO users (username, password, role, status) VALUES (?, ?, 'admin', 'approved')"
                )->execute([$adminUsername, $hash]);
            }
        }

        if ($marker !== '') {
            file_put_contents($marker, $currentSig);
        }
    }
}

