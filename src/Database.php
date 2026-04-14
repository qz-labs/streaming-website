<?php
declare(strict_types=1);

class Database {
    private static ?PDO $instance = null;

    public static function get(): PDO {
        if (self::$instance === null) {
            $dbDir  = __DIR__ . '/../database';
            $dbFile = $dbDir . '/users.db';

            if (!is_dir($dbDir)) {
                mkdir($dbDir, 0755, true);
            }

            self::$instance = new PDO('sqlite:' . $dbFile);
            self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$instance->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            self::$instance->exec('PRAGMA journal_mode=WAL');
            self::$instance->exec('PRAGMA foreign_keys=ON');

            self::migrate(self::$instance);
            self::seed(self::$instance);
        }
        return self::$instance;
    }

    private static function migrate(PDO $db): void {
        $db->exec("
            CREATE TABLE IF NOT EXISTS users (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                email      TEXT    UNIQUE NOT NULL,
                password   TEXT    NOT NULL,
                role       TEXT    NOT NULL DEFAULT 'user',
                status     TEXT    NOT NULL DEFAULT 'pending',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    private static function seed(PDO $db): void {
        // Create the admin account if it doesn't exist yet.
        $stmt = $db->prepare("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
        $stmt->execute();
        if ($stmt->fetch()) {
            return; // Admin already exists
        }

        $hash = password_hash('admin', PASSWORD_BCRYPT);
        $db->prepare("
            INSERT INTO users (email, password, role, status)
            VALUES (?, ?, 'admin', 'approved')
        ")->execute(['qzijlstra4@gmail.com', $hash]);
    }
}
