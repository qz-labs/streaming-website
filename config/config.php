<?php
declare(strict_types=1);

// ── Load environment variables from .env file ─────────────────────────────────
function loadEnv($filePath = __DIR__ . '/../.env') {
    if (!file_exists($filePath)) {
        return;
    }
    
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        
        // Parse KEY=VALUE
        if (strpos($line, '=') !== false) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Remove quotes if present
            if ((strpos($value, '"') === 0 && strrpos($value, '"') === strlen($value) - 1) ||
                (strpos($value, "'") === 0 && strrpos($value, "'") === strlen($value) - 1)) {
                $value = substr($value, 1, -1);
            }
            
            $_ENV[$key] = $value;
        }
    }
}

loadEnv();

// Helper function to get environment variables with fallback
function env($key, $default = null) {
    return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
}

// ── TMDB API ──────────────────────────────────────────────────────────────────
define('TMDB_KEY',          env('TMDB_KEY', ''));   // From .env file
define('TMDB_BASE',         'https://api.themoviedb.org/3');
define('TMDB_IMAGE_BASE',   'https://image.tmdb.org/t/p');

// ── Provider 1: vidsrc.me family (source: vidsrc.domains + official API docs) ─
// URL format:  /embed/movie/{id}  and  /embed/tv/{id}/{season}-{episode}  (dash)
// Supports ds_lang for audio/subtitle language selection.
define('VIDSRC_DOMAINS', [
    'vidsrc-embed.ru',
    'vidsrc-embed.su',
    'vidsrcme.su',
    'vsrc.su',
    'vidsrcme.ru',
    'vidsrc-me.su',
    'vidsrc-me.ru',
]);

// ── Provider 2: independent providers (different content index, slash format) ─
// URL format:  /embed/movie/{id}  and  /embed/tv/{id}/{season}/{episode}  (slashes)
define('VIDSRC_EXTRA_PROVIDERS', [
    ['host' => 'vidsrc.cc',  'prefix' => '/v2'],   // vidsrc.cc uses /v2/embed/...
    ['host' => 'vidsrc.mov', 'prefix' => ''],       // vidsrc.mov uses /embed/...
    ['host' => 'vidsrc.icu', 'prefix' => ''],       // vidsrc.icu mirror
]);

// Kept for backwards-compat in helpers.
define('VIDSRC_BASE', 'https://' . VIDSRC_DOMAINS[0] . '/embed');

// ── Site ──────────────────────────────────────────────────────────────────────
define('SITE_NAME',         env('SITE_NAME', 'StreamFlix'));
define('BASE_URL',          env('BASE_URL', '/StreamingWebsite/public'));  // no trailing slash

// ── HiAnime API + m3u8 proxy (optional) ──────────────────────────────────────
define('CONSUMET_URL',      rtrim(env('CONSUMET_URL',    ''), '/'));  // empty = disabled
define('M3U8_PROXY_URL',    rtrim(env('M3U8_PROXY_URL', ''), '/'));  // empty = no proxy

// ── File cache ────────────────────────────────────────────────────────────────
define('CACHE_DIR',         __DIR__ . '/../cache');
define('CACHE_TTL',         (int) env('CACHE_TTL', '3600'));   // seconds (1 hour)

if (!is_dir(CACHE_DIR)) {
    mkdir(CACHE_DIR, 0755, true);
}
