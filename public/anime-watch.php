<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Auth.php';
requireLogin();
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/JikanApi.php';

// ── Input validation ──────────────────────────────────────────────────────────
$malId   = intval($_GET['mal_id']  ?? 0);
$episode = intval($_GET['episode'] ?? 1);

if ($malId <= 0 || $episode <= 0) {
    header('Location: ' . BASE_URL . '/anime.php');
    exit;
}

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
// Each MAL entry = one season. No offset calculation needed.
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

// ── Stream API URL — no season/offset needed, each MAL entry = 1 season ──────
$streamApiUrl  = BASE_URL . '/stream.php?mal_id=' . $malId . '&episode=' . $episode;
$consumetReady = CONSUMET_URL !== '';
$backUrl       = BASE_URL . '/anime-detail.php?mal_id=' . $malId;
$today         = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($title) ?> &ndash; Episode <?= $episode ?> &ndash; <?= e(SITE_NAME) ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/player.css?v=<?= filemtime(__DIR__ . '/assets/css/player.css') ?>">
</head>
<body>

<div class="player-page">

  <!-- Top bar -->
  <div class="player-topbar">
    <a class="player-back" href="<?= e($backUrl) ?>" title="Back to anime">&#8592;</a>

    <span class="player-title">
      <?= e($title) ?>
      <?php if (count($sequelChain) > 1): ?>
        &mdash; S<?= $currentChainIdx + 1 ?>
      <?php endif; ?>
      &mdash; Ep.<?= $episode ?>
    </span>

    <div class="player-topbar-break" aria-hidden="true"></div>

    <!-- Sub / Dub toggle -->
    <div class="lang-toggle" id="lang-toggle">
      <button class="lang-btn active" id="btn-sub" data-mode="sub">SUB</button>
      <button class="lang-btn"        id="btn-dub" data-mode="dub">DUB</button>
    </div>

    <!-- Episode navigation -->
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

    <button class="ep-toggle-btn" id="ep-panel-toggle" title="Episode list">&#9776; Episodes</button>
    <button class="topbar-fs-btn" id="topbar-fs-btn" title="Fullscreen">&#x26F6;</button>
  </div>

  <!-- Status bar -->
  <div class="player-status" id="player-status">
    <?= $consumetReady ? 'Resolving stream&hellip;' : 'Consumet not configured' ?>
  </div>

  <!-- Player -->
  <div class="player-frame-wrap" id="player-wrap">

    <div class="player-overlay" id="player-overlay">
      <?php if (!$consumetReady): ?>
        <div class="not-configured">
          <strong style="color:#E50914;font-size:1rem;">Consumet API not configured</strong><br><br>
          Deploy a free Consumet instance on Vercel, then add<br>
          <code>CONSUMET_URL=https://your-app.vercel.app</code><br>
          to your <code>.env</code> file.
        </div>
      <?php else: ?>
        <div class="spinner"></div>
        <span id="overlay-text">Resolving stream&hellip;</span>
      <?php endif; ?>
    </div>

    <video id="anime-video" playsinline muted></video>

    <div class="center-play" id="center-play">
      <svg id="center-play-icon" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
    </div>

    <!-- iOS unmute prompt — shown when iOS blocks programmatic unmute -->
    <div class="unmute-prompt" id="unmute-prompt" style="display:none">
      <button class="unmute-btn" id="unmute-btn">&#128266; Tap to unmute</button>
    </div>

    <!-- Double-tap seek indicators -->
    <div class="seek-indicator seek-indicator--left"  id="seek-left"  aria-hidden="true">&#9664;&#9664; 10s</div>
    <div class="seek-indicator seek-indicator--right" id="seek-right" aria-hidden="true">10s &#9654;&#9654;</div>

    <!-- Custom controls -->
    <div class="custom-controls" id="custom-controls">
      <div class="progress-row">
        <div class="progress-wrap" id="progress-wrap">
          <div class="progress-buf"   id="progress-buf"   style="width:0%"></div>
          <div class="progress-fill"  id="progress-fill"  style="width:0%"></div>
          <div class="progress-thumb" id="progress-thumb" style="left:0%"></div>
        </div>
        <span class="time-display" id="time-display">0:00 / 0:00</span>
      </div>
      <div class="controls-row">
        <button class="ctrl-btn" id="btn-play" title="Play / Pause (Space)">
          <svg id="play-icon" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
        </button>
        <button class="ctrl-btn" id="btn-back" title="Back 10s">
          <svg viewBox="0 0 24 24"><path d="M11.99 5V1l-5 5 5 5V7c3.31 0 6 2.69 6 6s-2.69 6-6 6-6-2.69-6-6h-2c0 4.42 3.58 8 8 8s8-3.58 8-8-3.58-8-8-8z"/><text x="8.5" y="15.5" font-size="5" font-family="sans-serif" fill="currentColor">10</text></svg>
        </button>
        <button class="ctrl-btn" id="btn-fwd" title="Forward 10s">
          <svg viewBox="0 0 24 24"><path d="M12.01 5V1l5 5-5 5V7c-3.31 0-6 2.69-6 6s2.69 6 6 6 6-2.69 6-6h2c0 4.42-3.58 8-8 8s-8-3.58-8-8 3.58-8 8-8z"/><text x="8.5" y="15.5" font-size="5" font-family="sans-serif" fill="currentColor">10</text></svg>
        </button>
        <div class="volume-group">
          <button class="ctrl-btn" id="btn-mute" title="Mute / Unmute (M)">
            <svg id="vol-icon" viewBox="0 0 24 24"><path id="vol-path" d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/></svg>
          </button>
          <div class="volume-slider-wrap">
            <input class="volume-slider" id="volume-slider" type="range" min="0" max="1" step="0.02" value="1">
          </div>
        </div>
        <div class="ctrl-spacer"></div>
        <div class="sub-group" id="sub-group">
          <button class="ctrl-btn" id="btn-subs" title="Subtitles">
            <svg viewBox="0 0 24 24"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zM4 12h2v2H4v-2zm10 6H4v-2h10v2zm6 0h-4v-2h4v2zm0-4H10v-2h10v2z"/></svg>
          </button>
          <div class="sub-dropdown" id="sub-dropdown"></div>
        </div>
        <button class="ctrl-btn" id="btn-fs" title="Fullscreen (F)">
          <svg id="fs-icon" viewBox="0 0 24 24"><path d="M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z"/></svg>
        </button>
      </div>
    </div>

  </div><!-- /player-frame-wrap -->

</div><!-- /player-page -->

<!-- ── Episode panel ──────────────────────────────────────────────────────── -->
<div class="ep-panel" id="ep-panel">
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

    <button class="ep-panel__close" id="ep-panel-close" title="Close">&#10005;</button>
  </div>

  <div class="ep-panel__list" id="ep-panel-list">
    <?php if (!empty($panelEpisodes)): ?>
      <?php foreach ($panelEpisodes as $ep): ?>
        <?php
          $epN        = (int)($ep['mal_id'] ?? 0);
          $epT        = $ep['title'] ?? 'Episode ' . $epN;
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

<!-- HLS.js -->
<script src="https://cdn.jsdelivr.net/npm/hls.js@1/dist/hls.min.js"></script>

<script>
(function () {
  'use strict';

  const MAL_ID     = <?= $malId ?>;
  const EPISODE    = <?= $episode ?>;
  const STREAM_API = <?= json_encode($streamApiUrl) ?>;
  const READY      = <?= $consumetReady ? 'true' : 'false' ?>;

  if (!READY) return;

  const video       = document.getElementById('anime-video');
  const wrap        = document.getElementById('player-wrap');
  const overlay     = document.getElementById('player-overlay');
  const overlayText = document.getElementById('overlay-text');
  const statusEl    = document.getElementById('player-status');
  const langBtns    = Array.from(document.querySelectorAll('.lang-btn'));

  const btnPlay      = document.getElementById('btn-play');
  const playIcon     = document.getElementById('play-icon');
  const btnBack      = document.getElementById('btn-back');
  const btnFwd       = document.getElementById('btn-fwd');
  const btnMute      = document.getElementById('btn-mute');
  const volIcon      = document.getElementById('vol-icon');
  const volSlider    = document.getElementById('volume-slider');
  const progressWrap = document.getElementById('progress-wrap');
  const progressFill = document.getElementById('progress-fill');
  const progressBuf  = document.getElementById('progress-buf');
  const progressThumb= document.getElementById('progress-thumb');
  const timeDisplay  = document.getElementById('time-display');
  const btnFs        = document.getElementById('btn-fs');
  const fsIcon       = document.getElementById('fs-icon');
  const centerPlay   = document.getElementById('center-play');
  const centerIcon   = document.getElementById('center-play-icon');
  const btnSubs      = document.getElementById('btn-subs');
  const subDropdown  = document.getElementById('sub-dropdown');
  const unmutePrompt = document.getElementById('unmute-prompt');
  const unmuteBtn    = document.getElementById('unmute-btn');

  // ── iOS unmute: attach once at module scope so it works across stream reloads
  if (unmuteBtn) {
    unmuteBtn.addEventListener('click', () => {
      video.muted = false; // direct user gesture — iOS will honour this
      updateVolIcon();
      if (unmutePrompt) unmutePrompt.style.display = 'none';
    });
  }

  let hls         = null;
  let currentMode = 'sub';

  // ── Persist sub/dub preference across episodes ────────────────────────────
  const PREF_KEY = 'anime_lang_pref';
  function savePref(mode)   { try { localStorage.setItem(PREF_KEY, mode); } catch(_) {} }
  function loadPref()       { try { return localStorage.getItem(PREF_KEY) || 'sub'; } catch(_) { return 'sub'; } }

  // ── Mobile: overlay UI + auto-fullscreen ──────────────────────────────────
  const isMobile  = window.matchMedia('(max-width: 640px)').matches;
  const playerPage = document.querySelector('.player-page');
  // iOS Safari does not support document.fullscreenEnabled / requestFullscreen;
  // it only exposes video.webkitEnterFullscreen on <video> elements.
  const isIOS = !document.fullscreenEnabled &&
                typeof video.webkitEnterFullscreen === 'function';
  let fsTriggered = false;

  function enterFullscreen() {
    if (fsTriggered) return;
    fsTriggered = true;
    if (isIOS) {
      if (video.webkitEnterFullscreen) video.webkitEnterFullscreen();
      return;
    }
    const el = document.documentElement;
    const req = el.requestFullscreen || el.webkitRequestFullscreen;
    if (req) req.call(el).catch(() => {});
  }

  let hideTimer = null;
  function showControls() {
    wrap.classList.add('controls-visible');
    if (isMobile && playerPage) playerPage.classList.remove('ui-hidden');
    clearTimeout(hideTimer);
    if (!video.paused) {
      hideTimer = setTimeout(() => {
        wrap.classList.remove('controls-visible');
        if (isMobile && playerPage) playerPage.classList.add('ui-hidden');
      }, 3000);
    }
  }
  // ── Double-tap to seek ────────────────────────────────────────────────────
  const seekLeftEl  = document.getElementById('seek-left');
  const seekRightEl = document.getElementById('seek-right');
  let lastTapTime   = 0;

  function flashSeekIndicator(el) {
    if (!el) return;
    el.classList.remove('flash');
    void el.offsetWidth; // force reflow so re-triggering restarts the transition
    el.classList.add('flash');
    setTimeout(() => el.classList.remove('flash'), 600);
  }

  wrap.addEventListener('mousemove',  showControls);
  wrap.addEventListener('touchstart', (e) => {
    const now    = Date.now();
    const target = e.target;

    // Don't intercept taps on controls, dropdowns, or links
    if (target.closest('button, a, select, input, .sub-dropdown')) {
      showControls();
      return;
    }

    const dt   = now - lastTapTime;
    lastTapTime = now;

    if (dt < 300 && dt > 0) {
      // Double-tap detected — seek instead of toggling controls
      const rect = wrap.getBoundingClientRect();
      const tapX = e.touches[0].clientX - rect.left;
      if (tapX < rect.width / 2) {
        video.currentTime = Math.max(0, video.currentTime - 10);
        flashSeekIndicator(seekLeftEl);
      } else {
        video.currentTime += 10;
        flashSeekIndicator(seekRightEl);
      }
      lastTapTime = 0; // reset so a third tap doesn't double-seek
      showControls();  // keep controls visible after seeking
      return;
    }

    // Single tap: enter fullscreen on first touch, then show controls
    enterFullscreen();
    showControls();
  }, { passive: true });
  wrap.addEventListener('mouseleave', () => {
    if (!video.paused) {
      clearTimeout(hideTimer);
      wrap.classList.remove('controls-visible');
      if (isMobile && playerPage) playerPage.classList.add('ui-hidden');
    }
  });

  // On mobile: start with UI visible briefly, then auto-hide when playing
  if (isMobile && playerPage) {
    showControls(); // show on load
  }

  function fmt(s) {
    if (!isFinite(s)) return '0:00';
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const sec = Math.floor(s % 60);
    return (h ? h + ':' + String(m).padStart(2,'0') : m) + ':' + String(sec).padStart(2,'0');
  }

  const PLAY_PATH  = 'M8 5v14l11-7z';
  const PAUSE_PATH = 'M6 19h4V5H6v14zm8-14v14h4V5h-4z';

  function updatePlayIcon() {
    const path = video.paused ? PLAY_PATH : PAUSE_PATH;
    playIcon.innerHTML  = '<path d="' + path + '"/>';
    centerIcon.innerHTML= '<path d="' + path + '"/>';
  }

  function flashCenter() {
    centerPlay.classList.remove('flash');
    void centerPlay.offsetWidth;
    centerPlay.classList.add('flash');
    setTimeout(() => centerPlay.classList.remove('flash'), 400);
  }

  function togglePlay() {
    if (video.paused) { video.play(); } else { video.pause(); }
    flashCenter();
  }

  btnPlay.addEventListener('click', togglePlay);
  video.addEventListener('click', togglePlay);
  video.addEventListener('play',  updatePlayIcon);
  video.addEventListener('pause', () => { updatePlayIcon(); showControls(); });

  function updateProgress() {
    if (!isFinite(video.duration)) return;
    const pct = (video.currentTime / video.duration) * 100;
    progressFill.style.width  = pct + '%';
    progressThumb.style.left  = pct + '%';
    timeDisplay.textContent   = fmt(video.currentTime) + ' / ' + fmt(video.duration);
    if (video.buffered.length) {
      const bufEnd = video.buffered.end(video.buffered.length - 1);
      progressBuf.style.width = (bufEnd / video.duration * 100) + '%';
    }
  }

  video.addEventListener('timeupdate', updateProgress);
  video.addEventListener('progress',   updateProgress);

  function seekFromEvent(e) {
    if (!isFinite(video.duration)) return;
    const rect = progressWrap.getBoundingClientRect();
    const x    = Math.max(0, Math.min(e.clientX - rect.left, rect.width));
    video.currentTime = (x / rect.width) * video.duration;
  }

  let seeking = false;
  progressWrap.addEventListener('mousedown', (e) => { seeking = true; seekFromEvent(e); });
  document.addEventListener('mousemove',     (e) => { if (seeking) seekFromEvent(e); });
  document.addEventListener('mouseup',       ()  => { seeking = false; });
  progressWrap.addEventListener('touchstart', (e) => { seeking = true; seekFromEvent(e.touches[0]); e.preventDefault(); }, { passive: false });
  progressWrap.addEventListener('touchmove',  (e) => { if (seeking) { seekFromEvent(e.touches[0]); e.preventDefault(); } }, { passive: false });
  progressWrap.addEventListener('touchend', () => { seeking = false; });

  btnBack.addEventListener('click', () => { video.currentTime = Math.max(0, video.currentTime - 10); });
  btnFwd.addEventListener('click',  () => { video.currentTime += 10; });

  const VOL_HIGH = 'M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z';
  const VOL_LOW  = 'M18.5 12c0-1.77-1.02-3.29-2.5-4.03v2.21l2.45 2.45c.03-.2.05-.41.05-.63zm2.5 0c0 .94-.2 1.82-.54 2.64l1.51 1.51C20.63 14.91 21 13.5 21 12c0-4.28-2.99-7.86-7-8.77v2.06c2.89.86 5 3.54 5 6.71zM4.27 3L3 4.27 7.73 9H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.18v2.06c1.38-.31 2.63-.95 3.69-1.81L19.73 21 21 19.73l-9-9L4.27 3zM12 4L9.91 6.09 12 8.18V4z';
  const VOL_MUTE = 'M16.5 12c0-1.77-1.02-3.29-2.5-4.03v2.21l2.45 2.45c.03-.2.05-.41.05-.63zm2.5 0c0 .94-.2 1.82-.54 2.64l1.51 1.51C20.63 14.91 21 13.5 21 12c0-4.28-2.99-7.86-7-8.77v2.06c2.89.86 5 3.54 5 6.71zM4.27 3L3 4.27 7.73 9H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.18v2.06c1.38-.31 2.63-.95 3.69-1.81L19.73 21 21 19.73l-9-9L4.27 3zM12 4L9.91 6.09 12 8.18V4z';

  function updateVolIcon() {
    const v    = video.volume;
    const muted= video.muted || v === 0;
    volIcon.innerHTML = '<path d="' + (muted ? VOL_MUTE : v < 0.5 ? VOL_LOW : VOL_HIGH) + '"/>';
    volSlider.value = muted ? 0 : v;
  }

  btnMute.addEventListener('click', () => { video.muted = !video.muted; updateVolIcon(); });
  volSlider.addEventListener('input', () => {
    video.volume = parseFloat(volSlider.value);
    video.muted  = video.volume === 0;
    updateVolIcon();
  });
  video.addEventListener('volumechange', updateVolIcon);

  const FS_ENTER = 'M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z';
  const FS_EXIT  = 'M5 16h3v3h2v-5H5v2zm3-8H5v2h5V5H8v3zm6 11h2v-3h3v-2h-5v5zm2-11V5h-2v5h5V8h-3z';

  function updateFsIcon() {
    const inFs = !!(document.fullscreenElement || document.webkitFullscreenElement ||
                    (isIOS && video.webkitDisplayingFullscreen));
    fsIcon.innerHTML = '<path d="' + (inFs ? FS_EXIT : FS_ENTER) + '"/>';
  }

  function toggleFullscreen() {
    if (isIOS) {
      // iOS Safari: use the video element's own fullscreen API
      if (video.webkitDisplayingFullscreen) {
        video.webkitExitFullscreen();
      } else {
        video.webkitEnterFullscreen();
      }
      return;
    }
    if (!document.fullscreenElement && !document.webkitFullscreenElement) {
      // On mobile fullscreen the whole page so our overlay UI is included;
      // on desktop fullscreen just the frame-wrap for a cleaner cinema view.
      const el = isMobile ? document.documentElement : wrap;
      const req = el.requestFullscreen || el.webkitRequestFullscreen;
      if (req) req.call(el).catch(() => {});
    } else {
      (document.exitFullscreen || document.webkitExitFullscreen).call(document);
    }
  }

  btnFs.addEventListener('click', toggleFullscreen);

  // Topbar fullscreen button — same action, always visible
  const topbarFsBtn = document.getElementById('topbar-fs-btn');
  if (topbarFsBtn) {
    topbarFsBtn.addEventListener('click', toggleFullscreen);
  }

  function updateAllFsIcons() {
    updateFsIcon();
    if (topbarFsBtn) {
      const inFs = !!(document.fullscreenElement || document.webkitFullscreenElement ||
                      (isIOS && video.webkitDisplayingFullscreen));
      topbarFsBtn.innerHTML = inFs ? '&#x2715;' : '&#x26F6;';
      topbarFsBtn.title = inFs ? 'Exit fullscreen' : 'Fullscreen';
    }
  }

  document.addEventListener('fullscreenchange',       updateAllFsIcons);
  document.addEventListener('webkitfullscreenchange', updateAllFsIcons);
  // iOS fires these on the video element instead of the document
  video.addEventListener('webkitbeginfullscreen', updateAllFsIcons);
  video.addEventListener('webkitendfullscreen',   updateAllFsIcons);

  let availableSubtitles = [];
  let cachedSubtitles    = []; // subtitles from the sub stream, reused for dub

  function buildSubMenu() {
    subDropdown.innerHTML = '';
    if (!availableSubtitles.length) {
      subDropdown.innerHTML = '<button disabled style="opacity:.5">No subtitles</button>';
      return;
    }
    const offBtn = document.createElement('button');
    offBtn.textContent = 'Off';
    offBtn.addEventListener('click', () => {
      Array.from(video.textTracks).forEach(t => t.mode = 'hidden');
      subDropdown.querySelectorAll('button').forEach(b => b.classList.remove('active'));
      offBtn.classList.add('active');
      subDropdown.classList.remove('open');
    });
    subDropdown.appendChild(offBtn);

    availableSubtitles.forEach((sub, i) => {
      const label = sub.lang || sub.label || 'Track ' + (i + 1);
      const btn   = document.createElement('button');
      btn.textContent = label;
      if (currentMode === 'sub') btn.classList.add('active'); // applySubtitleMode will adjust
      btn.addEventListener('click', () => {
        Array.from(video.textTracks).forEach((t, ti) => { t.mode = ti === i ? 'showing' : 'hidden'; });
        subDropdown.querySelectorAll('button').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        subDropdown.classList.remove('open');
      });
      subDropdown.appendChild(btn);
    });
  }

  btnSubs.addEventListener('click', (e) => { e.stopPropagation(); subDropdown.classList.toggle('open'); });
  document.addEventListener('click', () => subDropdown.classList.remove('open'));

  document.addEventListener('keydown', (e) => {
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT') return;
    switch (e.code) {
      case 'Space':      e.preventDefault(); togglePlay(); break;
      case 'ArrowLeft':  video.currentTime = Math.max(0, video.currentTime - 10); break;
      case 'ArrowRight': video.currentTime += 10; break;
      case 'ArrowUp':    video.volume = Math.min(1, video.volume + 0.1); updateVolIcon(); break;
      case 'ArrowDown':  video.volume = Math.max(0, video.volume - 0.1); updateVolIcon(); break;
      case 'KeyM':       video.muted = !video.muted; updateVolIcon(); break;
      case 'KeyF':       btnFs.click(); break;
    }
    showControls();
  });

  function showOverlay(text) { overlayText.textContent = text || 'Loading\u2026'; overlay.classList.remove('hidden'); }
  function hideOverlay()     { overlay.classList.add('hidden'); }
  function showError(msg)    { overlay.classList.remove('hidden'); overlay.innerHTML = '<div class="error-msg">' + msg + '</div>'; }

  function setActiveMode(mode) {
    currentMode = mode;
    langBtns.forEach(btn => btn.classList.toggle('active', btn.dataset.mode === mode));
  }

  function applySubtitleMode() {
    const tracks = Array.from(video.textTracks);
    if (!tracks.length) return;

    if (currentMode === 'dub') {
      // DUB: hide all tracks by default — user can still pick one from the menu
      tracks.forEach(t => t.mode = 'hidden');
      return;
    }

    // SUB: prefer English track, fall back to first track
    const preferred = tracks.find(t => t.language.startsWith('en') || t.label.toLowerCase().includes('english'));
    const target    = preferred || tracks[0];
    tracks.forEach(t => t.mode = t === target ? 'showing' : 'hidden');

    // Highlight the matching button in the sub menu
    const items = Array.from(subDropdown.querySelectorAll('button'));
    items.forEach(b => b.classList.remove('active'));
    const activeLabel = target.label.toLowerCase();
    const match = items.find(b => b.textContent.trim().toLowerCase() === activeLabel);
    if (match) match.classList.add('active');
  }

  function loadStream(m3u8Url, refererHeader, subtitles, seekTo = 0) {
    if (hls) { hls.destroy(); hls = null; }

    const filtered = (subtitles || []).filter(s => {
      const l = (s.lang || s.label || '').toLowerCase();
      return !l.includes('thumbnail');
    });

    // Always use sub-stream subtitles if available; reuse cached ones for dub
    if (filtered.length > 0) {
      availableSubtitles = filtered;
      cachedSubtitles    = filtered;
    } else {
      availableSubtitles = cachedSubtitles;
    }

    function onReady() {
      hideOverlay();
      if (unmutePrompt) unmutePrompt.style.display = 'none';
      if (seekTo > 0) video.currentTime = seekTo;
      // Start muted so browsers allow autoplay, then restore sound immediately
      video.muted = true;
      video.play().then(() => {
        video.muted = false;
        updateVolIcon();
        // On iOS, video.muted = false inside .then() is blocked (not a user gesture).
        // After a short settle, check if the browser kept it muted and show a prompt.
        setTimeout(() => {
          if (video.muted && unmutePrompt) unmutePrompt.style.display = 'block';
        }, 200);
      }).catch(() => {
        // Muted autoplay also failed — show play button, user will click
        video.muted = false;
        updateVolIcon();
      });
      buildSubMenu();
      setTimeout(applySubtitleMode, 300);
    }

    if (Hls.isSupported()) {
      hls = new Hls({ maxBufferLength: 30, maxMaxBufferLength: 60 });
      hls.loadSource(m3u8Url);
      hls.attachMedia(video);
      hls.on(Hls.Events.MANIFEST_PARSED, onReady);
      hls.on(Hls.Events.ERROR, (event, data) => {
        if (data.fatal) showError('Stream error: ' + (data.details || 'unknown') + '<br><small>Try switching Sub / Dub or go back.</small>');
      });
    } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
      video.src = m3u8Url;
      video.addEventListener('loadedmetadata', onReady, { once: true });
    } else {
      showError('Your browser does not support HLS streaming.');
      return;
    }

    availableSubtitles.forEach((sub, i) => {
      const lang  = (sub.lang || sub.label || 'Unknown').toLowerCase();
      const track = document.createElement('track');
      track.kind    = 'subtitles';
      track.label   = sub.lang || sub.label || 'Unknown';
      track.srclang = lang.substring(0, 2);
      track.src     = sub.url;
      video.appendChild(track);
    });
  }

  async function fetchAndLoad(category, seekTo = 0) {
    if (unmutePrompt) unmutePrompt.style.display = 'none';
    setActiveMode(category);
    savePref(category);
    showOverlay('Resolving ' + category.toUpperCase() + ' stream\u2026');
    statusEl.textContent = 'Fetching stream for episode ' + EPISODE + ' (' + category.toUpperCase() + ')\u2026';
    Array.from(video.querySelectorAll('track')).forEach(t => t.remove());

    try {
      const res  = await fetch(STREAM_API + '&category=' + encodeURIComponent(category));
      const data = await res.json();

      if (data.error) {
        if (category === 'sub') {
          showError('<strong>Stream not found</strong><br>Could not find episode ' + EPISODE + '.<br><small>Try again later or go back.</small>');
          statusEl.textContent = 'Stream not found.';
        } else {
          statusEl.textContent = 'Dub not available, falling back to Sub\u2026';
          fetchAndLoad('sub', seekTo);
        }
        return;
      }

      statusEl.textContent = 'Playing Episode ' + EPISODE + ' \u00b7 ' + data.category.toUpperCase();
      loadStream(data.m3u8, data.headers?.Referer || null, data.subtitles || [], seekTo);

    } catch (err) {
      showError('Network error: could not reach the stream API.<br><small>' + err.message + '</small>');
      statusEl.textContent = 'Error reaching stream API.';
    }
  }

  langBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      const newMode = btn.dataset.mode;
      if (newMode === currentMode) return;
      fetchAndLoad(newMode, video.currentTime || 0);
    });
  });

  showControls();
  fetchAndLoad(loadPref());

})();
</script>

<script>
(function () {
  'use strict';

  const panel     = document.getElementById('ep-panel');
  const toggleBtn = document.getElementById('ep-panel-toggle');
  const closeBtn  = document.getElementById('ep-panel-close');
  const seasonSel = document.getElementById('ep-panel-season');
  const wrap      = document.getElementById('player-wrap');

  function openPanel()  { panel.classList.add('open'); toggleBtn.classList.add('active'); wrap.classList.add('panel-open'); }
  function closePanel() { panel.classList.remove('open'); toggleBtn.classList.remove('active'); wrap.classList.remove('panel-open'); }

  toggleBtn.addEventListener('click', () => panel.classList.contains('open') ? closePanel() : openPanel());
  closeBtn.addEventListener('click', closePanel);
  wrap.addEventListener('click', (e) => {
    if (panel.classList.contains('open') && !panel.contains(e.target)) closePanel();
  });

  // Season change → navigate to S<N>E1 using the MAL ID stored in option value
  if (seasonSel) {
    seasonSel.addEventListener('change', () => {
      if (seasonSel.value) window.location.href = seasonSel.value;
    });
  }

  toggleBtn.addEventListener('click', () => {
    if (panel.classList.contains('open')) {
      const cur = document.querySelector('.ep-panel__item--current');
      if (cur) setTimeout(() => cur.scrollIntoView({ block: 'center', behavior: 'smooth' }), 50);
    }
  });
})();
</script>

</body>
</html>
