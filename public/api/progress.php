<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Database.php';

header('Content-Type: application/json; charset=utf-8');

$user = currentUser();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$type     = $body['content_type']     ?? '';
$id       = (int)($body['content_id']       ?? 0);
$title    = trim((string)($body['content_title']   ?? ''));
$poster   = trim((string)($body['poster_path']     ?? ''));
$season   = (int)($body['season']           ?? 0);
$episode  = (int)($body['episode']          ?? 0);
$epTitle  = trim((string)($body['episode_title']   ?? ''));
$progress = (int)($body['progress_seconds'] ?? 0);
$duration = (int)($body['duration_seconds'] ?? 0);

if (!in_array($type, ['movie', 'tv', 'anime'], true) || $id <= 0) {
    http_response_code(422);
    echo json_encode(['error' => 'Invalid content_type or content_id']);
    exit;
}

$progress = max(0, $progress);
$duration = max(0, $duration);
$season   = max(0, $season);
$episode  = max(0, $episode);
$title    = mb_substr($title,   0, 500);
$poster   = mb_substr($poster,  0, 500);
$epTitle  = mb_substr($epTitle, 0, 500);

Database::get()->prepare("
    INSERT INTO watch_progress
        (user_id, content_type, content_id, content_title, poster_path,
         season, episode, episode_title, progress_seconds, duration_seconds)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        content_title    = VALUES(content_title),
        poster_path      = VALUES(poster_path),
        season           = VALUES(season),
        episode          = VALUES(episode),
        episode_title    = VALUES(episode_title),
        progress_seconds = VALUES(progress_seconds),
        duration_seconds = VALUES(duration_seconds),
        updated_at       = CURRENT_TIMESTAMP
")->execute([
    $user['id'], $type, $id, $title, $poster,
    $season, $episode, $epTitle, $progress, $duration,
]);

echo json_encode(['ok' => true]);
