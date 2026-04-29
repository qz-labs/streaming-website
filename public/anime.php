<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Auth.php';
requireLogin();
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/JikanApi.php';

$jikan = new JikanApi();

// 5 calls max — Jikan rate-limit is 3 req/sec; cache handles subsequent loads.
$seasonal    = $jikan->seasonalAnime();
$topAiring   = $jikan->topAnime('tv',    'airing');
$topTv       = $jikan->topAnime('tv',    'bypopularity');
$topMovies   = $jikan->topAnime('movie', 'bypopularity');
$topFavorite = $jikan->topAnime('tv',    'favorite');

// Pick a hero from seasonal anime that has an image
$heroPool = array_values(array_filter($seasonal, fn($a) => !empty($a['images']['jpg']['large_image_url'])));
$hero     = !empty($heroPool) ? $heroPool[0] : ($topTv[0] ?? null);

// Carousel slides (up to 5)
$heroSlides = array_map(function ($a) {
    $malId = (int)($a['mal_id'] ?? 0);
    return [
        'backdrop'  => jikanImg($a['images'] ?? [], 'large'),
        'title'     => $a['title_english'] ?: ($a['title'] ?? ''),
        'overview'  => truncate($a['synopsis'] ?? '', 160),
        'watchUrl'  => animeWatchUrl($malId, 1),
        'detailUrl' => animeDetailUrl($malId),
        'rating'    => isset($a['score']) && $a['score'] > 0 ? ratingBadge((float)$a['score']) : '',
        'year'      => yearFromDate($a['aired']['from'] ?? null),
    ];
}, array_slice($heroPool, 0, 5));

// Anime genre pills (Jikan genre objects have mal_id + name)
$usedAnimeGenres = [];
foreach ([...$seasonal, ...$topAiring, ...$topTv, ...$topFavorite, ...$topMovies] as $a) {
    foreach ($a['genres'] ?? [] as $g) {
        $usedAnimeGenres[(int)$g['mal_id']] = $g['name'];
    }
}
asort($usedAnimeGenres);

// Continue Watching + Favorites (anime only)
$user = currentUser();
$continueWatching = [];
$myFavorites      = [];
if ($user) {
    require_once __DIR__ . '/../src/Database.php';
    $stmt = Database::get()->prepare(
        "SELECT * FROM watch_progress
         WHERE user_id = ? AND content_type = 'anime'
         ORDER BY updated_at DESC LIMIT 20"
    );
    $stmt->execute([$user['id']]);
    $continueWatching = $stmt->fetchAll();

    $stmt = Database::get()->prepare(
        "SELECT * FROM favorites
         WHERE user_id = ? AND content_type = 'anime'
         ORDER BY added_at DESC LIMIT 20"
    );
    $stmt->execute([$user['id']]);
    $myFavorites = $stmt->fetchAll();
}

$activePage = 'anime';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Anime &ndash; <?= e(SITE_NAME) ?></title>
  <?php require __DIR__ . '/partials/fonts.php'; ?>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= ASSET_VERSION ?>">
  <style>
    /* Anime hero: portrait poster blurred as bg */
    .anime-hero-bg { background: #060010; }
  </style>
</head>
<body>

<?php require __DIR__ . '/partials/nav.php'; ?>

<?php if ($hero): ?>
  <?php
    $malId    = (int)$hero['mal_id'];
    $heroName = $hero['title_english'] ?: ($hero['title'] ?? '');
    $heroYear = yearFromDate($hero['aired']['from'] ?? null);
    $heroRate = isset($hero['score']) && $hero['score'] > 0 ? ratingBadge((float)$hero['score']) : '';
    $heroBg   = jikanImg($hero['images'] ?? [], 'large');
    $heroLink = animeDetailUrl($malId);
    $heroType = $hero['type'] ?? 'TV';
  ?>
  <section
    class="hero anime-hero anime-hero-bg hero--anime-portrait"
    data-slides="<?= e(json_encode($heroSlides)) ?>"
  >
    <div class="hero__bg">
      <img src="<?= e($heroBg) ?>" alt="" referrerpolicy="no-referrer" aria-hidden="true">
    </div>
    <div class="hero__content">
      <span class="hero__badge">&#9654; <?= $heroType === 'TV' ? 'Airing Now' : e($heroType) ?></span>
      <h1 class="hero__title"><?= e($heroName) ?></h1>
      <div class="hero__meta">
        <?php if ($heroRate): ?><span class="rating">&#9733; <?= e($heroRate) ?></span><?php endif; ?>
        <?php if ($heroYear): ?><span><?= e($heroYear) ?></span><?php endif; ?>
        <?php if (!empty($hero['episodes'])): ?><span><?= (int)$hero['episodes'] ?> eps</span><?php endif; ?>
      </div>
      <?php if (!empty($hero['synopsis'])): ?>
        <p class="hero__overview"><?= e(truncate($hero['synopsis'], 160)) ?></p>
      <?php endif; ?>
      <div class="hero__buttons">
        <a class="btn btn-play" href="<?= e($heroLink) ?>">&#9654; Watch Now</a>
        <a class="btn btn-info"  href="<?= e($heroLink) ?>">&#9432; More Info</a>
      </div>
    </div>
  </section>
<?php endif; ?>

<?php if (!empty($usedAnimeGenres)): ?>
<div class="genre-section">
  <span class="genre-section__label">Browse by Genre</span>
  <div class="genre-filters" role="group" aria-label="Filter by genre">
    <button class="genre-pill active" data-genre-id="all">All</button>
    <?php foreach ($usedAnimeGenres as $gid => $gname): ?>
      <button class="genre-pill" data-genre-id="<?= $gid ?>"><?= e($gname) ?></button>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<main class="rows-container">
  <?php renderContinueWatchingRow($continueWatching); ?>
  <?php renderFavoritesRow('My Favorites', $myFavorites); ?>
  <?php renderAnimeRow('Airing This Season',      $seasonal,    true); ?>
  <?php renderAnimeRow('Top Airing Now',          $topAiring,   true); ?>
  <?php renderAnimeRow('Most Popular Series',     $topTv,       true); ?>
  <?php renderAnimeRow('All-Time Fan Favorites',  $topFavorite, true); ?>
  <?php renderAnimeRow('Popular Anime Movies',    $topMovies,   true); ?>
</main>

<?php require __DIR__ . '/partials/footer.php'; ?>

<script>const BASE_URL = '<?= BASE_URL ?>';</script>
<script src="<?= BASE_URL ?>/assets/js/main.js?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>
