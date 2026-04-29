<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Auth.php';
requireLogin();
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/JikanApi.php';

// ── Input validation ──────────────────────────────────────────────────────────
$malId     = intval($_GET['mal_id']  ?? 0);
$episode   = intval($_GET['episode'] ?? 1);
$startTime = max(0, intval($_GET['t'] ?? 0));

// Optional back-navigation override (set by continue-watching / detail-page links)
$rawFrom  = $_GET['from'] ?? '';
$safeFrom = ($rawFrom !== '' && str_starts_with($rawFrom, '/') && !str_starts_with($rawFrom, '//'))
    ? $rawFrom : '';

if ($malId <= 0 || $episode <= 0) {
    header('Location: ' . BASE_URL . '/anime.php');
    exit;
}

// Allow fullscreen requests from embedded iframes
header('Permissions-Policy: fullscreen=*');

// ── Anime metadata ─────────────────────────────────────────────────────────────
$jikan = new JikanApi();
$anime = $jikan->animeDetails($malId);

if (empty($anime)) {
    header('Location: ' . BASE_URL . '/anime.php');
    exit;
}

$title        = $anime['title_english'] ?: ($anime['title'] ?? 'Anime');
$episodeCount = (int)($anime['episodes'] ?? 0);
$poster       = jikanImg($anime['images'] ?? [], 'large');

// ── Episode list from Jikan ───────────────────────────────────────────────────
$panelEpisodes = [];
$thumbs        = [];
if ($episodeCount > 0) {
    $panelEpisodes = $jikan->allAnimeEpisodes($malId);
    $thumbs        = $jikan->episodeThumbnails($malId);
}

// ── Prev / next episode ───────────────────────────────────────────────────────
$prevEpisode = null;
$nextEpisode = null;

if (!empty($panelEpisodes)) {
    $epNums = array_column($panelEpisodes, 'mal_id');
    $epNums = array_map('intval', $epNums);
    $pos    = array_search($episode, $epNums, true);
    if ($pos !== false) {
        if ($pos > 0)                    $prevEpisode = $epNums[$pos - 1];
        if ($pos < count($epNums) - 1)  $nextEpisode = $epNums[$pos + 1];
    } else {
        if ($episode > 1) $prevEpisode = $episode - 1;
        $nextEpisode = $episode + 1;
    }
} else {
    if ($episode > 1) $prevEpisode = $episode - 1;
    if ($episodeCount <= 0 || $episode < $episodeCount) $nextEpisode = $episode + 1;
}

// ── Sequel chain for season navigation ───────────────────────────────────────
$sequelChain     = $jikan->sequelChain($malId);
$currentChainIdx = 0;
foreach ($sequelChain as $i => $s) {
    if ((int)$s['mal_id'] === $malId) { $currentChainIdx = $i; break; }
}

// ── Should auto-remove from continue watching when near end? ─────────────────
$isAnimeMovie     = $nextEpisode === null && $episodeCount <= 1;
$isLastChainEntry = $currentChainIdx >= count($sequelChain) - 1;
$shouldAutoRemove = $isAnimeMovie || $nextEpisode === null && $isLastChainEntry;

// ── Player URLs ───────────────────────────────────────────────────────────────
// AniList IDs are more reliable than MAL IDs on dropfile.cc for version
// disambiguation (e.g. HxH 1999 vs 2011). Use AniList's own GraphQL API
// to convert MAL ID → AniList ID, fall back to mal-{id} on failure.
$dropfileIdPrefix = 'mal-' . $malId;
$alQuery = json_encode([
    'query'     => 'query($id:Int){Media(idMal:$id,type:ANIME){id}}',
    'variables' => ['id' => $malId],
]);
$alCtx = stream_context_create(['http' => [
    'method'        => 'POST',
    'header'        => "Content-Type: application/json\r\n",
    'content'       => $alQuery,
    'timeout'       => 4,
    'ignore_errors' => true,
]]);
$alRaw = @file_get_contents('https://graphql.anilist.co', false, $alCtx);
if ($alRaw !== false) {
    $alData    = json_decode($alRaw, true);
    $anilistId = $alData['data']['Media']['id'] ?? null;
    if ($anilistId) {
        $dropfileIdPrefix = 'anilist-' . (int)$anilistId;
    }
}
$dropfileSubUrl = 'https://dropfile.cc/player/tv/' . $dropfileIdPrefix . '/1/' . $episode . '?audio=sub&lang=en';
$dropfileDubUrl = 'https://dropfile.cc/player/tv/' . $dropfileIdPrefix . '/1/' . $episode . '?audio=dub&lang=en';

$detailUrl = BASE_URL . '/anime-detail.php?mal_id=' . $malId;
$backUrl   = $safeFrom !== '' ? BASE_URL . $safeFrom : $detailUrl;
$today     = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?= e($title) ?> &ndash; Episode <?= $episode ?> &ndash; <?= e(SITE_NAME) ?></title>
  <?php require __DIR__ . '/partials/fonts.php'; ?>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= ASSET_VERSION ?>">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/player.css?v=<?= ASSET_VERSION ?>">
</head>
<body>

<!-- ── Mini nav strip ──────────────────────────────────────────────────────── -->
<div class="watch-nav">
  <a class="watch-nav__logo" href="<?= BASE_URL ?>/"><?= e(SITE_NAME) ?></a>
  <a class="watch-nav__back" href="<?= e($backUrl) ?>">&#8592; Back</a>
  <a class="watch-nav__details" href="<?= e($detailUrl) ?>">&#9432; Details</a>
</div>

<!-- ── Main watch layout ───────────────────────────────────────────────────── -->
<div class="watch-layout">

  <!-- Left / main column -->
  <div class="watch-main">

    <!-- Topbar: back + title + fullscreen -->
    <div class="player-topbar">
      <a class="player-back" href="<?= e($backUrl) ?>" title="Back">&#8592;</a>
      <span class="player-title">
        <?= e($title) ?>
        <?php if (count($sequelChain) > 1): ?>
          &mdash; S<?= $currentChainIdx + 1 ?>
        <?php endif; ?>
        &mdash; Ep.<?= $episode ?>
      </span>
      <a class="player-details" href="<?= e($detailUrl) ?>" title="Details">&#9432; Details</a>
      <button class="topbar-fs-btn" id="topbar-fs-btn" title="Fullscreen">
        <svg id="topbar-fs-icon" viewBox="0 0 24 24" width="18" height="18" fill="currentColor">
          <path d="M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z"/>
        </svg>
      </button>
    </div>

    <!-- Iframe player -->
    <div class="player-frame-wrap" id="player-frame-wrap">
      <div class="player-loading" id="player-loading">
        <div class="spinner"></div>&nbsp;Loading&hellip;
      </div>
      <iframe
        id="anime-iframe"
        src="<?= e($dropfileSubUrl) ?>"
        frameborder="0"
        allowfullscreen
        referrerpolicy="origin"
        allow="autoplay *; fullscreen *; picture-in-picture *; encrypted-media *"
        scrolling="no"
      ></iframe>
    </div>

    <!-- Controls bar below video -->
    <div class="watch-controls-bar">

      <!-- Sub / Dub toggle -->
      <div class="lang-toggle" id="lang-toggle">
        <button class="lang-btn active" id="btn-sub" data-mode="sub">SUB</button>
        <button class="lang-btn"        id="btn-dub" data-mode="dub">DUB</button>
      </div>

      <!-- Status -->
      <div class="player-status" id="player-status">Loading stream&hellip;</div>

      <!-- Episode nav pushed to right -->
      <nav class="ep-nav">
        <?php if ($prevEpisode !== null): ?>
          <a href="<?= e(animeWatchUrl($malId, $prevEpisode)) ?>" title="Previous episode">&#8592; Ep.<?= $prevEpisode ?></a>
        <?php else: ?>
          <span>&#8592; Prev</span>
        <?php endif; ?>
        <?php if ($nextEpisode !== null): ?>
          <a href="<?= e(animeWatchUrl($malId, $nextEpisode)) ?>" title="Next episode">Ep.<?= $nextEpisode ?> &#8594;</a>
        <?php else: ?>
          <span>Next &#8594;</span>
        <?php endif; ?>
      </nav>

    </div><!-- /watch-controls-bar -->

  </div><!-- /watch-main -->

  <!-- ── Episode sidebar ──────────────────────────────────────────────────── -->
  <div class="ep-sidebar" id="ep-sidebar">
    <div class="ep-panel__head">
      <span class="ep-panel__head-title">Episodes</span>

      <?php if (count($sequelChain) > 1): ?>
      <select class="ep-panel__season-sel" id="ep-panel-season">
        <?php foreach ($sequelChain as $i => $s): ?>
          <option
            value="<?= e(BASE_URL . '/anime-watch.php?mal_id=' . (int)$s['mal_id'] . '&episode=1') ?>"
            <?= $i === $currentChainIdx ? 'selected' : '' ?>
          >S<?= $s['season_num'] ?> &mdash; <?= e($s['title']) ?><?= $s['episode_count'] > 0 ? ' (' . $s['episode_count'] . ' eps)' : '' ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>

      <!-- Mobile collapse toggle -->
      <button class="ep-sidebar-toggle" id="ep-sidebar-toggle" title="Toggle episode list">&#9660;</button>
    </div>

    <div class="ep-panel__list" id="ep-panel-list">
      <?php if (!empty($panelEpisodes)): ?>
        <?php foreach ($panelEpisodes as $ep): ?>
          <?php
            $epN        = (int)($ep['mal_id'] ?? 0);
            $epT        = $ep['title'] ?? "Episode $epN";
            $epD        = substr($ep['aired'] ?? '', 0, 10);
            $epUrl      = animeWatchUrl($malId, $epN);
            $isCurr     = $epN === $episode;
            $isUpcoming = $epD !== '' && $epD > $today;
            $epThumb    = $thumbs[$epN] ?? null;
          ?>
          <?php if ($isUpcoming): ?>
            <div class="ep-panel__item ep-panel__item--upcoming">
          <?php else: ?>
            <a href="<?= e($epUrl) ?>" class="ep-panel__item<?= $isCurr ? ' ep-panel__item--current' : '' ?>">
          <?php endif; ?>
            <div class="ep-panel__thumb<?= $epThumb ? '' : ' ep-panel__thumb--blank' ?>">
              <?php if ($epThumb): ?>
                <img src="<?= e($epThumb) ?>" alt="" loading="lazy">
              <?php endif; ?>
              <?php if ($isCurr): ?><div class="ep-panel__now">&#9654;</div><?php endif; ?>
              <?php if ($isUpcoming): ?><div class="ep-panel__soon">&#128274;</div><?php endif; ?>
            </div>
            <div class="ep-panel__info">
              <span class="ep-panel__num">Ep <?= $epN ?><?= $isUpcoming ? ' &middot; Soon' : '' ?></span>
              <span class="ep-panel__title"><?= e(truncate($epT, 40)) ?></span>
            </div>
          <?php echo $isUpcoming ? '</div>' : '</a>'; ?>
        <?php endforeach; ?>

      <?php elseif ($episodeCount > 0): ?>
        <?php for ($n = 1; $n <= min($episodeCount, 500); $n++): ?>
          <a href="<?= e(animeWatchUrl($malId, $n)) ?>" class="ep-panel__item<?= $n === $episode ? ' ep-panel__item--current' : '' ?>">
            <div class="ep-panel__thumb ep-panel__thumb--blank">
              <?php if ($n === $episode): ?><div class="ep-panel__now">&#9654;</div><?php endif; ?>
            </div>
            <div class="ep-panel__info">
              <span class="ep-panel__num">Ep <?= $n ?></span>
              <span class="ep-panel__title">Episode <?= $n ?></span>
            </div>
          </a>
        <?php endfor; ?>

      <?php else: ?>
        <p class="ep-panel__empty">No episode data available.</p>
      <?php endif; ?>
    </div>
  </div>

</div><!-- /watch-layout -->

<script>
// ── Resume-position toast ─────────────────────────────────────────────────────
(function () {
  'use strict';
  const RESUME_AT = <?= (int)$startTime ?>;
  if (RESUME_AT > 0) {
    const mins = Math.floor(RESUME_AT / 60);
    const secs = String(RESUME_AT % 60).padStart(2, '0');
    const el   = document.createElement('div');
    el.className   = 'resume-toast';
    el.textContent = 'Resuming from ' + mins + ':' + secs;
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 4000);
  }
})();
</script>

<script>
(function () {
  'use strict';

  const SUB_URL  = <?= json_encode($dropfileSubUrl) ?>;
  const DUB_URL  = <?= json_encode($dropfileDubUrl) ?>;
  const EPISODE  = <?= $episode ?>;

  const iframe   = document.getElementById('anime-iframe');
  const loading  = document.getElementById('player-loading');
  const statusEl = document.getElementById('player-status');
  const langBtns = Array.from(document.querySelectorAll('.lang-btn'));

  let currentMode = 'sub';

  const PREF_KEY = 'anime_lang_pref';
  function savePref(mode) { try { localStorage.setItem(PREF_KEY, mode); } catch(_) {} }
  function loadPref()     { try { return localStorage.getItem(PREF_KEY) || 'sub'; } catch(_) { return 'sub'; } }

  function setMode(mode) {
    currentMode = mode;
    langBtns.forEach(b => b.classList.toggle('active', b.dataset.mode === mode));
    savePref(mode);
    loading.classList.remove('hidden');
    iframe.src = 'about:blank';
    setTimeout(() => {
      iframe.src = mode === 'dub' ? DUB_URL : SUB_URL;
      statusEl.textContent = 'Episode ' + EPISODE + ' · ' + mode.toUpperCase();
    }, 50);
  }

  iframe.addEventListener('load', () => loading.classList.add('hidden'));

  langBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      const mode = btn.dataset.mode;
      if (mode !== currentMode) setMode(mode);
    });
  });

  // ── Apply saved language pref ──────────────────────────────────────────────
  const savedPref = loadPref();
  if (savedPref === 'dub') {
    setMode('dub');
  } else {
    statusEl.textContent = 'Episode ' + EPISODE + ' · SUB';
    langBtns.forEach(b => b.classList.toggle('active', b.dataset.mode === 'sub'));
  }

  // ── Fullscreen ─────────────────────────────────────────────────────────────
  const frameWrap   = document.getElementById('player-frame-wrap');
  const topbarFsBtn = document.getElementById('topbar-fs-btn');

  if (topbarFsBtn && frameWrap) {
    topbarFsBtn.addEventListener('click', () => {
      if (!document.fullscreenElement && !document.webkitFullscreenElement) {
        const el  = iframe || frameWrap;
        const req = el.requestFullscreen || el.webkitRequestFullscreen;
        if (req) {
          req.call(el).catch(() => {
            const fb = frameWrap.requestFullscreen || frameWrap.webkitRequestFullscreen;
            if (fb) fb.call(frameWrap).catch(() => {});
          });
        }
      } else {
        (document.exitFullscreen || document.webkitExitFullscreen).call(document);
      }
    });

    const FS_ENTER = 'M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z';
    const FS_EXIT  = 'M5 16h3v3h2v-5H5v2zm3-8H5v2h5V5H8v3zm6 11h2v-3h3v-2h-5v5zm2-11V5h-2v5h5V8h-3z';

    function updateFsIcon() {
      const inFs = !!(document.fullscreenElement || document.webkitFullscreenElement);
      const icon = topbarFsBtn.querySelector('svg');
      if (icon) icon.innerHTML = '<path d="' + (inFs ? FS_EXIT : FS_ENTER) + '"/>';
      topbarFsBtn.title = inFs ? 'Exit fullscreen' : 'Fullscreen';
    }
    document.addEventListener('fullscreenchange',       updateFsIcon);
    document.addEventListener('webkitfullscreenchange', updateFsIcon);
  }

  // ── Season selector ────────────────────────────────────────────────────────
  const seasonSel = document.getElementById('ep-panel-season');
  if (seasonSel) {
    seasonSel.addEventListener('change', () => { if (seasonSel.value) window.location.href = seasonSel.value; });
  }

  // ── Mobile episode sidebar collapse ───────────────────────────────────────
  const sidebar   = document.getElementById('ep-sidebar');
  const toggleBtn = document.getElementById('ep-sidebar-toggle');
  if (sidebar && toggleBtn) {
    toggleBtn.addEventListener('click', () => {
      const collapsed = sidebar.classList.toggle('collapsed');
      toggleBtn.innerHTML = collapsed ? '&#9650;' : '&#9660;';
    });
    const cur = document.querySelector('.ep-panel__item--current');
    if (cur) setTimeout(() => cur.scrollIntoView({ block: 'center', behavior: 'smooth' }), 100);
  }
})();
</script>

<script>
// ── Progress tracking for cross-origin iframe ────────────────────────────────
(function () {
  'use strict';

  const SHOULD_AUTO_REMOVE = <?= $shouldAutoRemove ? 'true' : 'false' ?>;
  const PROGRESS_META = <?= json_encode([
    'content_type'  => 'anime',
    'content_id'    => $malId,
    'content_title' => $title,
    'poster_path'   => $poster,
    'season'        => $currentChainIdx + 1,
    'episode'       => $episode,
    'base_url'      => BASE_URL,
  ]) ?>;

  let visibleStart   = Date.now();
  let accumulatedSec = 0;

  function currentTotal() {
    return accumulatedSec + Math.round((Date.now() - visibleStart) / 1000);
  }

  function saveProgress(isFinal) {
    const totalSec = currentTotal();
    if (totalSec < 10) return;

    const epTitleEl = document.querySelector('.ep-panel__item--current .ep-panel__title');
    const payload = JSON.stringify({
      content_type     : PROGRESS_META.content_type,
      content_id       : PROGRESS_META.content_id,
      content_title    : PROGRESS_META.content_title,
      poster_path      : PROGRESS_META.poster_path,
      season           : PROGRESS_META.season,
      episode          : PROGRESS_META.episode,
      episode_title    : epTitleEl ? epTitleEl.textContent.trim() : '',
      progress_seconds : totalSec,
      duration_seconds : 0,
    });

    if (isFinal) {
      navigator.sendBeacon(PROGRESS_META.base_url + '/api/progress.php', payload);
    } else {
      fetch(PROGRESS_META.base_url + '/api/progress.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: payload,
      }).catch(() => {});
    }
  }

  document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
      accumulatedSec += Math.round((Date.now() - visibleStart) / 1000);
      saveProgress(false);
    } else {
      visibleStart = Date.now();
    }
  });

  setInterval(() => saveProgress(false), 30000);
  window.addEventListener('pagehide', () => saveProgress(true));

  // Auto-remove from continue watching after enough time passes
  // (for iframe we use a timer since we can't read video.currentTime)
  if (SHOULD_AUTO_REMOVE) {
    // Assume ~24 min episode; remove from continue-watching after 22 min visible
    const TYPICAL_EP_SEC = 22 * 60;
    const checkInterval  = setInterval(() => {
      if (currentTotal() >= TYPICAL_EP_SEC) {
        clearInterval(checkInterval);
        fetch(PROGRESS_META.base_url + '/api/progress.php', {
          method : 'DELETE',
          headers: { 'Content-Type': 'application/json' },
          body   : JSON.stringify({
            content_type: PROGRESS_META.content_type,
            content_id  : PROGRESS_META.content_id,
          }),
        }).catch(() => {});
      }
    }, 60000);
  }
})();
</script>

</body>
</html>
