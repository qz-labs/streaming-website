<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../src/Auth.php';

header('Content-Type: application/json; charset=utf-8');

$user = currentUser();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$url = trim($_GET['url'] ?? '');
if (!$url || !preg_match('#^https?://#i', $url)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid url parameter']);
    exit;
}

// ── Fetch the resource ────────────────────────────────────────────────────────
$caBundle = CURL_CA_BUNDLE;
$isLocal  = in_array(parse_url($url, PHP_URL_HOST), ['localhost', '127.0.0.1', '::1'], true);
$verify   = !$isLocal && $caBundle !== '' && file_exists($caBundle);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_HTTPHEADER     => [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
        'Referer: https://vidsrc.stream/',
        'Origin: https://vidsrc.stream',
    ],
    CURLOPT_SSL_VERIFYPEER => $verify,
    CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
    CURLOPT_CAINFO         => $verify ? $caBundle : null,
]);
$body     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$mime     = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($body === false || $httpCode < 200 || $httpCode >= 400) {
    http_response_code(502);
    echo json_encode(['error' => 'Upstream fetch failed']);
    exit;
}

// ── Build proxy base for rewriting m3u8 segment URLs ─────────────────────────
// Use the hostname the browser used — Chromecast reaches the server by this host.
$scheme    = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
$reqHost   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$basePath  = parse_url(BASE_URL, PHP_URL_PATH) ?? BASE_URL;
$proxyBase = $scheme . '://' . $reqHost . rtrim($basePath, '/') . '/api/cast-stream.php?url=';

// ── Detect m3u8 ───────────────────────────────────────────────────────────────
$urlPath = parse_url($url, PHP_URL_PATH) ?? '';
$isM3u8  = str_contains((string)$mime, 'mpegurl')
        || str_contains((string)$mime, 'x-mpegurl')
        || str_ends_with(strtolower($urlPath), '.m3u8')
        || str_ends_with(strtolower($urlPath), '.m3u');

if ($isM3u8) {
    // Rewrite all segment/playlist/key URIs to go through this proxy
    $manifestBase = preg_replace('#[^/?#]*(?:[?#].*)?$#', '', $url); // directory of the manifest URL
    $lines = explode("\n", $body);
    foreach ($lines as &$line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            // Rewrite EXT-X-KEY URI
            if (str_contains($trimmed, 'URI="')) {
                $line = preg_replace_callback('/URI="([^"]+)"/', static function (array $m) use ($proxyBase, $manifestBase): string {
                    $abs = str_starts_with($m[1], 'http') ? $m[1] : $manifestBase . $m[1];
                    return 'URI="' . $proxyBase . rawurlencode($abs) . '"';
                }, $line);
            }
            continue;
        }
        $abs  = str_starts_with($trimmed, 'http') ? $trimmed : $manifestBase . $trimmed;
        $line = $proxyBase . rawurlencode($abs);
    }
    unset($line);
    $body = implode("\n", $lines);
    header('Content-Type: application/vnd.apple.mpegurl');
} else {
    header('Content-Type: ' . ($mime ?: 'application/octet-stream'));
}

header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');
echo $body;
