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

// ── GET: check whether a single item is favorited ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $type = $_GET['content_type'] ?? '';
    $id   = (int)($_GET['content_id'] ?? 0);

    if (!in_array($type, ['movie', 'tv', 'anime'], true) || $id <= 0) {
        http_response_code(422);
        echo json_encode(['error' => 'Invalid params']);
        exit;
    }

    $stmt = Database::get()->prepare(
        'SELECT id FROM favorites WHERE user_id=? AND content_type=? AND content_id=?'
    );
    $stmt->execute([$user['id'], $type, $id]);
    echo json_encode(['favorited' => (bool)$stmt->fetch()]);
    exit;
}

// ── POST: toggle favorite (add if missing, remove if present) ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        exit;
    }

    $type   = $body['content_type'] ?? '';
    $id     = (int)($body['content_id']    ?? 0);
    $title  = mb_substr(trim((string)($body['content_title'] ?? '')), 0, 500);
    $poster = mb_substr(trim((string)($body['poster_path']   ?? '')), 0, 500);

    if (!in_array($type, ['movie', 'tv', 'anime'], true) || $id <= 0) {
        http_response_code(422);
        echo json_encode(['error' => 'Invalid content_type or content_id']);
        exit;
    }

    $db   = Database::get();
    $stmt = $db->prepare(
        'SELECT id FROM favorites WHERE user_id=? AND content_type=? AND content_id=?'
    );
    $stmt->execute([$user['id'], $type, $id]);

    if ($stmt->fetch()) {
        $db->prepare(
            'DELETE FROM favorites WHERE user_id=? AND content_type=? AND content_id=?'
        )->execute([$user['id'], $type, $id]);
        echo json_encode(['favorited' => false]);
    } else {
        $db->prepare(
            'INSERT INTO favorites (user_id, content_type, content_id, content_title, poster_path)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$user['id'], $type, $id, $title, $poster]);
        echo json_encode(['favorited' => true]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
