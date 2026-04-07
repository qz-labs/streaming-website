<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/TmdbApi.php';

$api = new TmdbApi();

$trending    = $api->trendingMovies();
$trendingTv  = $api->trendingTv();
$popular     = $api->popularMovies();
$popularTv   = $api->popularTv();
$topRated    = $api->topRatedMovies();

// Pick a random trending item with a backdrop for the hero
$heroPool = array_values(array_filter($trending, fn($m) => !empty($m['backdrop_path'])));
$hero = !empty($heroPool) ? $heroPool[array_rand($heroPool)] : ($trending[0] ?? null);

$activePage = 'home';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e(SITE_NAME) ?> &ndash; Watch Free Movies &amp; TV</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>

<?php require __DIR__ . '/partials/nav.php'; ?>

<?php if ($hero): ?>
<section
  class="hero"
  style="background-image: url('<?= e(backdropUrl($hero['backdrop_path'] ?? null)) ?>')"
>
  <div class="hero__content">
    <span class="hero__badge">&#9654; Now Trending</span>
    <h1 class="hero__title"><?= e($hero['title'] ?? $hero['name'] ?? '') ?></h1>
    <div class="hero__meta">
      <?php if (!empty($hero['vote_average'])): ?>
        <span class="rating">&#9733; <?= e(ratingBadge((float)$hero['vote_average'])) ?></span>
      <?php endif; ?>
      <?php $heroYear = yearFromDate($hero['release_date'] ?? $hero['first_air_date'] ?? null); ?>
      <?php if ($heroYear): ?><span><?= e($heroYear) ?></span><?php endif; ?>
    </div>
    <?php if (!empty($hero['overview'])): ?>
      <p class="hero__overview"><?= e(truncate($hero['overview'], 160)) ?></p>
    <?php endif; ?>
    <div class="hero__buttons">
      <a class="btn btn-play" href="<?= e(movieWatchUrl((int)$hero['id'])) ?>">
        &#9654; Play
      </a>
      <a class="btn btn-info" href="<?= e(BASE_URL . '/movie.php?id=' . (int)$hero['id']) ?>">
        &#9432; More Info
      </a>
    </div>
  </div>
</section>
<?php endif; ?>

<main class="rows-container">
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
<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body>
</html>
