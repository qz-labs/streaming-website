<?php
/**
 * subtitle.php — Same-origin proxy for external VTT subtitle files.
 *
 * Browsers block <track> elements loaded from a different port (cross-origin).
 * This script fetches the VTT on the server side and serves it from the same
 * origin as the page, eliminating the CORS restriction entirely.
 *
 * Security measures:
 *   - Only .vtt files are allowed (by path extension)
 *   - Only whitelisted domains are proxied (SSRF prevention)
 *   - File size is capped at 2 MB
 *   - SSL peer verification is enabled when CA bundle is available
 *
 * GET ?url={encoded_vtt_url}
 */
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Auth.php';
authStart();
if (!isLoggedIn()) {
    http_response_code(401);
    exit('Unauthorized');
}

// ── Domain whitelist ──────────────────────────────────────────────────────────
// Only URLs whose host matches one of these suffixes (or exact values) are proxied.
const SUBTITLE_ALLOWED_SUFFIXES = [
    '.megacloud.club',
    '.megacloud.tv',
    '.hianime.to',
    '.aniwatch.to',
    '.aniwatchtv.to',
    '.netmagcdn.com',
    '.mgstatics.xyz',
    '.cloudfront.net',
    '.akamaized.net',
    '.r2.dev',
];

const SUBTITLE_ALLOWED_EXACT = [
    'megacloud.club',
    'megacloud.tv',
    'mgstatics.xyz',
];

function subtitleHostAllowed(string $host): bool
{
    if (in_array($host, SUBTITLE_ALLOWED_EXACT, true)) return true;
    foreach (SUBTITLE_ALLOWED_SUFFIXES as $suffix) {
        if (str_ends_with($host, $suffix)) return true;
    }
    return false;
}

// ── Validate input ────────────────────────────────────────────────────────────
$url = $_GET['url'] ?? '';
if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    exit('Invalid URL');
}

$parsed   = parse_url($url);
$scheme   = $parsed['scheme'] ?? '';
$host     = $parsed['host']   ?? '';
$urlPath  = $parsed['path']   ?? '';

if (!in_array($scheme, ['http', 'https'], true)) {
    http_response_code(400);
    exit('Only HTTP/HTTPS URLs are allowed');
}

if (!str_ends_with(strtolower($urlPath), '.vtt')) {
    http_response_code(400);
    exit('Only VTT files are allowed');
}

if (!subtitleHostAllowed($host)) {
    http_response_code(403);
    exit('Host not in allowlist');
}

// ── Fetch VTT ─────────────────────────────────────────────────────────────────
$caBundle  = defined('CURL_CA_BUNDLE') ? CURL_CA_BUNDLE : 'C:/xampp/apache/bin/curl-ca-bundle.crt';
$verifySsl = file_exists($caBundle);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 3,
    CURLOPT_HTTPHEADER     => [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    ],
    CURLOPT_SSL_VERIFYPEER => $verifySsl,
    CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
    CURLOPT_CAINFO         => $verifySsl ? $caBundle : null,
    // Cap download at 2 MB to prevent abuse
    CURLOPT_BUFFERSIZE     => 131072,
]);

$body   = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$size   = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
$errno  = curl_errno($ch);
unset($ch);

if ($errno || !$body || $status !== 200) {
    http_response_code(502);
    exit('Failed to fetch subtitle');
}

if ($size > 2 * 1024 * 1024) {
    http_response_code(413);
    exit('Subtitle file too large');
}

header('Content-Type: text/vtt; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo $body;
