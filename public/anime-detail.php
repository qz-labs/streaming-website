<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Auth.php';
requireLogin();
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/JikanApi.php';

$malId = intval($_GET['mal_id'] ?? 0);
if ($malId <= 0) {
    header('Location: ' . BASE_URL . '/anime.php');
    exit;
}

$jikan = new JikanApi();

// ── Fetch Jikan anime metadata (cached) ──────────────────────────────────────
$anime = $jikan->animeDetails($malId);

if (empty($anime)) {
    http_response_code(404);
    $activePage = 'anime';
    require __DIR__ . '/partials/nav.php';
    echo '<main style="padding:4rem 4%"><h1>Anime not found</h1><p style="color:var(--text-muted);margin-top:.5rem;">The requested anime could not be loaded. It may no longer exist on MyAnimeList, or the API may be temporarily unavailable. <a href="' . BASE_URL . '/anime.php" style="color:var(--red)">Back to Anime</a></p></main>';
    require __DIR__ . '/partials/footer.php';
    exit;
}

// ── Jikan metadata ────────────────────────────────────────────────────────────
$title        = $anime['title_english'] ?: ($anime['title'] ?? 'Unknown');
$titleJp      = $anime['title'] ?? '';
$synopsis     = $anime['synopsis'] ?? '';
$type         = $anime['type'] ?? 'TV';
$status       = $anime['status'] ?? '';
$episodeCount = (int)($anime['episodes'] ?? 0);
$duration     = $anime['duration'] ?? '';
$score        = isset($anime['score']) && $anime['score'] > 0 ? ratingBadge((float)$anime['score']) : '';
$rank         = $anime['rank'] ?? null;
$year         = yearFromDate($anime['aired']['from'] ?? null);
$genres       = $anime['genres'] ?? [];
$themes       = $anime['themes'] ?? [];
$allGenres    = array_merge($genres, $themes);
$studios      = $anime['studios'] ?? [];
$poster       = jikanImg($anime['images'] ?? [], 'large');
$trailer      = $anime['trailer']['url'] ?? '';
$typeBadge    = animeTypeBadge($type);

// ── Sequel chain (MAL-based season navigation) ────────────────────────────────
// Each MAL entry is already one season. sequelChain() walks Sequel relations
// to build an ordered list. This replaces the TMDB season selector entirely.
$isMovie      = in_array(strtoupper($type), ['MOVIE'], true);
$sequelChain  = (!$isMovie) ? $jikan->sequelChain($malId) : [];

// Find which position in the chain this MAL ID occupies (= current season index)
$currentChainIdx = 0;
foreach ($sequelChain as $i => $s) {
    if ((int)$s['mal_id'] === $malId) { $currentChainIdx = $i; break; }
}

// ── Episodes from Jikan for the current MAL entry ─────────────────────────────
$episodes   = [];
$thumbs     = [];
if (!$isMovie && $episodeCount > 0) {
    $episodes = $jikan->allAnimeEpisodes($malId);
    $thumbs   = $jikan->episodeThumbnails($malId);
}

// ── Watch URLs ────────────────────────────────────────────────────────────────
$firstEpUrl = '';
if ($isMovie) {
    $firstEpUrl = animeWatchUrl($malId, 1);
} elseif (!empty($episodes)) {
    $firstEpNum = (int)($episodes[0]['mal_id'] ?? 1);
    $firstEpUrl = animeWatchUrl($malId, $firstEpNum);
} elseif ($episodeCount > 0) {
    $firstEpUrl = animeWatchUrl($malId, 1);
}

$today      = date('Y-m-d');

// Favorites state
$user            = currentUser();
$isFavorited     = false;
$continueProgress = null;
if ($user) {
    require_once __DIR__ . '/../src/Database.php';
    $stmt = Database::get()->prepare(
        "SELECT id FROM favorites WHERE user_id=? AND content_type='anime' AND content_id=?"
    );
    $stmt->execute([$user['id'], $malId]);
    $isFavorited = (bool)$stmt->fetch();

    $stmt = Database::get()->prepare(
        "SELECT episode, progress_seconds FROM watch_progress
         WHERE user_id=? AND content_type='anime' AND content_id=?"
    );
    $stmt->execute([$user['id'], $malId]);
    $continueProgress = $stmt->fetch() ?: null;
}

$activePage = 'anime';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?= e($title) ?> &ndash; <?= e(SITE_NAME) ?></title>
  <?php require __DIR__ . '/partials/fonts.php'; ?>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
  <style>
    .anime-detail-hero {
      background: linear-gradient(135deg, #0d0017 0%, #141414 60%);
    }
  </style>
</head>
<body>

<?php require __DIR__ . '/partials/nav.php'; ?>

<!-- Hero -->
<section class="detail-hero anime-detail-hero">
  <div class="detail-content">
    <img class="detail-poster" src="<?= e($poster) ?>" alt="<?= e($title) ?> poster" width="200" height="300" loading="eager">
    <div class="detail-info">

      <?php if ($typeBadge): ?>
        <div style="margin-bottom:.5rem">
          <span class="badge badge--type"><?= e($typeBadge) ?></span>
          <?php if ($status): ?><span class="badge badge--status"><?= e($status) ?></span><?php endif; ?>
        </div>
      <?php endif; ?>

      <h1 class="detail-title"><?= e($title) ?></h1>

      <?php if ($titleJp && $titleJp !== $title): ?>
        <p class="detail-tagline"><?= e($titleJp) ?></p>
      <?php endif; ?>

      <div class="detail-meta">
        <?php if ($score): ?><span class="rating">&#9733; <?= e($score) ?></span><?php endif; ?>
        <?php if ($year):  ?><span><?= e($year) ?></span><?php endif; ?>
        <?php if ($episodeCount > 0): ?><span><?= $episodeCount ?> episodes</span><?php endif; ?>
        <?php if ($duration): ?><span><?= e($duration) ?></span><?php endif; ?>
        <?php if ($rank):  ?><span>Rank #<?= (int)$rank ?></span><?php endif; ?>
      </div>

      <?php if (!empty($allGenres)): ?>
        <div class="genres" style="margin-bottom:.75rem">
          <?php foreach ($allGenres as $g): ?>
            <span class="detail-genre"><?= e($g['name']) ?></span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($studios)): ?>
        <p style="font-size:.82rem;color:var(--text-muted);margin-bottom:.75rem;">
          Studio: <?= e(implode(', ', array_column($studios, 'name'))) ?>
        </p>
      <?php endif; ?>

      <?php if ($synopsis): ?>
        <p class="detail-overview"><?= e(truncate($synopsis, 400)) ?></p>
      <?php endif; ?>

      <div class="detail-buttons">
        <?php if ($continueProgress): ?>
          <?php
            $contEp  = max(1, (int)$continueProgress['episode']);
            $contT   = (int)$continueProgress['progress_seconds'];
            $contUrl = animeWatchUrl($malId, $contEp, 1, $contT)
                       . '&from=' . urlencode('/anime-detail.php?mal_id=' . $malId);
          ?>
          <a class="btn btn-play" href="<?= e($contUrl) ?>">&#9654; Continue Ep <?= $contEp ?></a>
          <?php if (!$isMovie && $firstEpUrl): ?>
            <a class="btn btn-info" href="<?= e($firstEpUrl) ?>">&#9654; Ep 1</a>
          <?php endif; ?>
        <?php elseif ($firstEpUrl): ?>
          <a class="btn btn-play" href="<?= e($firstEpUrl) ?>">&#9654; <?= $isMovie ? 'Watch Movie' : 'Play Episode 1' ?></a>
        <?php else: ?>
          <span class="btn btn-info" style="cursor:default;opacity:.5;">&#9888; Stream unavailable</span>
        <?php endif; ?>
        <a class="btn btn-info" href="<?= e(BASE_URL . '/anime.php') ?>" id="detail-back-btn">&#8592; Back</a>
        <?php if ($trailer): ?>
          <a class="btn btn-info" href="<?= e($trailer) ?>" target="_blank" rel="noopener">&#9654; Trailer</a>
        <?php endif; ?>
        <button
          class="btn btn-fav<?= $isFavorited ? ' btn-fav--active' : '' ?>"
          id="fav-btn"
          data-type="anime"
          data-id="<?= $malId ?>"
          data-title="<?= e($title) ?>"
          data-poster="<?= e($poster) ?>"
          aria-label="<?= $isFavorited ? 'Remove from favorites' : 'Add to favorites' ?>"
        ><?= $isFavorited ? '&#9829;' : '&#9825;' ?> Favorite</button>
      </div>

    </div>
  </div>
</section>

<?php if (!$isMovie): ?>
<!-- Season selector + episode list -->
<section class="season-section">
  <div class="season-header">
    <h3>Episodes</h3>
    <?php if (count($sequelChain) > 1): ?>
      <select id="season-select" class="season-select">
        <?php foreach ($sequelChain as $i => $s): ?>
          <option
            value="<?= e(BASE_URL . '/anime-detail.php?mal_id=' . (int)$s['mal_id']) ?>"
            <?= $i === $currentChainIdx ? 'selected' : '' ?>
          >
            Season <?= $s['season_num'] ?>
            <?php if ($s['episode_count'] > 0): ?>(<?= $s['episode_count'] ?> eps)<?php endif; ?>
            &mdash; <?= e($s['title']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
  </div>

  <?php if (!empty($episodes)): ?>
    <div class="episode-grid">
      <?php foreach ($episodes as $ep): ?>
        <?php
          $epNum        = (int)($ep['mal_id'] ?? 0);
          $epTitle      = $ep['title'] ?? ('Episode ' . $epNum);
          $epDate       = substr($ep['aired'] ?? '', 0, 10); // "2011-10-02"
          $epStill      = $thumbs[$epNum] ?? $poster;
          $epWatch      = animeWatchUrl($malId, $epNum);
          $isUpcoming   = $epDate !== '' && $epDate > $today;
          $epDatePretty = $epDate ? date('M j, Y', strtotime($epDate)) : '';
        ?>
        <div class="episode-card<?= $isUpcoming ? ' episode-card--upcoming' : '' ?>">
          <!-- Jikan has no episode stills — use poster as placeholder -->
          <?php if ($isUpcoming): ?>
            <div class="episode-card__still-wrap">
              <img class="episode-card__still" src="<?= e($epStill) ?>" alt="<?= e($epTitle) ?>" loading="lazy">
              <div class="episode-card__lock">&#128274;</div>
            </div>
          <?php else: ?>
            <a href="<?= e($epWatch) ?>">
              <img class="episode-card__still" src="<?= e($epStill) ?>" alt="<?= e($epTitle) ?>" loading="lazy">
            </a>
          <?php endif; ?>
          <div class="episode-card__body">
            <p class="episode-card__num">Episode <?= $epNum ?></p>
            <h4 class="episode-card__title"><?= e($epTitle) ?></h4>
            <?php if ($isUpcoming && $epDatePretty): ?>
              <p class="episode-card__upcoming-badge">&#9201; Releases <?= e($epDatePretty) ?></p>
            <?php elseif ($epDatePretty): ?>
              <p class="episode-card__date"><?= e($epDatePretty) ?></p>
            <?php endif; ?>
            <?php if (!$isUpcoming): ?>
              <a class="episode-card__watch" href="<?= e($epWatch) ?>">&#9654; Watch</a>
            <?php else: ?>
              <span class="episode-card__watch episode-card__watch--soon">Not yet aired</span>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php elseif ($episodeCount === 0): ?>
    <p style="color:var(--text-muted)">No episode data available yet.</p>
  <?php else: ?>
    <!-- Episodes exist on MAL but Jikan episode list isn't available — show numbered links -->
    <div class="episode-grid">
      <?php for ($n = 1; $n <= min($episodeCount, 100); $n++): ?>
        <div class="episode-card">
          <a href="<?= e(animeWatchUrl($malId, $n)) ?>">
            <img class="episode-card__still" src="<?= e($poster) ?>" alt="Episode <?= $n ?>" loading="lazy">
          </a>
          <div class="episode-card__body">
            <p class="episode-card__num">Episode <?= $n ?></p>
            <a class="episode-card__watch" href="<?= e(animeWatchUrl($malId, $n)) ?>">&#9654; Watch</a>
          </div>
        </div>
      <?php endfor; ?>
      <?php if ($episodeCount > 100): ?>
        <p style="color:var(--text-muted);padding:1rem 0">
          Showing first 100 of <?= $episodeCount ?> episodes.
          <a href="<?= e(animeWatchUrl($malId, 1)) ?>" style="color:var(--red)">Start watching</a>
        </p>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>

<script>const BASE_URL = '<?= BASE_URL ?>';</script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const sel = document.getElementById('season-select');
    if (sel) {
        sel.addEventListener('change', () => {
            if (sel.value) window.location.href = sel.value;
        });
    }
});
</script>
<script src="<?= BASE_URL ?>/assets/js/main.js?v=<?= filemtime(__DIR__ . '/assets/js/main.js') ?>"></script>
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
</body>
</html>
