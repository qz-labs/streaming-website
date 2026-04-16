'use strict';

document.addEventListener('DOMContentLoaded', () => {

  // ── 1. Nav background on scroll ──────────────────────────────────────────────
  const nav = document.getElementById('site-nav');
  if (nav) {
    const onScroll = () => nav.classList.toggle('nav--scrolled', window.scrollY > 10);
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll(); // run once on load
  }

  // ── 2. Nav search expand / collapse ──────────────────────────────────────────
  const searchToggle = document.getElementById('search-toggle');
  const navInput     = document.getElementById('nav-search-input');

  if (searchToggle && navInput) {
    searchToggle.addEventListener('click', () => {
      const isOpen = navInput.classList.toggle('open');
      if (isOpen) {
        navInput.focus();
      } else {
        navInput.value = '';
      }
    });

    navInput.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        navInput.classList.remove('open');
        navInput.value = '';
        searchToggle.focus();
      }
    });

    // Close if clicking outside
    document.addEventListener('click', (e) => {
      if (!searchToggle.contains(e.target) && !navInput.contains(e.target)) {
        navInput.classList.remove('open');
      }
    });
  }

  // ── 3. Horizontal row scroll arrows ──────────────────────────────────────────
  document.querySelectorAll('.row').forEach((row) => {
    const track = row.querySelector('.row__track');
    if (!track) return;

    // Wrap track in a relative container if not already
    let wrapper = row.querySelector('.row__wrapper');
    if (!wrapper) {
      wrapper = document.createElement('div');
      wrapper.className = 'row__wrapper';
      track.parentNode.insertBefore(wrapper, track);
      wrapper.appendChild(track);
    }

    const btnLeft  = makeArrowBtn('&#8249;', 'row__arrow row__arrow--left');
    const btnRight = makeArrowBtn('&#8250;', 'row__arrow row__arrow--right');
    wrapper.appendChild(btnLeft);
    wrapper.appendChild(btnRight);

    const scrollAmount = () => {
      const card = track.querySelector('.card');
      return card ? (card.offsetWidth + 6) * 4 : 720;
    };

    btnLeft.addEventListener('click',  () => track.scrollBy({ left: -scrollAmount(), behavior: 'smooth' }));
    btnRight.addEventListener('click', () => track.scrollBy({ left:  scrollAmount(), behavior: 'smooth' }));

    const updateArrows = () => {
      btnLeft.classList.toggle('hidden',  track.scrollLeft <= 0);
      btnRight.classList.toggle('hidden', track.scrollLeft + track.clientWidth >= track.scrollWidth - 1);
    };

    track.addEventListener('scroll', updateArrows, { passive: true });
    // Delay first check until images have a chance to load
    setTimeout(updateArrows, 200);
    window.addEventListener('resize', updateArrows, { passive: true });

    // CSS spec: setting overflow-x: auto forces overflow-y: auto as well,
    // which lets the track capture and consume vertical wheel events instead
    // of passing them up to the page. Fix: intercept pure-vertical wheel
    // events and redirect them to the window so the page scrolls normally.
    track.addEventListener('wheel', (e) => {
      // Only intercept pure vertical wheel events so the page scrolls normally.
      // Do NOT specify behavior — inherits scroll-behavior: smooth from <html>.
      if (e.deltaX === 0 && e.deltaY !== 0) {
        e.preventDefault();
        window.scrollBy({ top: e.deltaY, behavior: 'instant' });
      }
    }, { passive: false });
  });

  function makeArrowBtn(html, className) {
    const btn = document.createElement('button');
    btn.className = className;
    btn.innerHTML = html;
    btn.setAttribute('aria-hidden', 'true');
    return btn;
  }

  // ── 4. Detail page back button ───────────────────────────────────────────────
  // If the user arrived from a watch/episode page (e.g. clicked ← in the player),
  // history.back() would return them to that player — not where they started.
  // In that case we let the <a href> navigate to the fallback (home / anime page).
  // Otherwise we go back in history so search results / filtered lists are preserved.
  const detailBackBtn = document.getElementById('detail-back-btn');
  if (detailBackBtn) {
    detailBackBtn.addEventListener('click', function (e) {
      const fromPlayer = /\/(watch|anime-watch)\.php/.test(document.referrer || '');
      if (!fromPlayer && window.history.length > 1) {
        e.preventDefault();
        history.back();
      }
      // else: let the href navigate to the hardcoded fallback URL
    });
  }

  // ── 5. Search page debounce (search.php) ─────────────────────────────────────
  const searchInput = document.getElementById('search-input');
  if (searchInput) {
    let debounceTimer;
    searchInput.addEventListener('input', (e) => {
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(() => {
        const q = e.target.value.trim();
        if (q.length >= 2) {
          const url = (typeof BASE_URL !== 'undefined' ? BASE_URL : '') +
                      '/search.php?q=' + encodeURIComponent(q);
          window.location.href = url;
        }
      }, 450);
    });
  }

  // ── 5. Season selector (tv.php) ──────────────────────────────────────────────
  const seasonSelect = document.getElementById('season-select');
  if (seasonSelect) {
    seasonSelect.addEventListener('change', () => {
      const showId = seasonSelect.dataset.showId;
      const season = seasonSelect.value;
      if (showId && season) {
        const base = typeof BASE_URL !== 'undefined' ? BASE_URL : '';
        window.location.href = base + '/tv.php?id=' + encodeURIComponent(showId) +
                               '&season=' + encodeURIComponent(season);
      }
    });
  }

  // ── 6. Hero carousel ─────────────────────────────────────────────────────────
  const heroSection = document.querySelector('.hero[data-slides]');
  if (heroSection) {
    // Upgrade the static (PHP-rendered) hero background to w1280 on large screens.
    // PHP sets a w780 bg by default; the lg URL is stored in data-backdrop-lg.
    if (window.innerWidth > 768 && heroSection.dataset.backdropLg) {
      heroSection.style.backgroundImage = "url('" + heroSection.dataset.backdropLg + "')";
    }

    let slides;
    try { slides = JSON.parse(heroSection.dataset.slides); } catch { slides = []; }

    // Pick the right backdrop size based on the current viewport.
    // slide.backdrop = w1280 (desktop), slide.backdropSm = w780 (mobile)
    const pickBg = (s) => (window.innerWidth > 768 && s.backdrop)
      ? s.backdrop
      : (s.backdropSm || s.backdrop || '');

    if (slides.length > 1) {
      let current = 0;

      // Build background slide divs (inserted before hero content)
      const slideDivs = slides.map((s, i) => {
        const div = document.createElement('div');
        div.className = 'hero__slide' + (i === 0 ? ' active' : '');
        const bg = pickBg(s);
        if (bg) div.style.backgroundImage = "url('" + bg + "')";
        heroSection.insertBefore(div, heroSection.firstChild);
        return div;
      });

      // Build dot nav
      const dotsWrap = document.createElement('div');
      dotsWrap.className = 'hero__dots';
      const dots = slides.map((_, i) => {
        const btn = document.createElement('button');
        btn.className = 'hero__dot' + (i === 0 ? ' active' : '');
        btn.setAttribute('aria-label', 'Go to slide ' + (i + 1));
        btn.addEventListener('click', () => { goTo(i); resetTimer(); });
        dotsWrap.appendChild(btn);
        return btn;
      });
      heroSection.appendChild(dotsWrap);

      const titleEl    = heroSection.querySelector('.hero__title');
      const overviewEl = heroSection.querySelector('.hero__overview');
      const playBtn    = heroSection.querySelector('.btn-play');
      const infoBtn    = heroSection.querySelector('.btn-info');
      const ratingEl   = heroSection.querySelector('.hero__meta .rating');
      const yearEl     = heroSection.querySelector('.hero__meta span:not(.rating)');

      const heroBgImg = heroSection.querySelector('.hero__bg img');

      function goTo(idx) {
        slideDivs[current].classList.remove('active');
        dots[current].classList.remove('active');
        current = (idx + slides.length) % slides.length;
        slideDivs[current].classList.add('active');
        dots[current].classList.add('active');
        const s = slides[current];
        if (titleEl)    titleEl.textContent  = s.title;
        if (overviewEl) overviewEl.textContent = s.overview;
        if (playBtn)    playBtn.href = s.watchUrl;
        if (infoBtn)    infoBtn.href = s.detailUrl;
        if (ratingEl && s.rating) ratingEl.textContent = '\u2605 ' + s.rating;
        if (yearEl)     yearEl.textContent   = s.year || '';
        // Update blurred anime background when slide changes
        if (heroBgImg && (s.backdrop || s.backdropSm)) heroBgImg.src = pickBg(s);
      }

      let timer = setInterval(() => goTo(current + 1), 7000);
      function resetTimer() { clearInterval(timer); timer = setInterval(() => goTo(current + 1), 7000); }

      // Pause auto-advance while the user hovers over the hero
      heroSection.addEventListener('mouseenter', () => clearInterval(timer));
      heroSection.addEventListener('mouseleave', () => resetTimer());

      // Touch swipe support
      let touchStartX = 0;
      heroSection.addEventListener('touchstart', e => {
        touchStartX = e.touches[0].clientX;
        clearInterval(timer);
      }, { passive: true });
      heroSection.addEventListener('touchend', e => {
        const delta = touchStartX - e.changedTouches[0].clientX;
        if (Math.abs(delta) > 40) goTo(current + (delta > 0 ? 1 : -1));
        resetTimer();
      }, { passive: true });
    }
  }

  // ── 7. Hamburger menu ─────────────────────────────────────────────────────────
  const hamburger = document.getElementById('nav-hamburger');
  const drawer    = document.getElementById('nav-drawer');
  if (hamburger && drawer) {
    hamburger.addEventListener('click', (e) => {
      e.stopPropagation();
      const open = hamburger.classList.toggle('open');
      drawer.classList.toggle('open', open);
      hamburger.setAttribute('aria-expanded', String(open));
    });
    drawer.querySelectorAll('a').forEach(a =>
      a.addEventListener('click', () => {
        hamburger.classList.remove('open');
        drawer.classList.remove('open');
      })
    );
    document.addEventListener('click', (e) => {
      if (!hamburger.contains(e.target) && !drawer.contains(e.target)) {
        hamburger.classList.remove('open');
        drawer.classList.remove('open');
      }
    });
  }

  // ── 8. Back to top ────────────────────────────────────────────────────────────
  const backBtn = document.createElement('button');
  backBtn.className = 'back-to-top';
  backBtn.innerHTML = '&#8593;';
  backBtn.setAttribute('aria-label', 'Back to top');
  document.body.appendChild(backBtn);
  window.addEventListener('scroll', () =>
    backBtn.classList.toggle('visible', window.scrollY > 400), { passive: true }
  );
  backBtn.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));

  // ── 9. Genre filter pills ─────────────────────────────────────────────────────
  const genreFilters = document.querySelector('.genre-filters');
  if (genreFilters) {
    function applyGenreFilter(genreId) {
      genreFilters.querySelectorAll('.genre-pill').forEach(p =>
        p.classList.toggle('active', p.dataset.genreId === genreId)
      );
      document.querySelectorAll('.row').forEach(row => {
        const filterable = row.querySelectorAll('.card[data-genre-ids]');
        if (!filterable.length) return; // skip rows with no genre-tagged cards (e.g. continue watching, favorites)
        let anyVisible = false;
        filterable.forEach(card => {
          const ids = (card.dataset.genreIds || '').split(',');
          const show = genreId === 'all' || ids.includes(genreId);
          card.style.display = show ? '' : 'none';
          if (show) anyVisible = true;
        });
        row.style.display = anyVisible ? '' : 'none';
      });
    }

    // Restore filter from URL on page load
    const urlGenre = new URLSearchParams(location.search).get('genre') || 'all';
    applyGenreFilter(urlGenre);

    genreFilters.addEventListener('click', (e) => {
      const pill = e.target.closest('.genre-pill');
      if (!pill) return;
      const genreId = pill.dataset.genreId;
      applyGenreFilter(genreId);
      // Persist to URL without a full page reload
      const url = new URL(location.href);
      if (genreId === 'all') {
        url.searchParams.delete('genre');
      } else {
        url.searchParams.set('genre', genreId);
      }
      history.replaceState(null, '', url);
    });
  }

  // ── 10. Search tabs ───────────────────────────────────────────────────────────
  const searchTabs = document.querySelectorAll('.search-tab');
  if (searchTabs.length) {
    searchTabs.forEach(tab => {
      tab.addEventListener('click', () => {
        searchTabs.forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
        const target = tab.dataset.tab;
        document.querySelectorAll('.search-section').forEach(sec => {
          sec.classList.toggle('hidden', target !== 'all' && sec.dataset.section !== target);
        });
      });
    });
  }

  // ── 11. Broken image fallback ─────────────────────────────────────────────────
  const IMG_PLACEHOLDER = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='300' height='450' viewBox='0 0 300 450'%3E%3Crect width='300' height='450' fill='%231f1f1f'/%3E%3Crect x='40' y='60' width='220' height='330' rx='4' fill='%23333'/%3E%3Ctext x='150' y='235' font-family='Arial' font-size='14' fill='%23666' text-anchor='middle'%3ENo Image%3C/text%3E%3C/svg%3E";
  document.querySelectorAll('.card img').forEach(img => {
    img.addEventListener('error', function () {
      if (!this.src.startsWith('data:')) {
        this.src = IMG_PLACEHOLDER;
      }
    });
  });

});
