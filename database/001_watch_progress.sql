CREATE TABLE IF NOT EXISTS watch_progress (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id          INT UNSIGNED NOT NULL,
    content_type     ENUM('movie','tv','anime') NOT NULL,
    content_id       INT UNSIGNED NOT NULL,
    content_title    VARCHAR(500) NOT NULL DEFAULT '',
    poster_path      VARCHAR(500) NOT NULL DEFAULT '',
    season           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    episode          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    episode_title    VARCHAR(500) NOT NULL DEFAULT '',
    progress_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                     ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_content (user_id, content_type, content_id),
    INDEX idx_user_updated (user_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
