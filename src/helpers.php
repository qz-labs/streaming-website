<?php
declare(strict_types=1);

/**
 * HTML-escape a string for safe output.
 */
function e(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Full TMDB poster URL, or an inline SVG placeholder when path is absent.
 */
function imgUrl(?string $path, string $size = 'w500'): string
{
    if (!$path) {
        // Dark-gray placeholder with a film-frame icon
        return "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='300' height='450' viewBox='0 0 300 450'%3E%3Crect width='300' height='450' fill='%231f1f1f'/%3E%3Crect x='40' y='60' width='220' height='330' rx='4' fill='%23333'/%3E%3Crect x='20' y='80' width='20' height='40' rx='2' fill='%23444'/%3E%3Crect x='260' y='80' width='20' height='40' rx='2' fill='%23444'/%3E%3Crect x='20' y='160' width='20' height='40' rx='2' fill='%23444'/%3E%3Crect x='260' y='160' width='20' height='40' rx='2' fill='%23444'/%3E%3Crect x='20' y='240' width='20' height='40' rx='2' fill='%23444'/%3E%3Crect x='260' y='240' width='20' height='40' rx='2' fill='%23444'/%3E%3Crect x='20' y='320' width='20' height='40' rx='2' fill='%23444'/%3E%3Crect x='260' y='320' width='20' height='40' rx='2' fill='%23444'/%3E%3Ctext x='150' y='235' font-family='Arial' font-size='14' fill='%23666' text-anchor='middle'%3ENo Image%3C/text%3E%3C/svg%3E";
    }
    return TMDB_IMAGE_BASE . '/' . $size . $path;
}

/**
 * Full TMDB backdrop URL (wide format), or empty string.
 */
function backdropUrl(?string $path): string
{
    if (!$path) return '';
    return TMDB_IMAGE_BASE . '/w1280' . $path;
}

/**
 * Still image URL for TV episode stills.
 */
function stillUrl(?string $path): string
{
    if (!$path) {
        return "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='400' height='225' viewBox='0 0 400 225'%3E%3Crect width='400' height='225' fill='%231f1f1f'/%3E%3Ctext x='200' y='118' font-family='Arial' font-size='14' fill='%23666' text-anchor='middle'%3ENo Preview%3C/text%3E%3C/svg%3E";
    }
    return TMDB_IMAGE_BASE . '/w300' . $path;
}

/**
 * URL to the movie watch page.
 */
function movieWatchUrl(int $id): string
{
    return BASE_URL . '/watch.php?type=movie&id=' . $id;
}

/**
 * URL to the TV episode watch page.
 */
function tvWatchUrl(int $id, int $season, int $episode): string
{
    return BASE_URL . '/watch.php?type=tv&id=' . $id . '&s=' . $season . '&e=' . $episode;
}

/**
 * vidsrc embed URL for a movie.
 * Appends ds_lang when provided (ISO 639-1, e.g. "en", "ja").
 */
function vidsrcMovieEmbed(int $id, string $domain = '', string $dsLang = ''): string
{
    $base = $domain ? 'https://' . $domain . '/embed' : VIDSRC_BASE;
    $url  = $base . '/movie/' . $id;
    if ($dsLang !== '') $url .= '?ds_lang=' . urlencode($dsLang);
    return $url;
}

/**
 * vidsrc embed URL for a TV episode.
 * Correct format: /embed/tv/{tmdb_id}/{season}-{episode}  (dash, not slash)
 */
function vidsrcTvEmbed(int $id, int $season, int $episode, string $domain = '', string $dsLang = ''): string
{
    $base = $domain ? 'https://' . $domain . '/embed' : VIDSRC_BASE;
    $url  = $base . '/tv/' . $id . '/' . $season . '-' . $episode;
    if ($dsLang !== '') $url .= '?ds_lang=' . urlencode($dsLang);
    return $url;
}

/**
 * Build embed URL for a single extra provider (vidsrc.cc / vidsrc.mov style).
 * These use slashes for TV: /embed/tv/{id}/{season}/{episode}  (NOT the dash format).
 * They do NOT support ds_lang.
 *
 * $provider = ['host' => 'vidsrc.cc', 'prefix' => '/v2']
 */
function extraProviderUrl(array $provider, string $type, int $id, int $season = 1, int $episode = 1): string
{
    $base = 'https://' . $provider['host'] . $provider['prefix'] . '/embed';
    if ($type === 'movie') {
        return $base . '/movie/' . $id;
    }
    return $base . '/tv/' . $id . '/' . $season . '/' . $episode;
}

/**
 * Build ALL embed URLs across every provider and domain, for both language modes.
 *
 * Returns:
 *   'sub' => [ ['url'=>..., 'label'=>..., 'ping'=>...], ... ]
 *   'dub' => [ ['url'=>..., 'label'=>..., 'ping'=>...], ... ]
 *
 * Provider 1 (vidsrc.me family): supports ds_lang for sub/dub.
 * Provider 2 (vidsrc.cc, vidsrc.mov): no lang param, same URL for both modes.
 */
function vidsrcAllUrls(string $type, int $id, int $season = 1, int $episode = 1, string $origLang = ''): array
{
    $sub = [];
    $dub = [];

    $subLang = ($origLang !== '' && $origLang !== 'en') ? $origLang : 'en';

    // ── Provider 1: vidsrc.me family (dash format, ds_lang support) ────────────
    foreach (VIDSRC_DOMAINS as $i => $domain) {
        $label = 'S' . ($i + 1);
        $sub[] = [
            'url'   => ($type === 'movie')
                        ? vidsrcMovieEmbed($id, $domain, $subLang)
                        : vidsrcTvEmbed($id, $season, $episode, $domain, $subLang),
            'label' => $label,
            'ping'  => 'https://' . $domain . '/embed/movie/550',
        ];
        $dub[] = [
            'url'   => ($type === 'movie')
                        ? vidsrcMovieEmbed($id, $domain, 'en')
                        : vidsrcTvEmbed($id, $season, $episode, $domain, 'en'),
            'label' => $label,
            'ping'  => 'https://' . $domain . '/embed/movie/550',
        ];
    }

    // ── Provider 2: independent providers (slash format, no ds_lang) ───────────
    $baseCount = count(VIDSRC_DOMAINS);
    foreach (VIDSRC_EXTRA_PROVIDERS as $j => $provider) {
        $label = 'S' . ($baseCount + $j + 1);
        $url   = extraProviderUrl($provider, $type, $id, $season, $episode);
        $ping  = 'https://' . $provider['host'] . $provider['prefix'] . '/embed/movie/550';
        // Extra providers have no lang control — same URL for both modes
        $sub[] = ['url' => $url, 'label' => $label, 'ping' => $ping];
        $dub[] = ['url' => $url, 'label' => $label, 'ping' => $ping];
    }

    return ['sub' => $sub, 'dub' => $dub];
}

/**
 * Convert minutes to "2h 15m" format.
 */
function formatRuntime(int $minutes): string
{
    if ($minutes <= 0) return '';
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    if ($h > 0 && $m > 0) return $h . 'h ' . $m . 'm';
    if ($h > 0) return $h . 'h';
    return $m . 'm';
}

/**
 * Extract year from a date string like "2023-05-12".
 */
function yearFromDate(?string $date): string
{
    if (!$date || strlen($date) < 4) return '';
    return substr($date, 0, 4);
}

/**
 * Format vote average to one decimal.
 */
function ratingBadge(float $vote): string
{
    return number_format($vote, 1);
}

/**
 * Truncate a string to a maximum length, appending "…" if cut.
 */
function truncate(string $text, int $max = 150): string
{
    if (mb_strlen($text) <= $max) return $text;
    return mb_substr($text, 0, $max) . '…';
}

/**
 * URL to the anime detail page (Jikan-powered).
 */
function animeDetailUrl(int $malId): string
{
    return BASE_URL . '/anime-detail.php?mal_id=' . $malId;
}

/**
 * URL to the anime HLS watch page (Consumet-powered).
 */
function animeWatchUrl(int $malId, int $episode = 1): string
{
    return BASE_URL . '/anime-watch.php?mal_id=' . $malId . '&episode=' . $episode;
}

/**
 * Jikan image URL — returns the largest available image or a placeholder.
 */
function jikanImg(array $images, string $size = 'large'): string
{
    // Prefer WebP, fall back to JPG
    $webp = $images['webp'] ?? [];
    $jpg  = $images['jpg']  ?? [];

    $url = $webp[$size . '_image_url']
        ?? $webp['image_url']
        ?? $jpg[$size . '_image_url']
        ?? $jpg['image_url']
        ?? '';

    return $url ?: imgUrl(null); // SVG placeholder if nothing
}

/**
 * Human-readable label for a Jikan anime type.
 * TV, Movie, OVA, ONA, Special, Music
 */
function animeTypeBadge(string $type): string
{
    return match(strtoupper($type)) {
        'OVA'     => 'OVA',
        'ONA'     => 'ONA',
        'SPECIAL' => 'Special',
        'MOVIE'   => 'Movie',
        'MUSIC'   => 'Music',
        default   => '',
    };
}

/**
 * Render a horizontal content row of Jikan anime items.
 * Links go to anime-detail.php.
 */
function renderAnimeRow(string $title, array $items): void
{
    if (empty($items)) return;
    echo '<section class="row">';
    echo '<h2 class="row__title">' . e($title) . '</h2>';
    echo '<div class="row__track">';
    foreach ($items as $item) {
        $malId  = (int)($item['mal_id'] ?? 0);
        $name   = $item['title_english'] ?: ($item['title'] ?? 'Unknown');
        $year   = yearFromDate($item['aired']['from'] ?? null);
        $rating = isset($item['score']) && $item['score'] > 0 ? ratingBadge((float)$item['score']) : '';
        $poster = jikanImg($item['images'] ?? []);
        $badge  = animeTypeBadge($item['type'] ?? '');
        $link   = animeDetailUrl($malId);
        echo '<a class="card" href="' . e($link) . '" title="' . e($name) . '">';
        echo '<img src="' . e($poster) . '" alt="' . e($name) . '" loading="lazy">';
        echo '<div class="card__overlay">';
        if ($rating) echo '<span class="card__rating">' . e($rating) . '</span>';
        if ($badge)  echo '<span class="card__type-badge">' . e($badge) . '</span>';
        echo '<p class="card__title">' . e($name) . '</p>';
        if ($year)   echo '<p class="card__year">' . e($year) . '</p>';
        echo '</div>';
        echo '</a>';
    }
    echo '</div>';
    echo '</section>';
}

/**
 * Render a horizontal content row.
 * $type = 'movie' | 'tv'
 */
function renderRow(string $title, array $items, string $type): void
{
    if (empty($items)) return;
    $detailBase = ($type === 'movie') ? BASE_URL . '/movie.php?id=' : BASE_URL . '/tv.php?id=';
    echo '<section class="row">';
    echo '<h2 class="row__title">' . e($title) . '</h2>';
    echo '<div class="row__track">';
    foreach ($items as $item) {
        $id    = (int)($item['id'] ?? 0);
        $name  = e($item['title'] ?? $item['name'] ?? 'Unknown');
        $year  = e(yearFromDate($item['release_date'] ?? $item['first_air_date'] ?? null));
        $rating = isset($item['vote_average']) ? ratingBadge((float)$item['vote_average']) : '';
        $poster = imgUrl($item['poster_path'] ?? null);
        $link   = $detailBase . $id;
        echo '<a class="card" href="' . e($link) . '" title="' . $name . '">';
        echo '<img src="' . e($poster) . '" alt="' . $name . '" loading="lazy">';
        echo '<div class="card__overlay">';
        if ($rating) {
            echo '<span class="card__rating">' . e($rating) . '</span>';
        }
        echo '<p class="card__title">' . $name . '</p>';
        if ($year) {
            echo '<p class="card__year">' . $year . '</p>';
        }
        echo '</div>';
        echo '</a>';
    }
    echo '</div>';
    echo '</section>';
}
