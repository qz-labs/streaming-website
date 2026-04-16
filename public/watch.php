<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Auth.php';
requireLogin();
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/TmdbApi.php';

// ── Input validation ──────────────────────────────────────────────────────────
$type      = $_GET['type'] ?? '';
$id        = intval($_GET['id']  ?? 0);
$season    = intval($_GET['s']   ?? 1);
$episode   = intval($_GET['e']   ?? 1);
$startTime = max(0, intval($_GET['t'] ?? 0));

// Optional back-navigation override (set by continue-watching links)
$rawFrom  = $_GET['from'] ?? '';
$safeFrom = ($rawFrom !== '' && str_starts_with($rawFrom, '/') && !str_starts_with($rawFrom, '//'))
    ? $rawFrom : '';

if (!in_array($type, ['movie', 'tv'], true) || $id <= 0) {
    header('Location: ' . BASE_URL . '/');
    exit;
}
if ($type === 'tv' && ($season <= 0 || $episode <= 0)) {
    header('Location: ' . BASE_URL . '/');
    exit;
}

// Allow fullscreen requests from embedded iframes (vidsrc etc.)
header('Permissions-Policy: fullscreen=*');

// ── Metadata (cached) ─────────────────────────────────────────────────────────
$api  = new TmdbApi();
$meta = ($type === 'movie') ? $api->movieDetails($id) : $api->tvDetails($id);

$title     = $meta['title'] ?? $meta['name'] ?? 'Streaming';
$origLang  = $meta['original_language'] ?? 'en';

// ── Record watch immediately (server-side, reliable) ─────────────────────────
// Saves the current episode so it appears in Continue Watching right away.
// On DUPLICATE KEY: update episode/season (in case user changed episodes) and
// reset progress only when the episode changed; preserve progress on same ep.
require_once __DIR__ . '/../src/Database.php';
$watchUser = currentUser();
if ($watchUser) {
    Database::get()->prepare("
        INSERT INTO watch_progress
            (user_id, content_type, content_id, content_title, poster_path,
             season, episode, episode_title, progress_seconds, duration_seconds)
        VALUES (?, ?, ?, ?, ?, ?, ?, '', 0, 0)
        ON DUPLICATE KEY UPDATE
            content_title    = VALUES(content_title),
            poster_path      = VALUES(poster_path),
            progress_seconds = IF(season != VALUES(season) OR episode != VALUES(episode),
                                  0, progress_seconds),
            duration_seconds = IF(season != VALUES(season) OR episode != VALUES(episode),
                                  0, duration_seconds),
            season           = VALUES(season),
            episode          = VALUES(episode),
            updated_at       = CURRENT_TIMESTAMP
    ")->execute([
        $watchUser['id'], $type, $id, $title,
        $meta['poster_path'] ?? '',
        $type === 'tv' ? $season  : 0,
        $type === 'tv' ? $episode : 0,
    ]);
}

$langNames = [
    'ja' => 'JPN', 'ko' => 'KOR', 'zh' => 'CHN', 'fr' => 'FRA',
    'de' => 'DEU', 'es' => 'SPA', 'it' => 'ITA', 'pt' => 'POR',
    'ru' => 'RUS', 'ar' => 'ARA', 'hi' => 'HIN', 'tr' => 'TUR',
];
$origLangLabel = strtoupper($langNames[$origLang] ?? $origLang);
$isEnglish     = ($origLang === 'en');

$detailUrl = ($type === 'movie')
    ? BASE_URL . '/movie.php?id=' . $id
    : BASE_URL . '/tv.php?id=' . $id . '&season=' . $season;

$backUrl = $safeFrom !== '' ? BASE_URL . $safeFrom : $detailUrl;

$pageTitle = ($type === 'tv')
    ? e($title) . ' &ndash; S' . $season . 'E' . $episode
    : e($title);

// ── TV: seasons list + current season episodes + prev/next ep ─────────────────
$tvSeasons   = [];
$tvEpisodes  = [];
$prevEpisode = null;
$nextEpisode = null;

if ($type === 'tv') {
    $allSeasons = array_filter($meta['seasons'] ?? [], fn($s) => (int)$s['season_number'] > 0);
    $tvSeasons  = array_values($allSeasons);
    $seasonData = $api->tvSeason($id, $season);
    $tvEpisodes = $seasonData['episodes'] ?? [];

    $epNums = array_map('intval', array_column($tvEpisodes, 'episode_number'));
    $pos    = array_search($episode, $epNums, true);
    if ($pos !== false) {
        if ($pos > 0)                    $prevEpisode = $epNums[$pos - 1];
        if ($pos < count($epNums) - 1)  $nextEpisode = $epNums[$pos + 1];
    } else {
        if ($episode > 1)  $prevEpisode = $episode - 1;
        $nextEpisode = $episode + 1;
    }
}

// ── Build embed URL sets ──────────────────────────────────────────────────────
$urlSets       = vidsrcAllUrls($type, $id, $season, $episode, $origLang);
$subSources    = $urlSets['sub'];
$dubSources    = $urlSets['dub'];

$subSourcesJson  = json_encode($subSources, JSON_HEX_TAG | JSON_HEX_AMP);
$dubSourcesJson  = json_encode($dubSources, JSON_HEX_TAG | JSON_HEX_AMP);
$isEnglishJson   = $isEnglish ? 'true' : 'false';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?= $pageTitle ?> &ndash; <?= e(SITE_NAME) ?></title>
  <?php require __DIR__ . '/partials/fonts.php'; ?>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/player.css?v=<?= filemtime(__DIR__ . '/assets/css/player.css') ?>">
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

    <!-- Top bar: back + title + fullscreen -->
    <div class="player-topbar">
      <a class="player-back" href="<?= e($backUrl) ?>" title="Back">&#8592;</a>
      <span class="player-title">
        <?= e($title) ?>
        <?php if ($type === 'tv'): ?>
          &mdash; S<?= $season ?>E<?= $episode ?>
        <?php endif; ?>
      </span>
      <a class="player-details" href="<?= e($detailUrl) ?>" title="Details">&#9432; Details</a>
      <button class="topbar-fs-btn" id="topbar-fs-btn" title="Fullscreen video">
        <svg id="topbar-fs-icon" viewBox="0 0 24 24" width="18" height="18" fill="currentColor">
          <path d="M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z"/>
        </svg>
      </button>
    </div>

    <!-- Video iframe -->
    <div class="player-frame-wrap" id="player-frame-wrap">
      <div class="player-loading" id="player-loading">
        <div class="spinner"></div>&nbsp;Loading&hellip;
      </div>
      <iframe
        id="player-iframe"
        src="about:blank"
        frameborder="0"
        allowfullscreen
        referrerpolicy="origin"
        allow="autoplay *; fullscreen *; picture-in-picture *; encrypted-media *"
        scrolling="no"
      ></iframe>
    </div>

    <!-- Controls bar (below video) -->
    <div class="watch-controls-bar">

      <!-- Sub / Dub toggle -->
      <div class="lang-toggle" id="lang-toggle">
        <button
          class="lang-btn"
          id="btn-sub"
          data-mode="sub"
          title="Original <?= e($origLangLabel) ?> audio<?= $isEnglish ? '' : ' + subtitles' ?>"
        ><?= $isEnglish ? 'ORIG' : e($origLangLabel) ?></button>
        <button
          class="lang-btn active"
          id="btn-dub"
          data-mode="dub"
          title="English<?= $isEnglish ? ' (original)' : ' dubbed' ?>"
        >ENG</button>
      </div>

      <!-- Source selector -->
      <div class="source-btns" id="source-btns">
        <?php foreach ($subSources as $i => $src): ?>
          <button
            class="src-btn<?= $i === 0 ? ' active' : '' ?> checking"
            data-index="<?= $i ?>"
            title="<?= e($src['label']) ?>"
          ><?= e($src['label']) ?></button>
        <?php endforeach; ?>
      </div>

      <!-- Status + episode nav pushed to right -->
      <div class="player-status" id="player-status">Checking sources&hellip;</div>

      <?php if ($type === 'tv'): ?>
      <nav class="ep-nav">
        <?php if ($prevEpisode !== null): ?>
          <a href="<?= e(tvWatchUrl($id, $season, $prevEpisode)) ?>" title="Previous episode">&#8592; Ep.<?= $prevEpisode ?></a>
        <?php else: ?>
          <span>&#8592; Prev</span>
        <?php endif; ?>
        <?php if ($nextEpisode !== null): ?>
          <a href="<?= e(tvWatchUrl($id, $season, $nextEpisode)) ?>" title="Next episode">Ep.<?= $nextEpisode ?> &#8594;</a>
        <?php else: ?>
          <span>Next &#8594;</span>
        <?php endif; ?>
      </nav>
      <?php endif; ?>

    </div><!-- /watch-controls-bar -->

  </div><!-- /watch-main -->

  <?php if ($type === 'tv'): ?>
  <!-- ── Episode sidebar ──────────────────────────────────────────────────── -->
  <div class="ep-sidebar" id="ep-sidebar">
    <div class="ep-panel__head">
      <span class="ep-panel__head-title">Episodes</span>

      <?php if (!empty($tvSeasons)): ?>
      <select class="ep-panel__season-sel" id="ep-panel-season">
        <?php foreach ($tvSeasons as $s): ?>
          <option
            value="<?= e(BASE_URL . '/watch.php?type=tv&id=' . $id . '&s=' . (int)$s['season_number'] . '&e=1') ?>"
            <?= (int)$s['season_number'] === $season ? 'selected' : '' ?>
          >S<?= (int)$s['season_number'] ?><?= !empty($s['episode_count']) ? ' (' . (int)$s['episode_count'] . ' eps)' : '' ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>

      <!-- Mobile collapse toggle -->
      <button class="ep-sidebar-toggle" id="ep-sidebar-toggle" title="Toggle episode list">&#9660;</button>
    </div>

    <div class="ep-panel__list" id="ep-panel-list">
      <?php if (!empty($tvEpisodes)): ?>
        <?php foreach ($tvEpisodes as $ep): ?>
          <?php
            $epN     = (int)($ep['episode_number'] ?? 0);
            $epT     = $ep['name'] ?? "Episode $epN";
            $epUrl   = tvWatchUrl($id, $season, $epN);
            $isCurr  = $epN === $episode;
            $epThumb = !empty($ep['still_path']) ? stillUrl($ep['still_path']) : null;
          ?>
          <a href="<?= e($epUrl) ?>" class="ep-panel__item<?= $isCurr ? ' ep-panel__item--current' : '' ?>">
            <div class="ep-panel__thumb<?= $epThumb ? '' : ' ep-panel__thumb--blank' ?>">
              <?php if ($epThumb): ?>
                <img src="<?= e($epThumb) ?>" alt="" loading="lazy">
              <?php endif; ?>
              <?php if ($isCurr): ?><div class="ep-panel__now">&#9654;</div><?php endif; ?>
            </div>
            <div class="ep-panel__info">
              <span class="ep-panel__num">Ep <?= $epN ?></span>
              <span class="ep-panel__title"><?= e(truncate($epT, 40)) ?></span>
            </div>
          </a>
        <?php endforeach; ?>
      <?php else: ?>
        <p class="ep-panel__empty">No episode data available.</p>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /watch-layout -->

<script>
(function () {
  'use strict';

  const SUB_SOURCES = <?= $subSourcesJson ?>;
  const DUB_SOURCES = <?= $dubSourcesJson ?>;
  const IS_ENGLISH  = <?= $isEnglishJson ?>;

  const iframe   = document.getElementById('player-iframe');
  const srcBtns  = Array.from(document.querySelectorAll('.src-btn'));
  const langBtns = Array.from(document.querySelectorAll('.lang-btn'));
  const status   = document.getElementById('player-status');
  const loading  = document.getElementById('player-loading');

  let currentMode = 'dub';
  let currentIdx  = 0;

  function sourcesForMode(mode) { return mode === 'sub' ? SUB_SOURCES : DUB_SOURCES; }
  function domainOf(url) { try { return new URL(url).hostname; } catch { return url; } }

  function loadSource(idx, mode) {
    mode        = mode || currentMode;
    currentIdx  = idx;
    currentMode = mode;

    const sources = sourcesForMode(mode);
    const src     = sources[idx];

    srcBtns.forEach((b, i) => b.classList.toggle('active', i === idx));
    langBtns.forEach(b => b.classList.toggle('active', b.dataset.mode === mode));

    loading.classList.remove('hidden');
    iframe.src = 'about:blank';
    setTimeout(() => { iframe.src = src.url; }, 50);

    const modeLabel = mode === 'sub' ? 'Original audio' : 'English (DUB)';
    status.textContent = src.label + ' · ' + modeLabel + ' · ' + domainOf(src.url) + '…';

    iframe.onload = () => {
      loading.classList.add('hidden');
      status.textContent = src.label + ' · ' + modeLabel + ' via ' + domainOf(src.url);
    };
  }

  function ping(pingUrl) {
    return new Promise((resolve) => {
      const ctrl  = new AbortController();
      const timer = setTimeout(() => { ctrl.abort(); resolve(false); }, 4000);
      fetch(pingUrl, { method: 'HEAD', mode: 'no-cors', signal: ctrl.signal })
        .then(() => { clearTimeout(timer); resolve(true); })
        .catch(() => { clearTimeout(timer); resolve(false); });
    });
  }

  async function autoDetect() {
    const defaultMode = IS_ENGLISH ? 'dub' : 'sub';
    currentMode = defaultMode;
    langBtns.forEach(b => b.classList.toggle('active', b.dataset.mode === defaultMode));

    const sources = sourcesForMode(defaultMode);
    for (let i = 0; i < sources.length; i++) {
      status.textContent = 'Checking ' + sources[i].label + ' of ' + sources.length + '…';
      const ok = await ping(sources[i].ping);
      srcBtns[i].classList.remove('checking');
      if (ok) {
        for (let j = i + 1; j < srcBtns.length; j++) srcBtns[j].classList.remove('checking');
        loadSource(i, defaultMode);
        return;
      }
    }
    status.textContent = 'No source responded — trying S1 anyway…';
    srcBtns.forEach(b => b.classList.remove('checking'));
    loadSource(0, defaultMode);
  }

  srcBtns.forEach(btn => {
    btn.addEventListener('click', () => loadSource(parseInt(btn.dataset.index, 10), currentMode));
  });

  langBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      const mode = btn.dataset.mode;
      if (mode !== currentMode) loadSource(currentIdx, mode);
    });
  });

  autoDetect();

  // ── Season selector ────────────────────────────────────────────────────────
  const seasonSel = document.getElementById('ep-panel-season');
  if (seasonSel) {
    seasonSel.addEventListener('change', () => { if (seasonSel.value) window.location.href = seasonSel.value; });
  }

  // ── Mobile episode sidebar collapse toggle ─────────────────────────────────
  const sidebar    = document.getElementById('ep-sidebar');
  const toggleBtn  = document.getElementById('ep-sidebar-toggle');
  if (sidebar && toggleBtn) {
    toggleBtn.addEventListener('click', () => {
      const collapsed = sidebar.classList.toggle('collapsed');
      toggleBtn.innerHTML = collapsed ? '&#9650;' : '&#9660;';
    });
    // Scroll to current episode
    const cur = document.querySelector('.ep-panel__item--current');
    if (cur) setTimeout(() => cur.scrollIntoView({ block: 'center', behavior: 'smooth' }), 100);
  }

  // ── Fullscreen button ──────────────────────────────────────────────────────
  const frameWrap   = document.getElementById('player-frame-wrap');
  const topbarFsBtn = document.getElementById('topbar-fs-btn');

  if (topbarFsBtn && frameWrap) {
    const iframeEl = document.getElementById('player-iframe');

    topbarFsBtn.addEventListener('click', () => {
      if (!document.fullscreenElement && !document.webkitFullscreenElement) {
        // Try the iframe first (lets vidsrc's own player go fullscreen natively),
        // fall back to the wrapper div if the browser blocks iframe fullscreen.
        const el = iframeEl || frameWrap;
        const req = el.requestFullscreen || el.webkitRequestFullscreen;
        if (req) {
          req.call(el).catch(() => {
            const fallback = frameWrap.requestFullscreen || frameWrap.webkitRequestFullscreen;
            if (fallback) fallback.call(frameWrap).catch(() => {});
          });
        }
      } else {
        (document.exitFullscreen || document.webkitExitFullscreen).call(document);
      }
    });

    const FS_ENTER_PATH = 'M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z';
    const FS_EXIT_PATH  = 'M5 16h3v3h2v-5H5v2zm3-8H5v2h5V5H8v3zm6 11h2v-3h3v-2h-5v5zm2-11V5h-2v5h5V8h-3z';

    function updateFsIcon() {
      const inFs = !!(document.fullscreenElement || document.webkitFullscreenElement);
      const icon = topbarFsBtn.querySelector('svg');
      if (icon) icon.innerHTML = '<path d="' + (inFs ? FS_EXIT_PATH : FS_ENTER_PATH) + '"/>';
      topbarFsBtn.title = inFs ? 'Exit fullscreen' : 'Fullscreen video';
    }
    document.addEventListener('fullscreenchange',       updateFsIcon);
    document.addEventListener('webkitfullscreenchange', updateFsIcon);
  }
})();
</script>

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
// ── Progress tracking for cross-origin iframe ────────────────────────────────
// We can't read the iframe's video.currentTime, so we track visible page time
// as a proxy for how far through the content the user is.
(function () {
  'use strict';

  const PROGRESS_META = <?= json_encode([
    'content_type'   => $type,
    'content_id'     => $id,
    'content_title'  => $title,
    'poster_path'    => $meta['poster_path'] ?? '',
    'season'         => $type === 'tv' ? $season  : 0,
    'episode'        => $type === 'tv' ? $episode : 0,
    'episode_title'  => '',
    'base_url'       => BASE_URL,
  ]) ?>;

  let visibleStart    = Date.now();
  let accumulatedSec  = 0;

  function currentTotal() {
    return accumulatedSec + Math.round((Date.now() - visibleStart) / 1000);
  }

  function saveProgress(isFinal) {
    const totalSec = currentTotal();
    if (totalSec < 10) return; // Ignore accidental brief visits

    const payload = JSON.stringify({
      content_type     : PROGRESS_META.content_type,
      content_id       : PROGRESS_META.content_id,
      content_title    : PROGRESS_META.content_title,
      poster_path      : PROGRESS_META.poster_path,
      season           : PROGRESS_META.season,
      episode          : PROGRESS_META.episode,
      episode_title    : PROGRESS_META.episode_title,
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

  // Accumulate time when the tab goes hidden; reset start when it returns
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
      accumulatedSec += Math.round((Date.now() - visibleStart) / 1000);
      saveProgress(false);
    } else {
      visibleStart = Date.now();
    }
  });

  // Periodic save every 30 s (covers watching without tab switching)
  setInterval(() => saveProgress(false), 30000);

  // Final save when the user navigates away or closes the tab
  window.addEventListener('pagehide', () => saveProgress(true));
})();
</script>

</body>
</html>
