<nav class="site-nav" id="site-nav">
  <a class="nav__logo" href="<?= BASE_URL ?>/"><?= SITE_NAME ?></a>

  <ul class="nav__links">
    <li><a href="<?= BASE_URL ?>/" <?= ($activePage ?? '') === 'home'  ? 'class="active"' : '' ?>>Home</a></li>
    <li><a href="<?= BASE_URL ?>/#movies" <?= ($activePage ?? '') === 'movies' ? 'class="active"' : '' ?>>Movies</a></li>
    <li><a href="<?= BASE_URL ?>/#tv" <?= ($activePage ?? '') === 'tv'     ? 'class="active"' : '' ?>>TV Shows</a></li>
    <li><a href="<?= BASE_URL ?>/anime.php" <?= ($activePage ?? '') === 'anime'  ? 'class="active"' : '' ?>>Anime</a></li>
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
</nav>
