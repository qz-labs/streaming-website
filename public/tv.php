<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Auth.php';
requireLogin();
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/TmdbApi.php';

$id     = intval($_GET['id']     ?? 0);
$season = intval($_GET['season'] ?? 1);
if ($id <= 0) {
    header('Location: ' . BASE_URL . '/');
    exit;
}
if ($season <= 0) $season = 1;

$api        = new TmdbApi();
$show       = $api->tvDetails($id);

if (empty($show) || empty($show['id'])) {
    http_response_code(404);
    $activePage = '';
    require __DIR__ . '/partials/nav.php';
    echo '<main style="padding:4rem 4%;"><h1>Show not found</h1></main>';
    require __DIR__ . '/partials/footer.php';
    exit;
}

$seasonData = $api->tvSeason($id, $season);
$episodes   = $seasonData['episodes'] ?? [];

// Available seasons (skip season 0 – Specials with no episodes)
$seasons = array_filter($show['seasons'] ?? [], fn($s) => (int)$s['season_number'] > 0);
$seasons = array_values($seasons);

// If user requests a season that doesn't exist, fall back to 1
$validSeasons = array_column($seasons, 'season_number');
if (!in_array($season, $validSeasons, true) && !empty($validSeasons)) {
    $season = (int)$validSeasons[0];
    header('Location: ' . BASE_URL . '/tv.php?id=' . $id . '&season=' . $season);
    exit;
}

$title    = $show['name'] ?? 'Unknown';
$tagline  = $show['tagline'] ?? '';
$overview = $show['overview'] ?? '';
$year     = yearFromDate($show['first_air_date'] ?? null);
$rating   = isset($show['vote_average']) ? ratingBadge((float)$show['vote_average']) : '';
$genres   = $show['genres'] ?? [];
$cast     = array_slice($show['credits']['cast'] ?? [], 0, 12);
$backdrop = backdropUrl($show['backdrop_path'] ?? null, 'w780');
$poster   = imgUrl($show['poster_path'] ?? null, 'w342');
$numSeasons = count($seasons);

// First episode of current season for the quick-play button
$firstEp = $episodes[0] ?? null;
$quickPlayUrl = $firstEp
    ? tvWatchUrl($id, $season, (int)$firstEp['episode_number'])
    : tvWatchUrl($id, $season, 1);

// Favorites state + continue watching
$user            = currentUser();
$isFavorited     = false;
$continueProgress = null;
if ($user) {
    require_once __DIR__ . '/../src/Database.php';
    $stmt = Database::get()->prepare(
        "SELECT id FROM favorites WHERE user_id=? AND content_type='tv' AND content_id=?"
    );
    $stmt->execute([$user['id'], $id]);
    $isFavorited = (bool)$stmt->fetch();

    $stmt2 = Database::get()->prepare(
        "SELECT season, episode, progress_seconds FROM watch_progress
         WHERE user_id=? AND content_type='tv' AND content_id=?"
    );
    $stmt2->execute([$user['id'], $id]);
    $continueProgress = $stmt2->fetch() ?: null;
}
$favPoster = $show['poster_path'] ?? '';

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

<!-- Hero -->
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
        <?php if ($numSeasons > 0): ?><span><?= $numSeasons ?> Season<?= $numSeasons !== 1 ? 's' : '' ?></span><?php endif; ?>
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
            $contS       = max(1, (int)$continueProgress['season']);
            $contE       = max(1, (int)$continueProgress['episode']);
            $contT       = (int)$continueProgress['progress_seconds'];
            $contWatchUrl = tvWatchUrl($id, $contS, $contE, $contT) . '&from=' . urlencode('/tv.php?id=' . $id);
          ?>
          <span id="continue-block" style="display:contents;">
            <a class="btn btn-play" href="<?= e($contWatchUrl) ?>">&#9654; Continue S<?= $contS ?>E<?= $contE ?></a>
            <a class="btn btn-secondary" href="<?= e($quickPlayUrl) ?>">&#9654; Play S<?= $season ?>E1</a>
            <button
              class="btn btn-remove-progress"
              id="remove-progress-btn"
              data-type="tv"
              data-id="<?= $id ?>"
            >&#10005; Remove from Watching</button>
          </span>
          <span id="play-block" style="display:none;">
            <a class="btn btn-play" href="<?= e($quickPlayUrl) ?>">&#9654; Play S<?= $season ?>E1</a>
          </span>
        <?php else: ?>
          <a class="btn btn-play" href="<?= e($quickPlayUrl) ?>">&#9654; Play S<?= $season ?>E1</a>
        <?php endif; ?>
        <a class="btn btn-info" href="<?= e(BASE_URL . '/') ?>" id="detail-back-btn">&#8592; Back</a>
        <button
          class="btn btn-fav<?= $isFavorited ? ' btn-fav--active' : '' ?>"
          id="fav-btn"
          data-type="tv"
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

<!-- Season selector + episode grid -->
<section class="season-section">
  <div class="season-header">
    <h3>Episodes</h3>
    <?php if (!empty($seasons)): ?>
      <select
        id="season-select"
        class="season-select"
        data-show-id="<?= $id ?>"
      >
        <?php foreach ($seasons as $s): ?>
          <option
            value="<?= (int)$s['season_number'] ?>"
            <?= (int)$s['season_number'] === $season ? 'selected' : '' ?>
          >
            Season <?= (int)$s['season_number'] ?>
            <?php if (!empty($s['episode_count'])): ?>
              (<?= (int)$s['episode_count'] ?> episodes)
            <?php endif; ?>
          </option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
  </div>

  <?php if (!empty($episodes)): ?>
    <div class="episode-grid">
      <?php foreach ($episodes as $ep): ?>
        <?php
          $epNum    = (int)($ep['episode_number'] ?? 0);
          $epTitle  = $ep['name'] ?? 'Episode ' . $epNum;
          $epOverview = truncate($ep['overview'] ?? '', 180);
          $epDate   = $ep['air_date'] ?? '';
          $epStill  = stillUrl($ep['still_path'] ?? null);
          $epWatch  = tvWatchUrl($id, $season, $epNum);
        ?>
        <div class="episode-card">
          <a href="<?= e($epWatch) ?>">
            <img
              class="episode-card__still"
              src="<?= e($epStill) ?>"
              alt="<?= e($epTitle) ?>"
              loading="lazy"
            >
          </a>
          <div class="episode-card__body">
            <p class="episode-card__num">Episode <?= $epNum ?></p>
            <h4 class="episode-card__title"><?= e($epTitle) ?></h4>
            <?php if ($epDate): ?>
              <p class="episode-card__date"><?= e($epDate) ?></p>
            <?php endif; ?>
            <?php if ($epOverview): ?>
              <p class="episode-card__overview"><?= e($epOverview) ?></p>
            <?php endif; ?>
            <a class="episode-card__watch" href="<?= e($epWatch) ?>">&#9654; Watch</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p style="color:var(--text-muted);">No episodes available for this season.</p>
  <?php endif; ?>
</section>

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
