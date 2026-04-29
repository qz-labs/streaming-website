CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username_key VARCHAR(100) NOT NULL,
    ip_address   VARCHAR(45) NOT NULL,
    attempts     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME NULL DEFAULT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                 ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_login_attempts_user_ip (username_key, ip_address),
    INDEX idx_login_attempts_locked (locked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
