<?php
/**
 * subtitle.php — Same-origin proxy for external VTT subtitle files.
 *
 * Browsers block <track> elements loaded from a different port (cross-origin).
 * This script fetches the VTT on the server side and serves it from the same
 * origin as the page, eliminating the CORS restriction entirely.
 *
 * GET ?url={encoded_vtt_url}
 */
declare(strict_types=1);

$url = $_GET['url'] ?? '';
if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    exit('Invalid URL');
}

// Only allow fetching VTT/WebVTT files
$path = parse_url($url, PHP_URL_PATH) ?? '';
if (!str_ends_with(strtolower($path), '.vtt')) {
    http_response_code(400);
    exit('Only VTT files allowed');
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTPHEADER     => [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        'Referer: https://megacloud.club/',
    ],
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
]);

$body   = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$errno  = curl_errno($ch);

if ($errno || !$body || $status !== 200) {
    http_response_code(502);
    exit('Failed to fetch subtitle');
}

header('Content-Type: text/vtt; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo $body;
