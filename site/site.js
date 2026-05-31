/* OllamaDev website — vanilla JS, no libraries.
   1. Copy-to-clipboard buttons. 2. Active-section highlight in the docs sidebar. */
(function () {
  'use strict';

  // --- 1. Copy buttons ----------------------------------------------------
  // Two flavours: [data-copy="id"] copies another element's text; a .copy-btn
  // inside a <pre> copies that block (minus the button label itself).
  function flash(btn, ok) {
    var prev = btn.textContent;
    btn.textContent = ok ? 'Copied ✓' : 'Press ⌘C';
    setTimeout(function () { btn.textContent = prev; }, 1400);
  }

  function copyText(text, btn) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(
        function () { flash(btn, true); },
        function () { flash(btn, false); }
      );
    } else {
      // file:// fallback when the async clipboard API is unavailable.
      var ta = document.createElement('textarea');
      ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); flash(btn, true); }
      catch (e) { flash(btn, false); }
      document.body.removeChild(ta);
    }
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-copy], .copy-btn');
    if (!btn) return;

    var text;
    if (btn.hasAttribute('data-copy')) {
      var el = document.getElementById(btn.getAttribute('data-copy'));
      text = el ? el.textContent : '';
    } else {
      // Clone the <pre>, strip the button, read the rest.
      var pre = btn.closest('pre');
      if (!pre) return;
      var clone = pre.cloneNode(true);
      var b = clone.querySelector('.copy-btn');
      if (b) b.remove();
      text = clone.textContent;
    }
    copyText(text.replace(/\s+$/, ''), btn);
  });

  // --- 2. Docs sidebar active-section highlight ---------------------------
  var links = Array.prototype.slice.call(document.querySelectorAll('.docs-side a[href^="#"]'));
  if (!links.length || !('IntersectionObserver' in window)) return;

  var byId = {};
  links.forEach(function (a) { byId[a.getAttribute('href').slice(1)] = a; });

  var sections = links
    .map(function (a) { return document.getElementById(a.getAttribute('href').slice(1)); })
    .filter(Boolean);

  var current = null;
  function setActive(id) {
    if (id === current) return;
    if (current && byId[current]) byId[current].classList.remove('active');
    if (byId[id]) byId[id].classList.add('active');
    current = id;
  }

  var observer = new IntersectionObserver(function (entries) {
    // Pick the topmost section currently intersecting the viewport band.
    var visible = entries
      .filter(function (en) { return en.isIntersecting; })
      .sort(function (a, b) { return a.boundingClientRect.top - b.boundingClientRect.top; });
    if (visible.length) setActive(visible[0].target.id);
  }, { rootMargin: '-80px 0px -65% 0px', threshold: 0 });

  sections.forEach(function (s) { observer.observe(s); });

  // Reflect clicks immediately (smooth-scroll can lag the observer).
  links.forEach(function (a) {
    a.addEventListener('click', function () { setActive(a.getAttribute('href').slice(1)); });
  });
})();
