<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Auth.php';
requireLogin();
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/TmdbApi.php';
require_once __DIR__ . '/../src/JikanApi.php';

$q            = trim($_GET['q'] ?? '');
$results      = [];
$animeResults = [];

if (mb_strlen($q) >= 2) {
    // ── Cache search results for 6 hours to save API quota ───────────────────
    $cacheKey  = 'search_' . md5(strtolower($q)) . '.json';
    $cacheFile = __DIR__ . '/../cache/' . $cacheKey;
    $cacheTtl  = 6 * 3600;
    $cached    = null;

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
        $cached = json_decode(file_get_contents($cacheFile), true);
    }

    if (is_array($cached)) {
        $results      = $cached['results']      ?? [];
        $animeResults = $cached['animeResults'] ?? [];
    } else {
        $api  = new TmdbApi();
        $raw  = $api->search($q);
        $results = array_values(array_filter(
            $raw,
            fn($r) => in_array($r['media_type'] ?? '', ['movie', 'tv'], true)
        ));

        $jikan        = new JikanApi();
        $animeResults = $jikan->searchAnime($q);

        file_put_contents($cacheFile, json_encode([
            'results'      => $results,
            'animeResults' => $animeResults,
        ]));
    }
}

$movieResults = array_values(array_filter($results, fn($r) => ($r['media_type'] ?? '') === 'movie'));
$tvResults    = array_values(array_filter($results, fn($r) => ($r['media_type'] ?? '') === 'tv'));
$totalCount   = count($results) + count($animeResults);

$activePage = '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>
    <?= $q ? 'Search: ' . e($q) . ' &ndash; ' : 'Search &ndash; ' ?><?= e(SITE_NAME) ?>
  </title>
  <?php require __DIR__ . '/partials/fonts.php'; ?>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>
<body>

<?php require __DIR__ . '/partials/nav.php'; ?>

<main class="search-page" style="padding-top: calc(var(--nav-height) + 2rem);">

  <div class="search-bar">
    <form action="<?= BASE_URL ?>/search.php" method="get">
      <input
        id="search-input"
        type="search"
        name="q"
        value="<?= e($q) ?>"
        placeholder="Search movies, TV shows and anime..."
        autocomplete="off"
        autofocus
      >
      <button type="submit">Search</button>
    </form>
  </div>

  <?php if ($q !== '' && $totalCount > 0): ?>
    <p class="search-count">
      <?= $totalCount ?> result<?= $totalCount !== 1 ? 's' : '' ?> for
      &ldquo;<span><?= e($q) ?></span>&rdquo;
    </p>

    <!-- Tabs -->
    <div class="search-tabs" role="tablist">
      <button class="search-tab active" data-tab="all" role="tab">
        All <span class="tab-count"><?= $totalCount ?></span>
      </button>
      <?php if (!empty($movieResults)): ?>
      <button class="search-tab" data-tab="movies" role="tab">
        Movies <span class="tab-count"><?= count($movieResults) ?></span>
      </button>
      <?php endif; ?>
      <?php if (!empty($tvResults)): ?>
      <button class="search-tab" data-tab="tv" role="tab">
        TV Shows <span class="tab-count"><?= count($tvResults) ?></span>
      </button>
      <?php endif; ?>
      <?php if (!empty($animeResults)): ?>
      <button class="search-tab" data-tab="anime" role="tab">
        Anime <span class="tab-count"><?= count($animeResults) ?></span>
      </button>
      <?php endif; ?>
    </div>

    <!-- Movies section -->
    <?php if (!empty($movieResults)): ?>
    <div class="search-section" data-section="movies">
      <p class="search-section-title">Movies</p>
      <div class="search-grid">
        <?php foreach ($movieResults as $item): ?>
          <?php
            $itemId  = (int)($item['id'] ?? 0);
            $name    = $item['title'] ?? 'Unknown';
            $year    = yearFromDate($item['release_date'] ?? null);
            $rating  = isset($item['vote_average']) ? ratingBadge((float)$item['vote_average']) : '';
            $poster  = imgUrl($item['poster_path'] ?? null);
            $link    = BASE_URL . '/movie.php?id=' . $itemId;
          ?>
          <a class="card" href="<?= e($link) ?>" title="<?= e($name) ?>">
            <img src="<?= e($poster) ?>" alt="<?= e($name) ?>" loading="lazy" referrerpolicy="no-referrer">
            <div class="card__play">&#9654;</div>
            <div class="card__overlay">
              <?php if ($rating): ?><span class="card__rating">&#9733; <?= e($rating) ?></span><?php endif; ?>
              <p class="card__title"><?= e($name) ?></p>
              <?php if ($year): ?><p class="card__year"><?= e($year) ?></p><?php endif; ?>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- TV section -->
    <?php if (!empty($tvResults)): ?>
    <div class="search-section" data-section="tv" <?= empty($movieResults) ? '' : 'style="margin-top:2rem"' ?>>
      <p class="search-section-title">TV Shows</p>
      <div class="search-grid">
        <?php foreach ($tvResults as $item): ?>
          <?php
            $itemId  = (int)($item['id'] ?? 0);
            $name    = $item['name'] ?? 'Unknown';
            $year    = yearFromDate($item['first_air_date'] ?? null);
            $rating  = isset($item['vote_average']) ? ratingBadge((float)$item['vote_average']) : '';
            $poster  = imgUrl($item['poster_path'] ?? null);
            $link    = BASE_URL . '/tv.php?id=' . $itemId;
          ?>
          <a class="card" href="<?= e($link) ?>" title="<?= e($name) ?>">
            <img src="<?= e($poster) ?>" alt="<?= e($name) ?>" loading="lazy" referrerpolicy="no-referrer">
            <div class="card__play">&#9654;</div>
            <div class="card__overlay">
              <?php if ($rating): ?><span class="card__rating">&#9733; <?= e($rating) ?></span><?php endif; ?>
              <p class="card__title"><?= e($name) ?></p>
              <?php if ($year): ?><p class="card__year"><?= e($year) ?></p><?php endif; ?>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Anime section — always routes to anime-detail.php → anime-watch.php (HLS/Consumet), vidsrc fallback -->
    <?php if (!empty($animeResults)): ?>
    <div class="search-section" data-section="anime" <?= (empty($movieResults) && empty($tvResults)) ? '' : 'style="margin-top:2rem"' ?>>
      <p class="search-section-title">Anime <span style="color:var(--text-dim);font-weight:400;text-transform:none;letter-spacing:0;">via MyAnimeList</span></p>
      <div class="search-grid">
        <?php foreach ($animeResults as $item): ?>
          <?php
            $malId  = (int)($item['mal_id'] ?? 0);
            $name   = $item['title_english'] ?: ($item['title'] ?? 'Unknown');
            $year   = yearFromDate($item['aired']['from'] ?? null);
            $rating = isset($item['score']) && $item['score'] > 0 ? ratingBadge((float)$item['score']) : '';
            $poster = jikanImg($item['images'] ?? []);
            $badge  = animeTypeBadge($item['type'] ?? '');
            // Always route anime through the anime API player (HLS first, vidsrc fallback)
            $link   = animeDetailUrl($malId);
          ?>
          <a class="card" href="<?= e($link) ?>" title="<?= e($name) ?>">
            <img src="<?= e($poster) ?>" alt="<?= e($name) ?>" loading="lazy" referrerpolicy="no-referrer">
            <div class="card__play">&#9654;</div>
            <div class="card__overlay">
              <?php if ($rating): ?><span class="card__rating">&#9733; <?= e($rating) ?></span><?php endif; ?>
              <?php if ($badge):  ?><span class="card__type-badge"><?= e($badge) ?></span><?php endif; ?>
              <p class="card__title"><?= e($name) ?></p>
              <?php if ($year): ?><p class="card__year"><?= e($year) ?></p><?php endif; ?>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

  <?php elseif ($q !== ''): ?>
    <div class="empty-state">
      <h2>No results found</h2>
      <p>Try different keywords or check your spelling.</p>
    </div>
  <?php else: ?>
    <div class="empty-state">
      <h2>Start searching</h2>
      <p>Enter a movie, TV show, or anime title to get started.</p>
    </div>
  <?php endif; ?>

</main>

<?php require __DIR__ . '/partials/footer.php'; ?>

<script>const BASE_URL = '<?= BASE_URL ?>';</script>
<script src="<?= BASE_URL ?>/assets/js/main.js?v=<?= filemtime(__DIR__ . '/assets/js/main.js') ?>"></script>
</body>
</html>
