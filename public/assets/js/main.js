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
  });

  function makeArrowBtn(html, className) {
    const btn = document.createElement('button');
    btn.className = className;
    btn.innerHTML = html;
    btn.setAttribute('aria-hidden', 'true');
    return btn;
  }

  // ── 4. Search page debounce (search.php) ─────────────────────────────────────
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

});
