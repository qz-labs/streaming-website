<?php
declare(strict_types=1);

/**
 * stream.php — JSON endpoint for anime HLS stream resolution.
 *
 * Each MAL ID is already one season on HiAnime, so no season/offset logic needed.
 *
 * GET params:
 *   mal_id   (int)      MAL anime ID
 *   episode  (int)      Episode number within this MAL entry (always relative)
 *   category (sub|dub)  Audio track, default: sub
 *
 * Responses:
 *   200 { m3u8, headers, subtitles, category }   — success
 *   200 { error, category }                       — stream not found
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/JikanApi.php';
require_once __DIR__ . '/../src/ConsumetApi.php';

// ── Validate input ────────────────────────────────────────────────────────────
$malId    = intval($_GET['mal_id']  ?? 0);
$episode  = intval($_GET['episode'] ?? 1);
$category = ($_GET['category'] ?? 'sub') === 'dub' ? 'dub' : 'sub';

if ($malId <= 0 || $episode <= 0) {
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

if (CONSUMET_URL === '') {
    echo json_encode(['error' => 'Consumet API is not configured']);
    exit;
}

// ── Fetch anime title + year from Jikan (always cached) ───────────────────────
$jikan = new JikanApi();
$anime = $jikan->animeDetails($malId);

if (empty($anime)) {
    echo json_encode(['error' => 'Anime not found in Jikan']);
    exit;
}

$titleEnglish  = trim($anime['title_english'] ?? '');
$titleJapanese = trim($anime['title']         ?? '');

if (!$titleEnglish && !$titleJapanese) {
    echo json_encode(['error' => 'Anime has no usable title']);
    exit;
}

$primaryTitle   = $titleEnglish  ?: $titleJapanese;
$secondaryTitle = $titleJapanese ?: $titleEnglish;

// Airing year for disambiguation (e.g. HxH 1999 vs 2011)
$airedFrom = $anime['aired']['from'] ?? '';
$year      = $airedFrom ? (int)substr($airedFrom, 0, 4) : 0;

// ── Resolve stream ────────────────────────────────────────────────────────────
// season=1 always: each MAL entry maps to exactly one HiAnime show entry.
// No episode offset, no cross-season arithmetic.
$consumet = new ConsumetApi();
$stream   = $consumet->resolveStream($primaryTitle, $secondaryTitle, $episode, $category, $year);

if (!$stream) {
    echo json_encode(['error' => 'Stream not found', 'category' => $category]);
    exit;
}

// ── Proxy m3u8 and subtitles ──────────────────────────────────────────────────
$m3u8Url   = $stream['m3u8'];
$subtitles = $stream['subtitles'];

if (M3U8_PROXY_URL !== '') {
    $encodedHeaders = urlencode(json_encode($stream['headers']));
    $m3u8Url = M3U8_PROXY_URL . '/m3u8-proxy?url=' . urlencode($m3u8Url) . '&headers=' . $encodedHeaders;

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
