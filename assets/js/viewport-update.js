(function () {
  'use strict';

  /* ── Bootstrap-compatible breakpoint thresholds ─────────────────────────── */
  var BREAKPOINTS = [
    { name: 'xxl', min: 1400 },
    { name: 'xl',  min: 1200 },
    { name: 'lg',  min:  992 },
    { name: 'md',  min:  768 },
    { name: 'sm',  min:  576 },
    { name: 'xs',  min:    0 },
  ];

  /* ── Compute active breakpoint from current viewport width ───────────────── */
  function currentBreakpoint() {
    var w = Math.max(
      document.documentElement.clientWidth || 0,
      window.innerWidth || 0
    );
    for (var i = 0; i < BREAKPOINTS.length; i++) {
      if (w >= BREAKPOINTS[i].min) return BREAKPOINTS[i].name;
    }
    return 'xs';
  }

  /* ── Gracefully handle missing / unregistered CustomEvent ─────────────────── */
  var supportsCustomEvent = typeof window.CustomEvent === 'function'
      || window.CustomEvent !== undefined;

  function fire(name, detail) {
    if (supportsCustomEvent) {
      try {
        window.dispatchEvent(new CustomEvent(name, { detail: detail }));
        return;
      } catch (_) { /* fall through */ }
    }
    try {
      window.dispatchEvent(new Event(name));
    } catch (_) { /* no-op */ }
  }

  /* ── Update html data-breakpoint attribute and localStorage ───────────────── */
  var lastBreakpoint = null;

  function applyBreakpoint() {
    var bp = currentBreakpoint();
    if (bp === lastBreakpoint) return;
    lastBreakpoint = bp;

    document.documentElement.setAttribute('data-breakpoint', bp);

    /* Persist user's preferred viewport width so charts / layout
       Initialise correctly on the next page load / navigation. */
    try {
      localStorage.setItem('fnstm-breakpoint', bp);
    } catch (_) { /* localStorage unavailable */ }
  }

  /* ── Listen for breakpoint changes dispatched by child iframes / popups ────── */
  window.addEventListener('viewport-update', function (e) {
    applyBreakpoint();
    if (e && e.detail) {
      fire('viewport-applied', e.detail);
    }
  });

  /* ── Debounced resize handler ─────────────────────────────────────────────── */
  var rafPending = false;

  function onResize() {
    if (rafPending) return;
    rafPending = true;
    requestAnimationFrame(function () {
      rafPending = false;
      applyBreakpoint();
      /* Notify any other listener on the same page / window */
      fire('viewport-update', { breakpoint: currentBreakpoint(), ts: Date.now() });
    });
  }

  /* ── Restore-breakpoint → persist → fire global update on page-load ──────── */
  window.addEventListener('load', function () {
    /* Restore last known breakpoint */
    var saved;
    try { saved = localStorage.getItem('fnstm-breakpoint'); } catch (_) { saved = null; }
    if (saved) document.documentElement.setAttribute('data-breakpoint', saved);

    applyBreakpoint();
    fire('viewport-update', { breakpoint: lastBreakpoint, ts: Date.now() });
  });

  window.addEventListener('resize', onResize);

  /* ── Force a check when address-bar / UI chrome changes (Safari mobile) ────
     Mobile Safari does not always fire 'resize' when the URL bar collapses
     or expands.  We periodically sync width/height against the saved value. */
  (function safariPatch() {
    var _w = Math.max(document.documentElement.clientWidth, window.innerWidth || 0);
    var _h = Math.max(document.documentElement.clientHeight, window.innerHeight || 0);

    setInterval(function () {
      var w = Math.max(document.documentElement.clientWidth, window.innerWidth || 0);
      var h = Math.max(document.documentElement.clientHeight, window.innerHeight || 0);
      if (w !== _w || h !== _h) {
        _w = w;
        _h = h;
        applyBreakpoint();
        fire('viewport-update', { breakpoint: lastBreakpoint, ts: Date.now() });
      }
    }, 1000);
  })();

  /* ── Dev breakpoint indicator (enabled via ?dev-viewport=1 in URL) ────────── */
  (function devIndicator() {
    try {
      var params = new URLSearchParams(window.location.search);
      if (params.get('dev-viewport') !== '1') return;
      document.body.classList.add('dev-viewport-active');
    } catch (_) { /* no-op */ }
  })();
})();
