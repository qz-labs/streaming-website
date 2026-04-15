<?php
declare(strict_types=1);

class Database {
    private static ?PDO $instance = null;

    public static function get(): PDO {
        if (self::$instance === null) {
            $host   = env('DB_HOST',     'localhost');
            $port   = env('DB_PORT',     '3306');
            $dbname = env('DB_NAME',     'streamingwebsite');
            $user   = env('DB_USER',     'root');
            $pass   = env('DB_PASSWORD', '');

            // Connect without a database name first so we can create it if missing
            $rootDsn = "mysql:host={$host};port={$port};charset=utf8mb4";
            $pdo = new PDO($rootDsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);

            // Create the database if it doesn't exist yet
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbname}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$dbname}`");

            self::$instance = $pdo;
        }
        return self::$instance;
    }
}
