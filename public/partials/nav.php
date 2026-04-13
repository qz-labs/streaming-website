<?php $ap = $activePage ?? ''; ?>
<a class="skip-to-content" href="#main-content">Skip to content</a>
<nav class="site-nav" id="site-nav">
  <a class="nav__logo" href="<?= BASE_URL ?>/"><?= SITE_NAME ?></a>

  <ul class="nav__links">
    <li><a href="<?= BASE_URL ?>/"          <?= $ap === 'home'   ? 'class="active"' : '' ?>>Home</a></li>
    <li><a href="<?= BASE_URL ?>/#movies"   <?= $ap === 'movies' ? 'class="active"' : '' ?>>Movies</a></li>
    <li><a href="<?= BASE_URL ?>/#tv"       <?= $ap === 'tv'     ? 'class="active"' : '' ?>>TV Shows</a></li>
    <li><a href="<?= BASE_URL ?>/anime.php" <?= $ap === 'anime'  ? 'class="active"' : '' ?>>Anime</a></li>
  </ul>

  <div class="nav__search">
    <button class="nav__search-btn" id="search-toggle" aria-label="Search">&#128269;</button>
    <form action="<?= BASE_URL ?>/search.php" method="get">
      <input
        class="nav__search-input"
        id="nav-search-input"
        type="search"
        name="q"
        placeholder="Search titles..."
        autocomplete="off"
      >
    </form>
  </div>

  <button
    class="nav__hamburger"
    id="nav-hamburger"
    aria-label="Open menu"
    aria-expanded="false"
    aria-controls="nav-drawer"
  >
    <span></span><span></span><span></span>
  </button>
</nav>

<div class="nav__drawer" id="nav-drawer" aria-hidden="true">
  <ul>
    <li><a href="<?= BASE_URL ?>/"          <?= $ap === 'home'   ? 'class="active"' : '' ?>>Home</a></li>
    <li><a href="<?= BASE_URL ?>/#movies"   <?= $ap === 'movies' ? 'class="active"' : '' ?>>Movies</a></li>
    <li><a href="<?= BASE_URL ?>/#tv"       <?= $ap === 'tv'     ? 'class="active"' : '' ?>>TV Shows</a></li>
    <li><a href="<?= BASE_URL ?>/anime.php" <?= $ap === 'anime'  ? 'class="active"' : '' ?>>Anime</a></li>
    <li><a href="<?= BASE_URL ?>/search.php">Search</a></li>
  </ul>
</div>
