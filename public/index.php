<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Auth.php';
requireLogin();
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/TmdbApi.php';

$api = new TmdbApi();

$trending    = $api->trendingMovies();
$trendingTv  = $api->trendingTv();
$popular     = $api->popularMovies();
$popularTv   = $api->popularTv();
$topRated    = $api->topRatedMovies();

// Build hero pool (items with a backdrop)
$heroPool = array_values(array_filter($trending, fn($m) => !empty($m['backdrop_path'])));
$hero     = !empty($heroPool) ? $heroPool[0] : ($trending[0] ?? null);

// Carousel slides (up to 5)
$heroSlides = array_map(function ($m) {
    $isMovie = !empty($m['title']);
    return [
        'backdrop'   => backdropUrl($m['backdrop_path'] ?? null),         // w1280 — JS uses on desktop
        'backdropSm' => backdropUrl($m['backdrop_path'] ?? null, 'w780'), // w780  — JS uses on mobile
        'title'      => $m['title'] ?? $m['name'] ?? '',
        'overview'   => truncate($m['overview'] ?? '', 160),
        'watchUrl'   => $isMovie ? movieWatchUrl((int)$m['id']) : tvWatchUrl((int)$m['id'], 1, 1),
        'detailUrl'  => BASE_URL . ($isMovie ? '/movie.php?id=' : '/tv.php?id=') . (int)$m['id'],
        'rating'     => isset($m['vote_average']) ? ratingBadge((float)$m['vote_average']) : '',
        'year'       => yearFromDate($m['release_date'] ?? $m['first_air_date'] ?? null),
    ];
}, array_slice($heroPool, 0, 5));

// Genre pills — collect unique genre IDs across all rows and map to names
$tmdbGenreMap = [
    28=>'Action', 12=>'Adventure', 16=>'Animation', 35=>'Comedy', 80=>'Crime',
    99=>'Documentary', 18=>'Drama', 14=>'Fantasy', 27=>'Horror', 9648=>'Mystery',
    10749=>'Romance', 878=>'Sci-Fi', 53=>'Thriller', 10752=>'War', 37=>'Western',
    10751=>'Family', 10759=>'Action & Adventure', 10765=>'Sci-Fi & Fantasy',
];
$usedGenreIds = [];
foreach (array_merge($trending, $trendingTv, $popular, $popularTv) as $item) {
    foreach ($item['genre_ids'] ?? [] as $gid) {
        $usedGenreIds[(int)$gid] = true;
    }
}
$activeGenres = array_intersect_key($tmdbGenreMap, $usedGenreIds);
asort($activeGenres);

// Continue Watching (movie + tv only on home page)
$user = currentUser();
$continueWatching = [];
$myFavorites      = [];
if ($user) {
    require_once __DIR__ . '/../src/Database.php';
    $stmt = Database::get()->prepare(
        "SELECT * FROM watch_progress
         WHERE user_id = ? AND content_type IN ('movie','tv')
         ORDER BY updated_at DESC LIMIT 20"
    );
    $stmt->execute([$user['id']]);
    $continueWatching = $stmt->fetchAll();

    $stmt = Database::get()->prepare(
        "SELECT * FROM favorites
         WHERE user_id = ? AND content_type IN ('movie','tv')
         ORDER BY added_at DESC LIMIT 20"
    );
    $stmt->execute([$user['id']]);
    $myFavorites = $stmt->fetchAll();
}

$activePage = 'home';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?= e(SITE_NAME) ?> &ndash; Watch Free Movies &amp; TV</title>
  <?php require __DIR__ . '/partials/fonts.php'; ?>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>
<body>

<?php require __DIR__ . '/partials/nav.php'; ?>

<?php if ($hero): ?>
<?php
  $heroIsMovie  = !empty($hero['title']);
  $heroWatchUrl = $heroIsMovie ? movieWatchUrl((int)$hero['id']) : tvWatchUrl((int)$hero['id'], 1, 1);
  $heroInfoUrl  = BASE_URL . ($heroIsMovie ? '/movie.php?id=' : '/tv.php?id=') . (int)$hero['id'];
  $heroYear     = yearFromDate($hero['release_date'] ?? $hero['first_air_date'] ?? null);
?>
<section
  class="hero"
  style="background-image: url('<?= e(backdropUrl($hero['backdrop_path'] ?? null, 'w780')) ?>')"
  data-backdrop-lg="<?= e(backdropUrl($hero['backdrop_path'] ?? null)) ?>"
  data-slides="<?= json_encode($heroSlides, JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP) ?>"
>
  <div class="hero__content">
    <span class="hero__badge">&#9654; Now Trending</span>
    <h1 class="hero__title"><?= e($hero['title'] ?? $hero['name'] ?? '') ?></h1>
    <div class="hero__meta">
      <?php if (!empty($hero['vote_average'])): ?>
        <span class="rating">&#9733; <?= e(ratingBadge((float)$hero['vote_average'])) ?></span>
      <?php endif; ?>
      <?php if ($heroYear): ?><span><?= e($heroYear) ?></span><?php endif; ?>
    </div>
    <?php if (!empty($hero['overview'])): ?>
      <p class="hero__overview"><?= e(truncate($hero['overview'], 160)) ?></p>
    <?php endif; ?>
    <div class="hero__buttons">
      <a class="btn btn-play" href="<?= e($heroWatchUrl) ?>">&#9654; Play</a>
      <a class="btn btn-info" href="<?= e($heroInfoUrl) ?>">&#9432; More Info</a>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if (!empty($activeGenres)): ?>
<div class="genre-filters" role="group" aria-label="Filter by genre">
  <button class="genre-pill active" data-genre-id="all">All</button>
  <?php foreach ($activeGenres as $gid => $gname): ?>
    <button class="genre-pill" data-genre-id="<?= $gid ?>"><?= e($gname) ?></button>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<main class="rows-container">
  <?php if (!empty($continueWatching)) renderContinueWatchingRow($continueWatching); ?>
  <?php if (!empty($myFavorites))      renderFavoritesRow('My Favorites', $myFavorites); ?>
  <div id="movies">
    <?php renderRow('Trending Movies', $trending, 'movie'); ?>
  </div>
  <div id="tv">
    <?php renderRow('Trending TV Shows', $trendingTv, 'tv'); ?>
  </div>
  <?php renderRow('Popular Movies', $popular, 'movie'); ?>
  <?php renderRow('Popular TV Shows', $popularTv, 'tv'); ?>
  <?php renderRow('Top Rated Movies', $topRated, 'movie'); ?>
</main>

<?php require __DIR__ . '/partials/footer.php'; ?>

<script>const BASE_URL = '<?= BASE_URL ?>';</script>
<script src="<?= BASE_URL ?>/assets/js/main.js?v=<?= filemtime(__DIR__ . '/assets/js/main.js') ?>"></script>
</body>
</html>
