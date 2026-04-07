<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/JikanApi.php';
require_once __DIR__ . '/../src/TmdbApi.php';

$malId = intval($_GET['mal_id'] ?? 0);
if ($malId <= 0) {
    header('Location: ' . BASE_URL . '/anime.php');
    exit;
}

$jikan = new JikanApi();
$tmdb  = new TmdbApi();

$anime = $jikan->animeDetails($malId);

if (empty($anime)) {
    http_response_code(404);
    $activePage = 'anime';
    require __DIR__ . '/partials/nav.php';
    echo '<main style="padding:4rem 4%"><h1>Anime not found</h1></main>';
    require __DIR__ . '/partials/footer.php';
    exit;
}

// ── Jikan metadata ────────────────────────────────────────────────────────────
$title        = $anime['title_english'] ?: ($anime['title'] ?? 'Unknown');
$titleJp      = $anime['title'] ?? '';
$synopsis     = $anime['synopsis'] ?? '';
$type         = $anime['type'] ?? 'TV';          // TV / Movie / OVA / Special / ONA
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

// ── Map to TMDB for vidsrc streaming ─────────────────────────────────────────
$tmdbMap  = $tmdb->findAnimeTmdbId(
    $malId,
    $anime['title'] ?? '',
    $anime['title_english'] ?? '',
    $type
);
$tmdbId   = $tmdbMap ? (int)$tmdbMap['tmdb_id'] : 0;
$tmdbType = $tmdbMap ? $tmdbMap['type'] : 'tv';   // 'tv' or 'movie'

// If TMDB found, get season data for TV shows
$seasons    = [];
$currentSeason = intval($_GET['season'] ?? 1);
if ($tmdbId > 0 && $tmdbType === 'tv') {
    $showData = $tmdb->tvDetails($tmdbId);
    $seasons  = array_values(array_filter(
        $showData['seasons'] ?? [],
        fn($s) => (int)$s['season_number'] > 0
    ));
    // Clamp season to valid range
    $validSeasons = array_column($seasons, 'season_number');
    if (!in_array($currentSeason, $validSeasons, true) && !empty($validSeasons)) {
        $currentSeason = (int)$validSeasons[0];
    }
    $seasonData = $tmdb->tvSeason($tmdbId, $currentSeason);
    $episodes   = $seasonData['episodes'] ?? [];
} else {
    $episodes = [];
}

// For movies, watch URL is direct
$movieWatchUrl = ($tmdbId > 0 && $tmdbType === 'movie')
    ? movieWatchUrl($tmdbId)
    : '';

// Episode watch URL builder (used in template)
$firstEpUrl = '';
if ($tmdbId > 0 && $tmdbType === 'tv' && !empty($episodes)) {
    $firstEpNum = (int)($episodes[0]['episode_number'] ?? 1);
    $firstEpUrl = tvWatchUrl($tmdbId, $currentSeason, $firstEpNum);
}

$activePage = 'anime';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($title) ?> &ndash; <?= e(SITE_NAME) ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
  <style>
    /* Anime detail hero uses poster as bg (no TMDB backdrop) */
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
    <img class="detail-poster" src="<?= e($poster) ?>" alt="<?= e($title) ?> poster">
    <div class="detail-info">

      <?php if ($typeBadge): ?>
        <div style="margin-bottom:.5rem">
          <span style="background:#7c3aed;color:#fff;font-size:.7rem;font-weight:700;padding:3px 10px;border-radius:2px;letter-spacing:.5px;"><?= e($typeBadge) ?></span>
          <?php if ($status): ?><span style="color:var(--text-muted);font-size:.8rem;margin-left:.5rem;"><?= e($status) ?></span><?php endif; ?>
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
        <?php if ($tmdbId > 0 && $tmdbType === 'movie'): ?>
          <a class="btn btn-play" href="<?= e($movieWatchUrl) ?>">&#9654; Watch Movie</a>
        <?php elseif ($tmdbId > 0 && $firstEpUrl): ?>
          <a class="btn btn-play" href="<?= e($firstEpUrl) ?>">&#9654; Play S<?= $currentSeason ?>E1</a>
        <?php elseif ($tmdbId === 0): ?>
          <span class="btn btn-info" style="cursor:default;opacity:.5;" title="Could not find this title on the streaming index">&#9888; Stream unavailable</span>
        <?php endif; ?>
        <a class="btn btn-info" href="<?= e(BASE_URL . '/anime.php') ?>">&#8592; Back to Anime</a>
        <?php if ($trailer): ?>
          <a class="btn btn-info" href="<?= e($trailer) ?>" target="_blank" rel="noopener">&#9654; Trailer</a>
        <?php endif; ?>
      </div>

    </div>
  </div>
</section>

<?php if ($tmdbId > 0 && $tmdbType === 'tv' && !empty($seasons)): ?>
<!-- Season selector + episode grid -->
<section class="season-section">
  <div class="season-header">
    <h3>Episodes</h3>
    <select
      id="season-select"
      class="season-select"
      data-show-id="<?= $malId ?>"
      data-url-base="<?= e(BASE_URL . '/anime-detail.php?mal_id=' . $malId . '&season=') ?>"
    >
      <?php foreach ($seasons as $s): ?>
        <option
          value="<?= (int)$s['season_number'] ?>"
          <?= (int)$s['season_number'] === $currentSeason ? 'selected' : '' ?>
        >
          Season <?= (int)$s['season_number'] ?>
          <?php if (!empty($s['episode_count'])): ?>(<?= (int)$s['episode_count'] ?> eps)<?php endif; ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <?php if (!empty($episodes)): ?>
    <div class="episode-grid">
      <?php foreach ($episodes as $ep): ?>
        <?php
          $epNum      = (int)($ep['episode_number'] ?? 0);
          $epTitle    = $ep['name'] ?? 'Episode ' . $epNum;
          $epOverview = truncate($ep['overview'] ?? '', 180);
          $epDate     = $ep['air_date'] ?? '';
          $epStill    = stillUrl($ep['still_path'] ?? null);
          $epWatch    = tvWatchUrl($tmdbId, $currentSeason, $epNum);
        ?>
        <div class="episode-card">
          <a href="<?= e($epWatch) ?>">
            <img class="episode-card__still" src="<?= e($epStill) ?>" alt="<?= e($epTitle) ?>" loading="lazy">
          </a>
          <div class="episode-card__body">
            <p class="episode-card__num">Episode <?= $epNum ?></p>
            <h4 class="episode-card__title"><?= e($epTitle) ?></h4>
            <?php if ($epDate): ?><p class="episode-card__date"><?= e($epDate) ?></p><?php endif; ?>
            <?php if ($epOverview): ?><p class="episode-card__overview"><?= e($epOverview) ?></p><?php endif; ?>
            <a class="episode-card__watch" href="<?= e($epWatch) ?>">&#9654; Watch</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p style="color:var(--text-muted)">No episodes found for this season.</p>
  <?php endif; ?>
</section>
<?php elseif ($tmdbId === 0): ?>
<section style="padding:1.5rem 4%">
  <p style="color:var(--text-muted);font-size:.9rem;">
    &#9432; This title was not found in the streaming index. It may be too new, too obscure, or available under a different name.
    You can try searching for it manually on <a href="<?= e(BASE_URL . '/search.php') ?>" style="color:var(--red)">the search page</a>.
  </p>
</section>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>

<script>const BASE_URL = '<?= BASE_URL ?>';</script>
<script>
// Override season-select to use anime-detail URL instead of tv.php
document.addEventListener('DOMContentLoaded', () => {
    const sel = document.getElementById('season-select');
    if (sel) {
        sel.addEventListener('change', () => {
            const base = sel.dataset.urlBase;
            if (base) window.location.href = base + encodeURIComponent(sel.value);
        });
    }
});
</script>
<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body>
</html>
