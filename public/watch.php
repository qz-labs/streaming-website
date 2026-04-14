<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Auth.php';
requireLogin();
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/TmdbApi.php';

// ── Input validation ──────────────────────────────────────────────────────────
$type    = $_GET['type'] ?? '';
$id      = intval($_GET['id']  ?? 0);
$season  = intval($_GET['s']   ?? 1);
$episode = intval($_GET['e']   ?? 1);

if (!in_array($type, ['movie', 'tv'], true) || $id <= 0) {
    header('Location: ' . BASE_URL . '/');
    exit;
}
if ($type === 'tv' && ($season <= 0 || $episode <= 0)) {
    header('Location: ' . BASE_URL . '/');
    exit;
}

// ── Metadata (cached) ─────────────────────────────────────────────────────────
$api  = new TmdbApi();
$meta = ($type === 'movie') ? $api->movieDetails($id) : $api->tvDetails($id);

$title     = $meta['title'] ?? $meta['name'] ?? 'Streaming';
$origLang  = $meta['original_language'] ?? 'en';  // e.g. "ja" for anime

// Human-readable label for the original language (shown on the SUB button)
$langNames = [
    'ja' => 'JPN', 'ko' => 'KOR', 'zh' => 'CHN', 'fr' => 'FRA',
    'de' => 'DEU', 'es' => 'SPA', 'it' => 'ITA', 'pt' => 'POR',
    'ru' => 'RUS', 'ar' => 'ARA', 'hi' => 'HIN', 'tr' => 'TUR',
];
$origLangLabel = strtoupper($langNames[$origLang] ?? $origLang);
$isEnglish     = ($origLang === 'en');

$backUrl  = ($type === 'movie')
    ? BASE_URL . '/movie.php?id=' . $id
    : BASE_URL . '/tv.php?id=' . $id . '&season=' . $season;

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

// ── Build embed URL sets for both modes ───────────────────────────────────────
// Sub  = ds_lang={origLang}  → original audio + subtitles  (vidsrc.me family)
// Dub  = ds_lang=en          → English dubbed audio         (vidsrc.me family)
// Extra providers (vidsrc.cc, vidsrc.mov) have same URL for both modes.
// Each entry: { url, label, ping }
$urlSets       = vidsrcAllUrls($type, $id, $season, $episode, $origLang);
$subSources    = $urlSets['sub'];
$dubSources    = $urlSets['dub'];

$subSourcesJson  = json_encode($subSources);
$dubSourcesJson  = json_encode($dubSources);
$isEnglishJson   = $isEnglish ? 'true' : 'false';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $pageTitle ?> &ndash; <?= e(SITE_NAME) ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/player.css?v=<?= filemtime(__DIR__ . '/assets/css/player.css') ?>">
</head>
<body>

<div class="player-page">

  <!-- Top bar -->
  <div class="player-topbar">
    <a class="player-back" href="<?= e($backUrl) ?>" title="Go back">&#8592;</a>

    <span class="player-title">
      <?= e($title) ?>
      <?php if ($type === 'tv'): ?>
        &mdash; S<?= $season ?>E<?= $episode ?>
      <?php endif; ?>
    </span>

    <!-- Mobile row break: controls go to second line -->
    <div class="player-topbar-break" aria-hidden="true"></div>

    <!-- Sub / Dub language toggle -->
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
        title="English<?= $isEnglish ? ' (original)' : ' dubbed audio' ?>"
      >ENG</button>
    </div>

    <!-- Source fallback buttons — generated from subSources (labels are stable) -->
    <div class="source-btns" id="source-btns">
      <?php foreach ($subSources as $i => $src): ?>
        <button
          class="src-btn<?= $i === 0 ? ' active' : '' ?> checking"
          data-index="<?= $i ?>"
          title="<?= e($src['label']) ?>"
        ><?= e($src['label']) ?></button>
      <?php endforeach; ?>
    </div>

    <?php if ($type === 'tv'): ?>
    <!-- Episode navigation -->
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
    <button class="ep-toggle-btn" id="ep-panel-toggle" title="Episode list">&#9776; Episodes</button>
    <?php endif; ?>
    <button class="topbar-fs-btn" id="topbar-fs-btn" title="Fullscreen">&#x26F6;</button>
  </div>

  <!-- Status line -->
  <div class="player-status" id="player-status">Checking sources&hellip;</div>

  <!-- Player -->
  <div class="player-frame-wrap">
    <div class="player-loading" id="player-loading">
      <div class="spinner"></div> Loading&hellip;
    </div>
    <iframe
      id="player-iframe"
      src="about:blank"
      frameborder="0"
      allowfullscreen
      referrerpolicy="origin"
      allow="autoplay; fullscreen; picture-in-picture; encrypted-media"
      scrolling="no"
    ></iframe>
  </div>

</div>

<?php if ($type === 'tv'): ?>
<!-- ── TV Episode panel ──────────────────────────────────────────────────────── -->
<div class="ep-panel" id="ep-panel">
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
    <button class="ep-panel__close" id="ep-panel-close" title="Close">&#10005;</button>
  </div>
  <div class="ep-panel__list" id="ep-panel-list">
    <?php if (!empty($tvEpisodes)): ?>
      <?php foreach ($tvEpisodes as $ep): ?>
        <?php
          $epN     = (int)($ep['episode_number'] ?? 0);
          $epT     = $ep['name'] ?? 'Episode ' . $epN;
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

<script>
(function () {
  'use strict';
  const panel     = document.getElementById('ep-panel');
  const toggleBtn = document.getElementById('ep-panel-toggle');
  const closeBtn  = document.getElementById('ep-panel-close');
  const seasonSel = document.getElementById('ep-panel-season');
  const frameWrap = document.querySelector('.player-frame-wrap');
  if (!panel || !toggleBtn) return;

  function openPanel()  {
    panel.classList.add('open');
    toggleBtn.classList.add('active');
    if (frameWrap) frameWrap.classList.add('panel-open');
    const cur = document.querySelector('.ep-panel__item--current');
    if (cur) setTimeout(() => cur.scrollIntoView({ block: 'center', behavior: 'smooth' }), 50);
  }
  function closePanel() {
    panel.classList.remove('open');
    toggleBtn.classList.remove('active');
    if (frameWrap) frameWrap.classList.remove('panel-open');
  }

  toggleBtn.addEventListener('click', () => panel.classList.contains('open') ? closePanel() : openPanel());
  if (closeBtn) closeBtn.addEventListener('click', closePanel);

  if (seasonSel) {
    seasonSel.addEventListener('change', () => { if (seasonSel.value) window.location.href = seasonSel.value; });
  }
})();
</script>
<?php endif; ?>

<script>
(function () {
  'use strict';

  // Each source is { url, label, ping }
  // vidsrc.me family (S1-S7): different sub/dub URLs via ds_lang
  // Extra providers (S8+):   same URL for both modes (no lang param support)
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

  // ── Load a specific source ─────────────────────────────────────────────────
  function loadSource(idx, mode) {
    mode        = mode || currentMode;
    currentIdx  = idx;
    currentMode = mode;

    const sources = sourcesForMode(mode);
    const src     = sources[idx];

    srcBtns.forEach((b, i) => b.classList.toggle('active', i === idx));
    langBtns.forEach(b => b.classList.toggle('active', b.dataset.mode === mode));

    loading.classList.remove('hidden');
    // Use about:blank + small delay instead of '' so browsers don't
    // navigate to the current page URL, which would break the autoplay
    // user-interaction context.
    iframe.src = 'about:blank';
    setTimeout(() => { iframe.src = src.url; }, 50);

    const modeLabel = mode === 'sub' ? 'Original audio' : 'English (DUB)';
    status.textContent = src.label + ' · ' + modeLabel + ' · ' + domainOf(src.url) + '…';

    iframe.onload = () => {
      loading.classList.add('hidden');
      status.textContent = src.label + ' · ' + modeLabel + ' via ' + domainOf(src.url);
    };
  }

  // ── Ping a source URL (no-cors HEAD, 4s timeout) ──────────────────────────
  function ping(pingUrl) {
    return new Promise((resolve) => {
      const ctrl  = new AbortController();
      const timer = setTimeout(() => { ctrl.abort(); resolve(false); }, 4000);
      fetch(pingUrl, { method: 'HEAD', mode: 'no-cors', signal: ctrl.signal })
        .then(() => { clearTimeout(timer); resolve(true); })
        .catch(() => { clearTimeout(timer); resolve(false); });
    });
  }

  // ── Auto-detect first reachable source ────────────────────────────────────
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

  // ── Source button clicks ───────────────────────────────────────────────────
  srcBtns.forEach(btn => {
    btn.addEventListener('click', () => loadSource(parseInt(btn.dataset.index, 10), currentMode));
  });

  // ── Sub / Dub toggle ──────────────────────────────────────────────────────
  langBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      const mode = btn.dataset.mode;
      if (mode !== currentMode) loadSource(currentIdx, mode);
    });
  });

  autoDetect();

  // ── Mobile: overlay UI + auto-fullscreen ─────────────────────────────────
  const isMobile = window.matchMedia('(max-width: 640px)').matches;
  const playerPage = document.querySelector('.player-page');
  // iOS Safari does not support document.requestFullscreen; detect it via a
  // temporary video element (no actual video element exists on this page).
  const isIOS = !document.fullscreenEnabled &&
                typeof document.createElement('video').webkitEnterFullscreen === 'function';

  if (isMobile && playerPage) {
    let uiTimer = null;
    let fsTriggered = false;

    function showUI() {
      playerPage.classList.remove('ui-hidden');
      clearTimeout(uiTimer);
      uiTimer = setTimeout(() => playerPage.classList.add('ui-hidden'), 3500);
    }

    function enterFullscreen() {
      if (fsTriggered) return;
      fsTriggered = true;
      if (isIOS) {
        // iOS Safari: try to fullscreen the iframe element itself.
        // Cross-origin iframes may silently refuse; the provider's own
        // fullscreen button inside the iframe remains the fallback.
        const iframeEl = document.getElementById('player-iframe');
        if (iframeEl && iframeEl.webkitRequestFullscreen) {
          iframeEl.webkitRequestFullscreen();
        }
        return;
      }
      const el = document.documentElement;
      const req = el.requestFullscreen || el.webkitRequestFullscreen;
      if (req) req.call(el).catch(() => {});
    }

    // On first touch anywhere: enter fullscreen + show UI
    document.addEventListener('touchstart', (e) => {
      // Don't intercept taps on actual buttons/links
      if (!e.target.closest('button, a, select, input')) enterFullscreen();
      showUI();
    }, { passive: true });

    // Topbar buttons should also show UI when tapped
    document.querySelector('.player-topbar').addEventListener('touchstart', showUI, { passive: true });

    // Start hidden — first touch reveals UI
    playerPage.classList.add('ui-hidden');
    // Show briefly on load so user knows controls exist
    showUI();
  }

  // ── Topbar fullscreen button (desktop fallback) ───────────────────────────
  const topbarFsBtn = document.getElementById('topbar-fs-btn');
  const playerFrameWrap = document.querySelector('.player-frame-wrap');
  if (topbarFsBtn && playerFrameWrap) {
    topbarFsBtn.addEventListener('click', () => {
      if (isIOS) {
        // iOS Safari: try iframe.webkitRequestFullscreen; no-op if blocked
        const iframeEl = document.getElementById('player-iframe');
        if (iframeEl && iframeEl.webkitRequestFullscreen) {
          iframeEl.webkitRequestFullscreen();
        }
        return;
      }
      if (!document.fullscreenElement && !document.webkitFullscreenElement) {
        const el = isMobile ? document.documentElement : playerFrameWrap;
        (el.requestFullscreen || el.webkitRequestFullscreen).call(el).catch(() => {});
      } else {
        (document.exitFullscreen || document.webkitExitFullscreen).call(document);
      }
    });
    function updateTopbarFsIcon() {
      const inFs = !!(document.fullscreenElement || document.webkitFullscreenElement);
      topbarFsBtn.innerHTML = inFs ? '&#x2715;' : '&#x26F6;';
      topbarFsBtn.title = inFs ? 'Exit fullscreen' : 'Fullscreen';
    }
    document.addEventListener('fullscreenchange',       updateTopbarFsIcon);
    document.addEventListener('webkitfullscreenchange', updateTopbarFsIcon);
  }
})();
</script>

</body>
</html>
