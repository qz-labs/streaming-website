<?php
declare(strict_types=1);

/**
 * stream.php — JSON endpoint for anime HLS stream resolution.
 *
 * GET params:
 *   mal_id   (int)           MAL anime ID
 *   episode  (int)           Episode number
 *   category (sub|dub)       Audio track, default: sub
 *
 * Responses:
 *   200 { m3u8, headers, subtitles, category }   — success
 *   200 { error, category }                       — stream not found
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');   // Never cache — stream URLs expire

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/JikanApi.php';
require_once __DIR__ . '/../src/ConsumetApi.php';

// ── Validate input ────────────────────────────────────────────────────────────
$malId    = intval($_GET['mal_id']   ?? 0);
$episode  = intval($_GET['episode']  ?? 1);
$category = ($_GET['category'] ?? 'sub') === 'dub' ? 'dub' : 'sub';

if ($malId <= 0 || $episode <= 0) {
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

if (CONSUMET_URL === '') {
    echo json_encode(['error' => 'Consumet API is not configured']);
    exit;
}

// ── Fetch anime title from Jikan (always cached) ──────────────────────────────
$jikan = new JikanApi();
$anime = $jikan->animeDetails($malId);

if (empty($anime)) {
    echo json_encode(['error' => 'Anime not found in Jikan']);
    exit;
}

$titleEnglish  = trim($anime['title_english'] ?? '');
$titleJapanese = trim($anime['title']         ?? '');

// Prefer English, but we need at least one title
if (!$titleEnglish && !$titleJapanese) {
    echo json_encode(['error' => 'Anime has no usable title']);
    exit;
}

// Use whichever title is available as the primary search term
$primaryTitle   = $titleEnglish  ?: $titleJapanese;
$secondaryTitle = $titleJapanese ?: $titleEnglish;

// ── Resolve stream via Consumet ───────────────────────────────────────────────
$consumet = new ConsumetApi();
$stream   = $consumet->resolveStream($primaryTitle, $secondaryTitle, $episode, $category);

if (!$stream) {
    echo json_encode(['error' => 'Stream not found', 'category' => $category]);
    exit;
}

// Route the m3u8 through the local proxy so the browser never needs to send
// a Referer header directly — the proxy injects it server-side on every request.
$m3u8Url   = $stream['m3u8'];
$subtitles = $stream['subtitles'];

if (M3U8_PROXY_URL !== '') {
    $encodedHeaders = urlencode(json_encode($stream['headers']));

    // Proxy the video manifest
    $m3u8Url = M3U8_PROXY_URL . '/m3u8-proxy?url=' . urlencode($m3u8Url) . '&headers=' . $encodedHeaders;

    // Proxy subtitle VTT files through same-origin PHP proxy — <track> elements
    // are blocked by browsers when loaded cross-port, so Node.js proxy can't be used.
    $subtitles = array_map(function (array $sub): array {
        $sub['url'] = BASE_URL . '/subtitle.php?url=' . urlencode($sub['url']);
        return $sub;
    }, $subtitles);
}

echo json_encode([
    'm3u8'      => $m3u8Url,
    'subtitles' => $subtitles,
    'category'  => $category,
]);
