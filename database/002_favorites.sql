CREATE TABLE IF NOT EXISTS favorites (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    content_type  ENUM('movie','tv','anime') NOT NULL,
    content_id    INT UNSIGNED NOT NULL,
    content_title VARCHAR(500) NOT NULL DEFAULT '',
    poster_path   VARCHAR(500) NOT NULL DEFAULT '',
    added_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_fav (user_id, content_type, content_id),
    INDEX idx_user_added (user_id, added_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
