<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/JikanApi.php';

// ── Input validation ──────────────────────────────────────────────────────────
$malId   = intval($_GET['mal_id']  ?? 0);
$episode = intval($_GET['episode'] ?? 1);

if ($malId <= 0 || $episode <= 0) {
    header('Location: ' . BASE_URL . '/anime.php');
    exit;
}

// ── Anime metadata (cached via Jikan) ─────────────────────────────────────────
$jikan = new JikanApi();
$anime = $jikan->animeDetails($malId);

if (empty($anime)) {
    header('Location: ' . BASE_URL . '/anime.php');
    exit;
}

$title        = $anime['title_english'] ?: ($anime['title'] ?? 'Anime');
$episodeCount = (int)($anime['episodes'] ?? 0);
$backUrl      = BASE_URL . '/anime-detail.php?mal_id=' . $malId;

$prevEpisode = $episode > 1              ? $episode - 1 : null;
$nextEpisode = ($episodeCount <= 0 || $episode < $episodeCount) ? $episode + 1 : null;

$streamApiUrl = BASE_URL . '/stream.php?mal_id=' . $malId . '&episode=' . $episode;
$consumetReady = CONSUMET_URL !== '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($title) ?> &ndash; Episode <?= $episode ?> &ndash; <?= e(SITE_NAME) ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
  <style>
    html, body { margin: 0; padding: 0; background: #000; height: 100%; overflow: hidden; }

    .player-page { display: flex; flex-direction: column; height: 100vh; background: #000; }

    /* ── Top bar ── */
    .player-topbar {
      display: flex;
      align-items: center;
      gap: 0.6rem;
      padding: 0 1rem;
      height: 48px;
      flex-shrink: 0;
      background: rgba(0,0,0,0.9);
      z-index: 10;
    }

    .player-back {
      font-size: 1.25rem;
      color: #fff;
      opacity: 0.7;
      text-decoration: none;
      flex-shrink: 0;
      transition: opacity .15s;
    }
    .player-back:hover { opacity: 1; }

    .player-title {
      flex: 1;
      font-size: 0.85rem;
      color: #b3b3b3;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    /* ── Sub / Dub toggle ── */
    .lang-toggle {
      display: flex;
      gap: 0;
      flex-shrink: 0;
      border: 1px solid #444;
      border-radius: 4px;
      overflow: hidden;
    }

    .lang-btn {
      background: #1a1a1a;
      color: #888;
      border: none;
      padding: 4px 12px;
      font-size: 0.75rem;
      font-weight: 700;
      letter-spacing: .5px;
      cursor: pointer;
      transition: background .15s, color .15s;
    }
    .lang-btn + .lang-btn { border-left: 1px solid #444; }
    .lang-btn:hover       { background: #2a2a2a; color: #ccc; }
    .lang-btn.active      { background: #E50914; color: #fff; }
    .lang-btn:disabled    { opacity: .35; cursor: default; }

    /* ── Episode nav ── */
    .ep-nav {
      display: flex;
      gap: 4px;
      flex-shrink: 0;
    }

    .ep-nav a, .ep-nav span {
      display: inline-block;
      padding: 4px 10px;
      font-size: 0.72rem;
      font-weight: 700;
      border-radius: 3px;
      text-decoration: none;
      color: #888;
      background: #1a1a1a;
      border: 1px solid #333;
      transition: background .15s, color .15s;
    }
    .ep-nav a:hover { background: #2a2a2a; color: #ccc; }
    .ep-nav span    { opacity: .3; cursor: default; }

    /* ── Status bar ── */
    .player-status {
      text-align: center;
      font-size: 0.7rem;
      color: #444;
      padding: 3px 0;
      flex-shrink: 0;
      background: #000;
      min-height: 18px;
    }

    /* ── Video area ── */
    .player-frame-wrap {
      flex: 1;
      position: relative;
      background: #000;
    }

    #anime-video {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      background: #000;
    }

    /* ── Loading / error overlay ── */
    .player-overlay {
      position: absolute;
      inset: 0;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      background: #000;
      color: #555;
      font-size: 0.85rem;
      z-index: 5;
      pointer-events: none;
      transition: opacity .3s;
      gap: 12px;
    }
    .player-overlay.hidden { opacity: 0; pointer-events: none; }

    @keyframes spin { to { transform: rotate(360deg); } }
    .spinner {
      width: 32px; height: 32px;
      border: 3px solid #222;
      border-top-color: #E50914;
      border-radius: 50%;
      animation: spin .75s linear infinite;
      flex-shrink: 0;
    }

    .error-msg {
      color: #c0392b;
      text-align: center;
      max-width: 420px;
      line-height: 1.5;
    }
    .error-msg a { color: #E50914; }

    .not-configured {
      color: #888;
      text-align: center;
      max-width: 480px;
      line-height: 1.6;
      font-size: 0.8rem;
    }
    .not-configured code {
      background: #1a1a1a;
      padding: 2px 6px;
      border-radius: 3px;
      color: #ccc;
    }
  </style>
</head>
<body>

<div class="player-page">

  <!-- Top bar -->
  <div class="player-topbar">
    <a class="player-back" href="<?= e($backUrl) ?>" title="Back to anime">&#8592;</a>

    <span class="player-title">
      <?= e($title) ?> &mdash; Episode <?= $episode ?>
    </span>

    <!-- Sub / Dub toggle -->
    <div class="lang-toggle" id="lang-toggle">
      <button class="lang-btn active" id="btn-sub" data-mode="sub">SUB</button>
      <button class="lang-btn"        id="btn-dub" data-mode="dub">DUB</button>
    </div>

    <!-- Episode navigation -->
    <nav class="ep-nav">
      <?php if ($prevEpisode): ?>
        <a href="<?= e(BASE_URL . '/anime-watch.php?mal_id=' . $malId . '&episode=' . $prevEpisode) ?>" title="Previous episode">&#8592; EP<?= $prevEpisode ?></a>
      <?php else: ?>
        <span>&#8592; Prev</span>
      <?php endif; ?>

      <?php if ($nextEpisode): ?>
        <a href="<?= e(BASE_URL . '/anime-watch.php?mal_id=' . $malId . '&episode=' . $nextEpisode) ?>" title="Next episode">EP<?= $nextEpisode ?> &#8594;</a>
      <?php else: ?>
        <span>Next &#8594;</span>
      <?php endif; ?>
    </nav>
  </div>

  <!-- Status bar -->
  <div class="player-status" id="player-status">
    <?= $consumetReady ? 'Resolving stream&hellip;' : 'Consumet not configured' ?>
  </div>

  <!-- Player -->
  <div class="player-frame-wrap">

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

    <video id="anime-video" controls playsinline></video>
  </div>

</div>

<!-- HLS.js — industry standard, MIT licensed -->
<script src="https://cdn.jsdelivr.net/npm/hls.js@1/dist/hls.min.js"></script>

<script>
(function () {
  'use strict';

  const MAL_ID     = <?= $malId ?>;
  const EPISODE    = <?= $episode ?>;
  const STREAM_API = <?= json_encode($streamApiUrl) ?>;
  const READY      = <?= $consumetReady ? 'true' : 'false' ?>;

  if (!READY) return; // Consumet not configured — overlay already shows the message

  const video       = document.getElementById('anime-video');
  const overlay     = document.getElementById('player-overlay');
  const overlayText = document.getElementById('overlay-text');
  const statusEl    = document.getElementById('player-status');
  const langBtns    = Array.from(document.querySelectorAll('.lang-btn'));

  let hls          = null;
  let currentMode  = 'sub';

  // ── Enable the first subtitle track (browsers hide them by default) ──────────
  function enableFirstSubtitleTrack() {
    const tracks = Array.from(video.textTracks);
    if (!tracks.length) return;
    tracks.forEach((t, i) => {
      t.mode = i === 0 ? 'showing' : 'hidden';
    });
  }

  // ── Show/hide overlay ────────────────────────────────────────────────────────
  function showOverlay(text) {
    overlayText.textContent = text || 'Loading\u2026';
    overlay.classList.remove('hidden');
  }

  function hideOverlay() {
    overlay.classList.add('hidden');
  }

  function showError(message, showFallback = false) {
    overlay.classList.remove('hidden');
    overlay.innerHTML = '<div class="error-msg">' + message + '</div>';
  }

  // ── Update lang toggle UI ────────────────────────────────────────────────────
  function setActiveMode(mode) {
    currentMode = mode;
    langBtns.forEach(btn => btn.classList.toggle('active', btn.dataset.mode === mode));
  }

  // ── Load a stream URL into HLS.js ────────────────────────────────────────────
  function loadStream(m3u8Url, refererHeader, subtitles, seekTo = 0) {
    // Destroy any previous HLS instance
    if (hls) {
      hls.destroy();
      hls = null;
    }

    if (Hls.isSupported()) {
      hls = new Hls({
        // Pass the Referer header on every XHR request to the CDN
        xhrSetup: (xhr) => {
          if (refererHeader) {
            // Note: browsers block setting Referer directly — we set the
            // closest permitted alternative instead. Many CDNs accept this.
            xhr.setRequestHeader('X-Forwarded-For', '');
          }
        },
        // Start with reasonable buffer sizes for streaming
        maxBufferLength: 30,
        maxMaxBufferLength: 60,
      });

      hls.loadSource(m3u8Url);
      hls.attachMedia(video);

      hls.on(Hls.Events.MANIFEST_PARSED, () => {
        hideOverlay();
        if (seekTo > 0) {
          video.currentTime = seekTo;
        }
        video.play().catch(() => {
          // Autoplay blocked by browser — user must click play manually, which is fine
        });

        // Auto-enable the first subtitle track on sub mode
        if (currentMode === 'sub') {
          setTimeout(() => enableFirstSubtitleTrack(), 300);
        }
      });

      hls.on(Hls.Events.ERROR, (event, data) => {
        if (data.fatal) {
          showError(
            'Stream error: ' + (data.details || 'unknown') + '<br>' +
            '<small>Try switching between Sub and Dub, or go back and try again.</small>'
          );
        }
      });

    } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
      // Safari — native HLS support
      video.src = m3u8Url;
      video.addEventListener('loadedmetadata', () => {
        hideOverlay();
        if (seekTo > 0) video.currentTime = seekTo;
        video.play().catch(() => {});
        if (currentMode === 'sub') setTimeout(() => enableFirstSubtitleTrack(), 300);
      });
    } else {
      showError('Your browser does not support HLS streaming. Try Chrome, Firefox, or Safari.');
    }

    // Add subtitle tracks if provided
    if (subtitles && subtitles.length) {
      subtitles.forEach((sub) => {
        const lang = (sub.lang || sub.label || 'Unknown').toLowerCase();
        // Skip 'thumbnails' track — that is a sprite sheet, not real subtitles
        if (lang.includes('thumbnail')) return;

        const track    = document.createElement('track');
        track.kind     = 'subtitles';
        track.label    = sub.lang || sub.label || 'Unknown';
        track.srclang  = lang.substring(0, 2);
        track.src      = sub.url;
        // Enable English subtitles by default on sub mode
        if (currentMode === 'sub' && (lang === 'english' || lang === 'en')) {
          track.default = true;
        }
        video.appendChild(track);
      });
    }
  }

  // ── Fetch stream URL from server, then load it ────────────────────────────────
  async function fetchAndLoad(category, seekTo = 0) {
    setActiveMode(category);
    showOverlay('Resolving ' + category.toUpperCase() + ' stream\u2026');
    statusEl.textContent = 'Fetching stream for episode ' + EPISODE + ' (' + category.toUpperCase() + ')\u2026';

    // Remove existing subtitle tracks
    Array.from(video.querySelectorAll('track')).forEach(t => t.remove());

    try {
      const url      = STREAM_API + '&category=' + encodeURIComponent(category);
      const response = await fetch(url);
      const data     = await response.json();

      if (data.error) {
        if (category === 'sub') {
          // Sub not found — nothing to fall back to
          showError(
            '<strong>Stream not found</strong><br>' +
            'Could not find episode ' + EPISODE + ' on any source.<br>' +
            '<small>The anime may not be available yet, or the title did not match.</small>'
          );
          statusEl.textContent = 'Stream not found.';
        } else {
          // Dub not available — automatically fall back to sub
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

  // ── Sub / Dub toggle ──────────────────────────────────────────────────────────
  langBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      const newMode = btn.dataset.mode;
      if (newMode === currentMode) return;

      // Save playback position before switching
      const savedTime = video.currentTime || 0;
      fetchAndLoad(newMode, savedTime);
    });
  });

  // ── Initial load ──────────────────────────────────────────────────────────────
  fetchAndLoad('sub');

})();
</script>

</body>
</html>
