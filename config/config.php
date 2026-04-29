<?php
declare(strict_types=1);

function loadEnv(string $filePath = __DIR__ . '/../.env'): void
{
    if (!file_exists($filePath)) return;

    foreach (file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;

        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);

        if (strlen($value) >= 2 && in_array($value[0], ['"', "'"], true) && $value[0] === $value[-1]) {
            $value = substr($value, 1, -1);
        }

        $_ENV[$key] = $value;
    }
}

loadEnv();

function env(string $key, mixed $default = null): mixed
{
    return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
}

// ── TMDB API ──────────────────────────────────────────────────────────────────
define('TMDB_KEY',          env('TMDB_KEY', ''));
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

// ── Provider 3: moviesapi.to — uses TMDB IDs, different URL scheme ────────────
// Movie: /movie/{tmdb_id}   TV: /tv/{tmdb_id}-{season}-{episode}
define('MOVIESAPI_HOST', 'moviesapi.to');

define('VIDSRC_BASE', 'https://' . VIDSRC_DOMAINS[0] . '/embed');

// ── Site ──────────────────────────────────────────────────────────────────────
define('SITE_NAME', env('SITE_NAME', 'StreamFlix'));

// BASE_URL: prefer explicit .env value; otherwise auto-detect from the request.
// Auto-detection: scheme + host + the path segment up to /public (handles any subfolder).
(function () {
    $fromEnv = env('BASE_URL', '');
    if ($fromEnv !== '') {
        define('BASE_URL', rtrim($fromEnv, '/'));
        return;
    }
    // Detect scheme
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Walk up SCRIPT_NAME to find the /public segment
    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    // Strip everything after /public (inclusive of the file name)
    $base   = preg_replace('#/public/.*$#', '/public', $script) ?: '/public';
    define('BASE_URL', $scheme . '://' . $host . $base);
})();

// ── Startup config validation ─────────────────────────────────────────────────
// Fail fast with a clear message rather than silent downstream errors.
if (env('TMDB_KEY', '') === '' && php_sapi_name() !== 'cli') {
    http_response_code(500);
    exit('Configuration error: TMDB_KEY is not set. Add it to your .env file.');
}

// ── HiAnime API + m3u8 proxy (optional) ──────────────────────────────────────
define('CONSUMET_URL',         rtrim(env('CONSUMET_URL',         ''), '/'));              // empty = disabled
define('M3U8_PROXY_URL',       rtrim(env('M3U8_PROXY_URL',       ''), '/'));              // empty = no proxy
define('VIDSRC_EXTRACTOR_URL', rtrim(env('VIDSRC_EXTRACTOR_URL', 'http://localhost:3031'), '/'));  // vidsrc M3U8 extractor (legacy)
define('CINEPRO_URL',          rtrim(env('CINEPRO_URL',          'http://localhost:3002'), '/'));  // CinePro multi-provider stream extractor

// ── cURL SSL ─────────────────────────────────────────────────────────────────
// Path to CA bundle for SSL verification. On XAMPP/Windows this must be set
// explicitly; on Linux/production the system bundle is used automatically.
define('CURL_CA_BUNDLE',    env('CURL_CA_BUNDLE', ''));

// ── File cache ────────────────────────────────────────────────────────────────
define('CACHE_DIR',         __DIR__ . '/../cache');
define('CACHE_TTL',         (int) env('CACHE_TTL', '3600'));   // seconds (1 hour)

// ── Asset cache-busting ───────────────────────────────────────────────────────
// One filesystem stat at config-load time instead of one per asset per page.
define('ASSET_VERSION', (string)(@filemtime(__DIR__ . '/../public/assets/css/style.css') ?: '1'));

if (!is_dir(CACHE_DIR)) {
    mkdir(CACHE_DIR, 0755, true);
}

// ── Auto-run DB migrations on every web request (IF NOT EXISTS = idempotent) ─
// Database::get() auto-creates the database if it doesn't exist yet, so this
// works on a fresh MySQL install with no manual setup required.
if (php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/../src/Database.php';
    require_once __DIR__ . '/../src/Migrations.php';
    try {
        Migrations::run();
    } catch (PDOException $e) {
        http_response_code(503);
        exit('Database unavailable: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES) .
             '<br>Make sure MySQL is running and the credentials in .env are correct.');
    }
}
