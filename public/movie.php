<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Auth.php';
requireLogin();
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/TmdbApi.php';

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: ' . BASE_URL . '/');
    exit;
}

$api   = new TmdbApi();
$movie = $api->movieDetails($id);

if (empty($movie) || empty($movie['id'])) {
    http_response_code(404);
    $activePage = '';
    include __DIR__ . '/partials/nav.php';
    echo '<main style="padding:4rem 4%;"><h1>Movie not found</h1></main>';
    include __DIR__ . '/partials/footer.php';
    exit;
}

$title     = $movie['title'] ?? 'Unknown';
$tagline   = $movie['tagline'] ?? '';
$overview  = $movie['overview'] ?? '';
$runtime   = isset($movie['runtime']) ? formatRuntime((int)$movie['runtime']) : '';
$year      = yearFromDate($movie['release_date'] ?? null);
$rating    = isset($movie['vote_average']) ? ratingBadge((float)$movie['vote_average']) : '';
$genres    = $movie['genres'] ?? [];
$cast      = $movie['credits']['cast'] ?? [];
$cast      = array_slice($cast, 0, 12);
$backdrop  = backdropUrl($movie['backdrop_path'] ?? null);
$poster    = imgUrl($movie['poster_path'] ?? null, 'w342');
$watchUrl  = movieWatchUrl($id);

$activePage = '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($title) ?> &ndash; <?= e(SITE_NAME) ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>

<?php require __DIR__ . '/partials/nav.php'; ?>

<!-- Hero with backdrop -->
<section
  class="detail-hero"
  style="<?= $backdrop ? 'background-image: url(\'' . e($backdrop) . '\');' : '' ?>"
>
  <div class="detail-content">
    <img class="detail-poster" src="<?= e($poster) ?>" alt="<?= e($title) ?> poster">
    <div class="detail-info">
      <h1 class="detail-title"><?= e($title) ?></h1>
      <?php if ($tagline): ?>
        <p class="detail-tagline"><?= e($tagline) ?></p>
      <?php endif; ?>

      <div class="detail-meta">
        <?php if ($rating): ?><span class="rating">&#9733; <?= e($rating) ?></span><?php endif; ?>
        <?php if ($year):   ?><span><?= e($year) ?></span><?php endif; ?>
        <?php if ($runtime): ?><span><?= e($runtime) ?></span><?php endif; ?>
      </div>

      <?php if (!empty($genres)): ?>
        <div class="genres">
          <?php foreach ($genres as $g): ?>
            <span class="detail-genre"><?= e($g['name']) ?></span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($overview): ?>
        <p class="detail-overview"><?= e($overview) ?></p>
      <?php endif; ?>

      <div class="detail-buttons">
        <a class="btn btn-play" href="<?= e($watchUrl) ?>">&#9654; Play Movie</a>
        <a class="btn btn-info" href="<?= e(BASE_URL . '/') ?>" id="detail-back-btn">&#8592; Back</a>
      </div>
    </div>
  </div>
</section>

<!-- Cast -->
<?php if (!empty($cast)): ?>
<section class="cast-section">
  <h3>Cast</h3>
  <div class="cast-track">
    <?php foreach ($cast as $person): ?>
      <div class="cast-card">
        <img
          src="<?= e(imgUrl($person['profile_path'] ?? null, 'w185')) ?>"
          alt="<?= e($person['name'] ?? '') ?>"
          loading="lazy"
        >
        <p><?= e($person['name'] ?? '') ?></p>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>

<script>const BASE_URL = '<?= BASE_URL ?>';</script>
<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body>
</html>
