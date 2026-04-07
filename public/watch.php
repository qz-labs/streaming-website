<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
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
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
  <style>
    html, body { margin: 0; padding: 0; background: #000; height: 100%; overflow: hidden; }

    .player-page   { display: flex; flex-direction: column; height: 100vh; background: #000; }

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
    .lang-btn.active      { background: #E50914; color: #fff; border-color: #E50914; }

    /* ── Source buttons ── */
    .source-btns {
      display: flex;
      gap: 3px;
      flex-shrink: 0;
    }

    .src-btn {
      background: #1a1a1a;
      color: #666;
      border: 1px solid #333;
      border-radius: 3px;
      padding: 3px 8px;
      font-size: 0.7rem;
      cursor: pointer;
      transition: background .15s, color .15s;
    }
    .src-btn:hover    { background: #2a2a2a; color: #ccc; }
    .src-btn.active   { background: #E50914; border-color: #E50914; color: #fff; }
    .src-btn.checking { opacity: .35; cursor: default; }

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

    /* ── Iframe area ── */
    .player-frame-wrap {
      flex: 1;
      position: relative;
      background: #000;
    }

    .player-frame-wrap iframe {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      border: none;
    }

    /* ── Loading overlay ── */
    .player-loading {
      position: absolute;
      inset: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #000;
      color: #555;
      font-size: 0.85rem;
      z-index: 5;
      pointer-events: none;
      transition: opacity .3s;
    }
    .player-loading.hidden { opacity: 0; }

    @keyframes spin { to { transform: rotate(360deg); } }
    .spinner {
      width: 28px; height: 28px;
      border: 3px solid #222;
      border-top-color: #E50914;
      border-radius: 50%;
      animation: spin .75s linear infinite;
      margin-right: 10px;
      flex-shrink: 0;
    }
  </style>
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
      src=""
      frameborder="0"
      allowfullscreen
      referrerpolicy="origin"
      allow="autoplay; fullscreen; picture-in-picture"
      scrolling="no"
    ></iframe>
  </div>

</div>

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
    iframe.src = '';
    requestAnimationFrame(() => { iframe.src = src.url; });

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
})();
</script>

</body>
</html>
