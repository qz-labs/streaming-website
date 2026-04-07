<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/JikanApi.php';

$jikan = new JikanApi();

$seasonal  = $jikan->seasonalAnime();
$topTv     = $jikan->topAnime('tv',      'bypopularity');
$topMovies = $jikan->topAnime('movie',   'bypopularity');
$topOva    = $jikan->topAnime('ova',     'bypopularity');
$topSp     = $jikan->topAnime('special', 'bypopularity');

// Pick a hero from seasonal anime that has an image
$heroPool = array_values(array_filter($seasonal, fn($a) => !empty($a['images']['jpg']['large_image_url'])));
$hero     = !empty($heroPool) ? $heroPool[array_rand($heroPool)] : ($topTv[0] ?? null);

$activePage = 'anime';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Anime &ndash; <?= e(SITE_NAME) ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
  <style>
    /* Anime hero uses backdrop from Jikan (no TMDB backdrop here) */
    .anime-hero-bg {
      background-size: cover;
      background-position: center top;
    }
  </style>
</head>
<body>

<?php require __DIR__ . '/partials/nav.php'; ?>

<?php if ($hero): ?>
  <?php
    $malId    = (int)$hero['mal_id'];
    $heroName = $hero['title_english'] ?: ($hero['title'] ?? '');
    $heroYear = yearFromDate($hero['aired']['from'] ?? null);
    $heroRate = isset($hero['score']) && $hero['score'] > 0 ? ratingBadge((float)$hero['score']) : '';
    $heroBg   = jikanImg($hero['images'] ?? [], 'large');
    $heroLink = animeDetailUrl($malId);
    $heroType = $hero['type'] ?? 'TV';
  ?>
  <section
    class="hero anime-hero anime-hero-bg"
    style="background-image: url('<?= e($heroBg) ?>')"
  >
    <div class="hero__content">
      <span class="hero__badge">&#9654; <?= $heroType === 'TV' ? 'Airing Now' : e($heroType) ?></span>
      <h1 class="hero__title"><?= e($heroName) ?></h1>
      <div class="hero__meta">
        <?php if ($heroRate): ?><span class="rating">&#9733; <?= e($heroRate) ?></span><?php endif; ?>
        <?php if ($heroYear): ?><span><?= e($heroYear) ?></span><?php endif; ?>
        <?php if (!empty($hero['episodes'])): ?><span><?= (int)$hero['episodes'] ?> eps</span><?php endif; ?>
      </div>
      <?php if (!empty($hero['synopsis'])): ?>
        <p class="hero__overview"><?= e(truncate($hero['synopsis'], 160)) ?></p>
      <?php endif; ?>
      <div class="hero__buttons">
        <a class="btn btn-play" href="<?= e($heroLink) ?>">&#9654; Watch Now</a>
        <a class="btn btn-info"  href="<?= e($heroLink) ?>">&#9432; More Info</a>
      </div>
    </div>
  </section>
<?php endif; ?>

<main class="rows-container">
  <?php renderAnimeRow('Airing This Season',    $seasonal);  ?>
  <?php renderAnimeRow('Top Anime Series',      $topTv);     ?>
  <?php renderAnimeRow('Top Anime Movies',      $topMovies); ?>
  <?php if (!empty($topOva)):  renderAnimeRow('Top OVAs',      $topOva);  endif; ?>
  <?php if (!empty($topSp)):   renderAnimeRow('Specials',      $topSp);   endif; ?>
</main>

<?php require __DIR__ . '/partials/footer.php'; ?>

<script>const BASE_URL = '<?= BASE_URL ?>';</script>
<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body>
</html>
