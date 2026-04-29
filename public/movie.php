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
$backdrop  = backdropUrl($movie['backdrop_path'] ?? null, 'w780');
$poster    = imgUrl($movie['poster_path'] ?? null, 'w342');
$watchUrl  = movieWatchUrl($id);

// Favorites state + continue watching
$user            = currentUser();
$isFavorited     = false;
$continueProgress = null;
if ($user) {
    require_once __DIR__ . '/../src/Database.php';
    $stmt = Database::get()->prepare(
        "SELECT id FROM favorites WHERE user_id=? AND content_type='movie' AND content_id=?"
    );
    $stmt->execute([$user['id'], $id]);
    $isFavorited = (bool)$stmt->fetch();

    $stmt2 = Database::get()->prepare(
        "SELECT progress_seconds FROM watch_progress
         WHERE user_id=? AND content_type='movie' AND content_id=?"
    );
    $stmt2->execute([$user['id'], $id]);
    $continueProgress = $stmt2->fetch() ?: null;
}
$favPoster = $movie['poster_path'] ?? '';

$activePage = '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?= e($title) ?> &ndash; <?= e(SITE_NAME) ?></title>
  <?php require __DIR__ . '/partials/fonts.php'; ?>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= ASSET_VERSION ?>">
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
        <?php if ($continueProgress): ?>
          <?php
            $contT       = (int)$continueProgress['progress_seconds'];
            $contWatchUrl = movieWatchUrl($id, $contT) . '&from=' . urlencode('/movie.php?id=' . $id);
          ?>
          <span id="continue-block" style="display:contents;">
            <a class="btn btn-play" href="<?= e($contWatchUrl) ?>">&#9654; Continue Movie</a>
            <a class="btn btn-secondary" href="<?= e($watchUrl) ?>">&#9654; Play from Start</a>
            <button
              class="btn btn-remove-progress"
              id="remove-progress-btn"
              data-type="movie"
              data-id="<?= $id ?>"
            >&#10005; Remove from Watching</button>
          </span>
          <span id="play-block" style="display:none;">
            <a class="btn btn-play" href="<?= e($watchUrl) ?>">&#9654; Play Movie</a>
          </span>
        <?php else: ?>
          <a class="btn btn-play" href="<?= e($watchUrl) ?>">&#9654; Play Movie</a>
        <?php endif; ?>
        <a class="btn btn-info" href="<?= e(BASE_URL . '/') ?>" id="detail-back-btn">&#8592; Back</a>
        <button
          class="btn btn-fav<?= $isFavorited ? ' btn-fav--active' : '' ?>"
          id="fav-btn"
          data-type="movie"
          data-id="<?= $id ?>"
          data-title="<?= e($title) ?>"
          data-poster="<?= e($favPoster) ?>"
          aria-label="<?= $isFavorited ? 'Remove from favorites' : 'Add to favorites' ?>"
        ><?= $isFavorited ? '&#9829;' : '&#9825;' ?> Favorite</button>
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
<script src="<?= BASE_URL ?>/assets/js/main.js?v=<?= ASSET_VERSION ?>"></script>
<script>
(function () {
  'use strict';
  const btn = document.getElementById('fav-btn');
  if (!btn) return;
  btn.addEventListener('click', async () => {
    btn.disabled = true;
    try {
      const res  = await fetch(BASE_URL + '/api/favorites.php', {
        method : 'POST',
        headers: { 'Content-Type': 'application/json' },
        body   : JSON.stringify({
          content_type : btn.dataset.type,
          content_id   : parseInt(btn.dataset.id, 10),
          content_title: btn.dataset.title,
          poster_path  : btn.dataset.poster,
        }),
      });
      const data = await res.json();
      btn.classList.toggle('btn-fav--active', data.favorited);
      btn.innerHTML = (data.favorited ? '\u2665' : '\u2661') + ' Favorite';
      btn.setAttribute('aria-label', data.favorited ? 'Remove from favorites' : 'Add to favorites');
    } catch (err) {
      console.error('Favorites error:', err);
    } finally {
      btn.disabled = false;
    }
  });
})();
</script>
<script>
(function () {
  'use strict';
  const btn = document.getElementById('remove-progress-btn');
  if (!btn) return;
  btn.addEventListener('click', async () => {
    btn.disabled = true;
    try {
      await fetch(BASE_URL + '/api/progress.php', {
        method : 'DELETE',
        headers: { 'Content-Type': 'application/json' },
        body   : JSON.stringify({ content_type: btn.dataset.type, content_id: parseInt(btn.dataset.id, 10) }),
      });
      document.getElementById('continue-block').style.display = 'none';
      document.getElementById('play-block').style.display = '';
    } catch (err) {
      console.error('Remove progress error:', err);
      btn.disabled = false;
    }
  });
})();
</script>
</body>
</html>
