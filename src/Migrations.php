<?php
declare(strict_types=1);

class Migrations
{
    /**
     * Run all pending SQL migrations in filename order.
     * Each file contains a single CREATE TABLE IF NOT EXISTS statement.
     * Safe to call on every page load — IF NOT EXISTS makes it idempotent.
     * Also seeds the default admin account if none exists.
     */
    public static function run(): void
    {
        $db  = Database::get();
        $dir = __DIR__ . '/../database';

        $files = glob($dir . '/*.sql');
        if (!$files) return;
        sort($files); // run in filename order: 000_, 001_, 002_, ...

        foreach ($files as $file) {
            $sql = file_get_contents($file);
            // Split on semicolons to support multi-statement files
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                if ($stmt !== '') {
                    $db->exec($stmt);
                }
            }
        }

        // Seed default admin if none exists yet
        $count = (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
        if ($count === 0) {
            $adminUsername = $_ENV['ADMIN_USERNAME'] ?? 'quintoniusvlint';
            $adminPassword = $_ENV['ADMIN_PASSWORD'] ?? 'changeme';
            $hash = password_hash($adminPassword, PASSWORD_BCRYPT);
            $db->prepare(
                "INSERT INTO users (username, password, role, status) VALUES (?, ?, 'admin', 'approved')"
            )->execute([$adminUsername, $hash]);
        }
    }
}
