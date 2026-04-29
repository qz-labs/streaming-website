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
 * Default size w342 (342 px) is sufficient for card thumbnails at 2× retina.
 */
function imgUrl(?string $path, string $size = 'w342'): string
{
    if (!$path) {
        // Dark-gray placeholder with a film-frame icon
        return "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='300' height='450' viewBox='0 0 300 450'%3E%3Crect width='300' height='450' fill='%231f1f1f'/%3E%3Crect x='40' y='60' width='220' height='330' rx='4' fill='%23333'/%3E%3Crect x='20' y='80' width='20' height='40' rx='2' fill='%23444'/%3E%3Crect x='260' y='80' width='20' height='40' rx='2' fill='%23444'/%3E%3Crect x='20' y='160' width='20' height='40' rx='2' fill='%23444'/%3E%3Crect x='260' y='160' width='20' height='40' rx='2' fill='%23444'/%3E%3Crect x='20' y='240' width='20' height='40' rx='2' fill='%23444'/%3E%3Crect x='260' y='240' width='20' height='40' rx='2' fill='%23444'/%3E%3Crect x='20' y='320' width='20' height='40' rx='2' fill='%23444'/%3E%3Crect x='260' y='320' width='20' height='40' rx='2' fill='%23444'/%3E%3Ctext x='150' y='235' font-family='Arial' font-size='14' fill='%23666' text-anchor='middle'%3ENo Image%3C/text%3E%3C/svg%3E";
    }
    return TMDB_IMAGE_BASE . '/' . $size . $path;
}

/**
 * Full TMDB backdrop URL (wide format), or empty string.
 * $size: 'w780' for mobile/detail pages, 'w1280' for desktop hero (default).
 */
function backdropUrl(?string $path, string $size = 'w1280'): string
{
    if (!$path) return '';
    return TMDB_IMAGE_BASE . '/' . $size . $path;
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
function movieWatchUrl(int $id, int $t = 0): string
{
    $url = BASE_URL . '/watch.php?type=movie&id=' . $id;
    if ($t > 0) $url .= '&t=' . $t;
    return $url;
}

/**
 * URL to the TV episode watch page.
 */
function tvWatchUrl(int $id, int $season, int $episode, int $t = 0): string
{
    $url = BASE_URL . '/watch.php?type=tv&id=' . $id . '&s=' . $season . '&e=' . $episode;
    if ($t > 0) $url .= '&t=' . $t;
    return $url;
}

/**
 * Return true only when $domain is in the configured VIDSRC_DOMAINS list.
 * Prevents arbitrary domain injection into embed URLs.
 */
function isAllowedVidsrcDomain(string $domain): bool
{
    return in_array($domain, VIDSRC_DOMAINS, true);
}

/**
 * vidsrc embed URL for a movie.
 * Appends ds_lang when provided (ISO 639-1, e.g. "en", "ja").
 */
function vidsrcMovieEmbed(int $id, string $domain = '', string $dsLang = ''): string
{
    if ($domain !== '' && !isAllowedVidsrcDomain($domain)) {
        $domain = '';  // Fall back to default rather than injecting arbitrary domain
    }
    $base   = $domain ? 'https://' . $domain . '/embed' : VIDSRC_BASE;
    $url    = $base . '/movie/' . $id;
    $params = [];
    if ($dsLang !== '') $params[] = 'ds_lang=' . urlencode($dsLang);
    $params[] = 'autoplay=1';
    return $url . '?' . implode('&', $params);
}

/**
 * vidsrc embed URL for a TV episode.
 * Correct format: /embed/tv/{tmdb_id}/{season}-{episode}  (dash, not slash)
 */
function vidsrcTvEmbed(int $id, int $season, int $episode, string $domain = '', string $dsLang = ''): string
{
    if ($domain !== '' && !isAllowedVidsrcDomain($domain)) {
        $domain = '';  // Fall back to default rather than injecting arbitrary domain
    }
    $base   = $domain ? 'https://' . $domain . '/embed' : VIDSRC_BASE;
    $url    = $base . '/tv/' . $id . '/' . $season . '-' . $episode;
    $params = [];
    if ($dsLang !== '') $params[] = 'ds_lang=' . urlencode($dsLang);
    $params[] = 'autoplay=1';
    return $url . '?' . implode('&', $params);
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
        return $base . '/movie/' . $id . '?autoplay=1';
    }
    return $base . '/tv/' . $id . '/' . $season . '/' . $episode . '?autoplay=1';
}

/**
 * Build ALL embed URLs across every provider and domain, for both language modes.
 *
 * Returns:
 *   'sub' => [ ['url'=>..., 'label'=>..., 'ping'=>...], ... ]
 *   'dub' => [ ['url'=>..., 'label'=>..., 'ping'=>...], ... ]
 *
 * Order: moviesapi.to first, then vidsrc.me family, then extras.
 */
function vidsrcAllUrls(string $type, int $id, int $season = 1, int $episode = 1, string $origLang = ''): array
{
    $sub = [];
    $dub = [];

    $subLang = ($origLang !== '' && $origLang !== 'en') ? $origLang : 'en';

    // ── Provider 0: moviesapi.to (primary — different URL scheme) ───────────────
    $moviesApiUrl = ($type === 'movie')
        ? 'https://' . MOVIESAPI_HOST . '/movie/' . $id
        : 'https://' . MOVIESAPI_HOST . '/tv/' . $id . '-' . $season . '-' . $episode;
    $sub[] = ['url' => $moviesApiUrl, 'label' => 'M1', 'ping' => 'https://' . MOVIESAPI_HOST . '/movie/550'];
    $dub[] = ['url' => $moviesApiUrl, 'label' => 'M1', 'ping' => 'https://' . MOVIESAPI_HOST . '/movie/550'];

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
function animeWatchUrl(int $malId, int $episode = 1, int $season = 1, int $t = 0): string
{
    $url = BASE_URL . '/anime-watch.php?mal_id=' . $malId . '&episode=' . $episode . '&season=' . $season;
    if ($t > 0) $url .= '&t=' . $t;
    return $url;
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
 * When $required is true and $items is empty, renders 8 skeleton placeholder
 * cards so the row title is always visible (API temporarily unavailable).
 */
function renderAnimeRow(string $title, array $items, bool $required = false): void
{
    if (empty($items) && !$required) return;

    echo '<section class="row">';
    echo '<h2 class="row__title">' . e($title) . '</h2>';
    echo '<div class="row__track">';

    if (empty($items)) {
        for ($i = 0; $i < 8; $i++) {
            echo '<div class="card card--skeleton" aria-hidden="true"></div>';
        }
        echo '</div></section>';
        return;
    }

    foreach ($items as $item) {
        $malId  = (int)($item['mal_id'] ?? 0);
        $name   = $item['title_english'] ?: ($item['title'] ?? 'Unknown');
        $year   = yearFromDate($item['aired']['from'] ?? null);
        $rating = isset($item['score']) && $item['score'] > 0 ? ratingBadge((float)$item['score']) : '';
        $poster = jikanImg($item['images'] ?? []);
        $badge  = animeTypeBadge($item['type'] ?? '');
        $link      = animeDetailUrl($malId);
        $genreIds  = implode(',', array_column($item['genres'] ?? [], 'mal_id'));
        echo '<a class="card" href="' . e($link) . '" title="' . e($name) . '" data-genre-ids="' . e($genreIds) . '">';
        echo '<img src="' . e($poster) . '" alt="' . e($name) . '" loading="lazy" referrerpolicy="no-referrer">';
        echo '<div class="card__play">&#9654;</div>';
        echo '<div class="card__overlay">';
        if ($rating) echo '<span class="card__rating">&#9733; ' . e($rating) . '</span>';
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
        $id       = (int)($item['id'] ?? 0);
        $name     = $item['title'] ?? $item['name'] ?? 'Unknown';
        $year     = yearFromDate($item['release_date'] ?? $item['first_air_date'] ?? null);
        $rating   = isset($item['vote_average']) ? ratingBadge((float)$item['vote_average']) : '';
        $poster   = imgUrl($item['poster_path'] ?? null);
        $link     = $detailBase . $id;
        $genreIds = implode(',', $item['genre_ids'] ?? []);
        echo '<a class="card" href="' . e($link) . '" title="' . e($name) . '" data-genre-ids="' . e($genreIds) . '">';
        echo '<img src="' . e($poster) . '" alt="' . e($name) . '" loading="lazy" referrerpolicy="no-referrer">';
        echo '<div class="card__play">&#9654;</div>';
        echo '<div class="card__overlay">';
        if ($rating) echo '<span class="card__rating">&#9733; ' . e($rating) . '</span>';
        echo '<p class="card__title">' . e($name) . '</p>';
        if ($year) echo '<p class="card__year">' . e($year) . '</p>';
        echo '</div>';
        echo '</a>';
    }
    echo '</div>';
    echo '</section>';
}

/**
 * Render the "Continue Watching" horizontal row.
 * Rows come from the watch_progress table: content_type, content_id,
 * content_title, poster_path, season, episode, progress_seconds, duration_seconds.
 *
 * poster_path for movie/tv is the raw TMDB path (e.g. "/abc.jpg").
 * poster_path for anime is the full Jikan image URL.
 */
function renderContinueWatchingRow(array $items): void
{
    echo '<section class="row">';
    echo '<h2 class="row__title">Continue Watching</h2>';

    if (empty($items)) {
        echo '<div class="row__empty"><p>Nothing here yet &mdash; start watching something and it will appear here.</p></div>';
        echo '</section>';
        return;
    }

    echo '<div class="row__track">';

    foreach ($items as $item) {
        $type     = $item['content_type'];
        $id       = (int)$item['content_id'];
        $title    = $item['content_title'] ?: 'Unknown';
        $poster   = $item['poster_path'] ?? '';
        $season   = (int)$item['season'];
        $episode  = (int)$item['episode'];
        $progress = (int)$item['progress_seconds'];
        $duration = (int)$item['duration_seconds'];

        if ($type === 'anime') {
            $watchLink  = animeWatchUrl($id, max(1, $episode), $season, $progress) . '&from=' . urlencode('/anime.php');
            $detailLink = animeDetailUrl($id);
        } elseif ($type === 'tv') {
            $watchLink  = tvWatchUrl($id, max(1, $season), max(1, $episode), $progress) . '&from=' . urlencode('/');
            $detailLink = BASE_URL . '/tv.php?id=' . $id;
        } else {
            $watchLink  = movieWatchUrl($id, $progress) . '&from=' . urlencode('/');
            $detailLink = BASE_URL . '/movie.php?id=' . $id;
        }

        // Progress bar percentage (0–100); 0 when duration unknown
        $pct = ($duration > 0) ? min(100, (int)round($progress / $duration * 100)) : 0;

        $subLabel = '';
        if ($type === 'tv'    && $season > 0 && $episode > 0) $subLabel = 'S' . $season . ' E' . $episode;
        if ($type === 'anime' && $episode > 0)                 $subLabel = 'Ep ' . $episode;

        echo '<div class="card card--progress" data-progress-type="' . e($type) . '" data-progress-id="' . $id . '">';

        // Poster image — sits in normal flow, fills the card
        if ($type === 'anime' && $poster !== '') {
            echo '<img src="' . e($poster) . '" alt="' . e($title) . '" loading="lazy" referrerpolicy="no-referrer">';
        } else {
            echo '<img src="' . e(imgUrl($poster !== '' ? $poster : null)) . '" alt="' . e($title) . '" loading="lazy">';
        }

        // Stretched invisible link — covers entire card, handles play click
        echo '<a class="card__watch-link" href="' . e($watchLink) . '" aria-label="Play ' . e($title) . '"></a>';

        echo '<div class="card__play">&#9654;</div>';
        echo '<div class="card__progress-bar"><div class="card__progress-fill" style="width:' . $pct . '%"></div></div>';
        echo '<div class="card__overlay">';
        echo '<p class="card__title">' . e($title) . '</p>';
        if ($subLabel) echo '<p class="card__year">' . e($subLabel) . '</p>';
        echo '</div>';

        // Details button — sits above the stretched link
        echo '<a class="card__details-btn" href="' . e($detailLink) . '">&#9432;</a>';

        // Remove-from-watching button
        echo '<button class="card__remove-btn" title="Remove from Continue Watching" aria-label="Remove ' . e($title) . ' from Continue Watching">&#10005;</button>';

        echo '</div>';
    }

    echo '</div>';
    echo '</section>';
}

/**
 * Render a "My Favorites" horizontal row.
 * Handles all 3 content types (movie, tv, anime) since favorites
 * from different sources are stored in the same table.
 *
 * poster_path for movie/tv is the raw TMDB path; for anime it is the full Jikan URL.
 */
function renderFavoritesRow(string $title, array $items): void
{
    echo '<section class="row">';
    echo '<h2 class="row__title">' . e($title) . '</h2>';

    if (empty($items)) {
        echo '<div class="row__empty"><p>No favorites yet &mdash; click the &#9825; on any title to save it here.</p></div>';
        echo '</section>';
        return;
    }

    echo '<div class="row__track">';

    foreach ($items as $item) {
        $type   = $item['content_type'];
        $id     = (int)$item['content_id'];
        $name   = $item['content_title'] ?: 'Unknown';
        $poster = $item['poster_path'] ?? '';

        if ($type === 'anime') {
            $link   = animeDetailUrl($id);
            $imgSrc = $poster !== '' ? e($poster) : e(imgUrl(null));
            $refpol = ' referrerpolicy="no-referrer"';
        } elseif ($type === 'tv') {
            $link   = BASE_URL . '/tv.php?id=' . $id;
            $imgSrc = e(imgUrl($poster !== '' ? $poster : null));
            $refpol = '';
        } else {
            $link   = BASE_URL . '/movie.php?id=' . $id;
            $imgSrc = e(imgUrl($poster !== '' ? $poster : null));
            $refpol = '';
        }

        $typeBadge = match($type) {
            'tv'    => 'TV',
            'anime' => 'Anime',
            default => 'Movie',
        };

        echo '<a class="card card--fav" href="' . e($link) . '" title="' . e($name) . '">';
        echo '<img src="' . $imgSrc . '" alt="' . e($name) . '" loading="lazy"' . $refpol . '>';
        echo '<div class="card__play">&#9654;</div>';
        echo '<span class="card__fav-heart" aria-hidden="true">&#9829;</span>';
        echo '<span class="card__type-badge">' . $typeBadge . '</span>';
        echo '<div class="card__overlay">';
        echo '<p class="card__title">' . e($name) . '</p>';
        echo '</div>';
        echo '</a>';
    }

    echo '</div>';
    echo '</section>';
}
