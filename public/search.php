<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/TmdbApi.php';
require_once __DIR__ . '/../src/JikanApi.php';

$q            = trim($_GET['q'] ?? '');
$results      = [];   // TMDB results
$animeResults = [];   // Jikan results

if (mb_strlen($q) >= 2) {
    $api  = new TmdbApi();
    $raw  = $api->search($q);
    $results = array_values(array_filter(
        $raw,
        fn($r) => in_array($r['media_type'] ?? '', ['movie', 'tv'], true)
    ));

    // Also search Jikan for anime-specific results
    $jikan        = new JikanApi();
    $animeResults = $jikan->searchAnime($q);
}

$activePage = '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>
    <?= $q ? 'Search: ' . e($q) . ' &ndash; ' : 'Search &ndash; ' ?><?= e(SITE_NAME) ?>
  </title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
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
        placeholder="Search movies and TV shows..."
        autocomplete="off"
        autofocus
      >
      <button type="submit">Search</button>
    </form>
  </div>

  <?php if ($q !== ''): ?>
    <p class="search-count">
      <?= count($results) ?> result<?= count($results) !== 1 ? 's' : '' ?> for
      &ldquo;<span><?= e($q) ?></span>&rdquo;
    </p>
  <?php endif; ?>

  <?php if (!empty($results)): ?>
    <div class="search-grid">
      <?php foreach ($results as $item): ?>
        <?php
          $type    = $item['media_type'] ?? 'movie';
          $itemId  = (int)($item['id'] ?? 0);
          $name    = $item['title'] ?? $item['name'] ?? 'Unknown';
          $year    = yearFromDate($item['release_date'] ?? $item['first_air_date'] ?? null);
          $rating  = isset($item['vote_average']) ? ratingBadge((float)$item['vote_average']) : '';
          $poster  = imgUrl($item['poster_path'] ?? null);
          $link    = ($type === 'movie')
                     ? BASE_URL . '/movie.php?id=' . $itemId
                     : BASE_URL . '/tv.php?id=' . $itemId;
        ?>
        <a class="card" href="<?= e($link) ?>" title="<?= e($name) ?>">
          <img src="<?= e($poster) ?>" alt="<?= e($name) ?>" loading="lazy">
          <div class="card__overlay">
            <?php if ($rating): ?>
              <span class="card__rating"><?= e($rating) ?></span>
            <?php endif; ?>
            <p class="card__title"><?= e($name) ?></p>
            <?php if ($year): ?>
              <p class="card__year"><?= e($year) ?></p>
            <?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>
    </div>

  <?php elseif ($q !== ''): ?>
    <div class="empty-state">
      <h2>No results found</h2>
      <p>Try different keywords or check your spelling.</p>
    </div>
  <?php else: ?>
    <div class="empty-state">
      <h2>Start searching</h2>
      <p>Enter a movie or TV show title to get started.</p>
    </div>
  <?php endif; ?>

  <!-- Anime results from Jikan -->
  <?php if (!empty($animeResults)): ?>
    <h2 style="font-size:1.05rem;font-weight:700;margin:2rem 0 0.75rem;">
      Anime Results <span style="color:var(--text-muted);font-size:.85rem;">(via MyAnimeList)</span>
    </h2>
    <div class="search-grid">
      <?php foreach ($animeResults as $item): ?>
        <?php
          $malId  = (int)($item['mal_id'] ?? 0);
          $name   = $item['title_english'] ?: ($item['title'] ?? 'Unknown');
          $year   = yearFromDate($item['aired']['from'] ?? null);
          $rating = isset($item['score']) && $item['score'] > 0 ? ratingBadge((float)$item['score']) : '';
          $poster = jikanImg($item['images'] ?? []);
          $badge  = animeTypeBadge($item['type'] ?? '');
          $link   = animeDetailUrl($malId);
        ?>
        <a class="card" href="<?= e($link) ?>" title="<?= e($name) ?>">
          <img src="<?= e($poster) ?>" alt="<?= e($name) ?>" loading="lazy">
          <div class="card__overlay">
            <?php if ($rating): ?><span class="card__rating"><?= e($rating) ?></span><?php endif; ?>
            <?php if ($badge):  ?><span class="card__type-badge"><?= e($badge) ?></span><?php endif; ?>
            <p class="card__title"><?= e($name) ?></p>
            <?php if ($year): ?><p class="card__year"><?= e($year) ?></p><?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</main>

<?php require __DIR__ . '/partials/footer.php'; ?>

<script>const BASE_URL = '<?= BASE_URL ?>';</script>
<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body>
</html>
