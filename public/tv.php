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
$backdrop = backdropUrl($show['backdrop_path'] ?? null);
$poster   = imgUrl($show['poster_path'] ?? null, 'w342');
$numSeasons = count($seasons);

// First episode of current season for the quick-play button
$firstEp = $episodes[0] ?? null;
$quickPlayUrl = $firstEp
    ? tvWatchUrl($id, $season, (int)$firstEp['episode_number'])
    : tvWatchUrl($id, $season, 1);

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
        <a class="btn btn-play" href="<?= e($quickPlayUrl) ?>">&#9654; Play S<?= $season ?>E1</a>
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
<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body>
</html>
