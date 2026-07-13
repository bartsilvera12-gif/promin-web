// ProMin — shared motion engine. Called from each page's componentDidMount.
// Plain DOM manipulation only (no framework), safe to run after mount.

// ---- Adaptive mobile layer (injected once, applies to every page) --------
// The DCs use inline styles (no media queries), so we ship a single adaptive
// stylesheet here. site.js's applyGrids() already collapses multi-column
// grids to 1fr <=900px; this handles the design polish on top of that.
function injectAdaptiveCSS() {
  if (document.getElementById('promin-adaptive')) return;
  const css = `
/* ===== PROMIN · Adaptive (mobile) ===== */
/* Tablet & below — stacked columns must not stay sticky (React serializes
   inline styles as "position: sticky", so match on the value only). */
@media (max-width: 900px){
  [style*="sticky"]{ position: static !important; top: auto !important; }
}
/* Phones */
@media (max-width: 640px){
  /* Stable full-screen hero height (no jump when the URL bar hides) */
  section[style*="100vh"]{ min-height: 100svh !important; }
  /* Decorative preview image reads as a compact banner when stacked */
  [data-index-preview]{ aspect-ratio: 16 / 10 !important; }
  /* Comfortable rhythm on small screens */
  [data-intro-grid]{ gap: 30px !important; }
  [data-intro-grid] [style*="96px"]{ min-width: 62px !important; }
  /* Full-width, tappable primary/secondary buttons in the hero */
  .promin-hero-btns{ width: 100%; }
  .promin-hero-btns > a{ flex: 1 1 100%; justify-content: center; }
  /* Slightly larger social touch targets */
  .promin-social{ width: 46px; height: 46px; }
}
/* Small phones */
@media (max-width: 400px){
  [data-index-preview]{ aspect-ratio: 16 / 12 !important; }
}`;
  const style = document.createElement('style');
  style.id = 'promin-adaptive';
  style.textContent = css;
  document.head.appendChild(style);
}

export function initMotion(root) {
  const scope = root || document;
  injectAdaptiveCSS();

  // ---- Responsive grid collapse (inline-style DCs have no media queries) --
  const GRID_SEL = ['[data-hero-grid]', '[data-story-grid]', '[data-svc-grid]',
    '[data-area-grid]', '[data-tech-grid]', '[data-contact-grid]', '[data-mvv-grid]',
    '[data-exp-grid]', '[data-intro-grid]', '[data-index-wrap]', '[data-foot-grid]',
    '[data-values-grid]', '[data-form-row]'].join(',');
  const applyGrids = () => {
    const narrow = window.innerWidth <= 900;
    document.querySelectorAll(GRID_SEL).forEach((el) => {
      if (!el.__cols) el.__cols = el.style.gridTemplateColumns || getComputedStyle(el).gridTemplateColumns;
      el.style.gridTemplateColumns = narrow ? '1fr' : (el.getAttribute('data-cols') || el.__cols);
      if (el.hasAttribute('data-exp-grid')) el.style.gridTemplateColumns = narrow ? '1fr' : 'auto 1fr';
    });
  };
  applyGrids();
  [200, 600, 1200].forEach((t) => setTimeout(applyGrids, t));
  window.addEventListener('resize', applyGrids, { passive: true });

  // ---- Scroll reveal --------------------------------------------------
  const revealEls = Array.from(scope.querySelectorAll('[data-reveal]'));
  revealEls.forEach((el) => {
    if (el.__revInit) return;
    el.__revInit = true;
    const dir = el.getAttribute('data-reveal') || 'up';
    const dist = dir === 'left' ? 'translateX(-42px)'
      : dir === 'right' ? 'translateX(42px)'
      : dir === 'scale' ? 'scale(0.92)'
      : 'translateY(46px)';
    el.style.opacity = '0';
    el.style.transform = dist;
    el.style.willChange = 'opacity, transform';
    el.style.transition = 'opacity .9s cubic-bezier(.22,.61,.36,1), transform 1s cubic-bezier(.22,.61,.36,1)';
  });
  const io = new IntersectionObserver((entries) => {
    entries.forEach((e) => {
      if (!e.isIntersecting) return;
      const el = e.target;
      const delay = parseInt(el.getAttribute('data-reveal-delay') || '0', 10);
      setTimeout(() => {
        el.style.opacity = '1';
        el.style.transform = 'none';
      }, delay);
      io.unobserve(el);
    });
  }, { threshold: 0.12, rootMargin: '0px 0px -8% 0px' });
  revealEls.forEach((el) => io.observe(el));

  // ---- Count up -------------------------------------------------------
  const countEls = Array.from(scope.querySelectorAll('[data-count]'));
  const cio = new IntersectionObserver((entries) => {
    entries.forEach((e) => {
      if (!e.isIntersecting) return;
      const el = e.target;
      const target = parseFloat(el.getAttribute('data-count'));
      const dur = parseInt(el.getAttribute('data-count-dur') || '1600', 10);
      const suffix = el.getAttribute('data-count-suffix') || '';
      const t0 = performance.now();
      const step = (now) => {
        const p = Math.min(1, (now - t0) / dur);
        const eased = 1 - Math.pow(1 - p, 3);
        el.textContent = Math.round(target * eased) + suffix;
        if (p < 1) requestAnimationFrame(step);
      };
      requestAnimationFrame(step);
      cio.unobserve(el);
    });
  }, { threshold: 0.5 });
  countEls.forEach((el) => cio.observe(el));

  // ---- Parallax -------------------------------------------------------
  const paraEls = Array.from(scope.querySelectorAll('[data-parallax]'));
  let ticking = false;
  const onScroll = () => {
    if (ticking) return;
    ticking = true;
    requestAnimationFrame(() => {
      const vh = window.innerHeight;
      paraEls.forEach((el) => {
        const speed = parseFloat(el.getAttribute('data-parallax')) || 0.15;
        const r = el.getBoundingClientRect();
        const center = r.top + r.height / 2;
        const off = (center - vh / 2) * -speed;
        el.style.transform = `translate3d(0, ${off.toFixed(1)}px, 0)`;
      });
      ticking = false;
    });
  };
  if (paraEls.length) {
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  // ---- Progress bar (top) --------------------------------------------
  const bar = scope.querySelector('[data-progress]');
  if (bar) {
    const upd = () => {
      const h = document.documentElement.scrollHeight - window.innerHeight;
      bar.style.transform = `scaleX(${h > 0 ? window.scrollY / h : 0})`;
    };
    window.addEventListener('scroll', upd, { passive: true });
    upd();
  }
}

// Current language helper
export function getLang() {
  try { return localStorage.getItem('promin_lang') === 'es' ? 'es' : 'pt'; }
  catch (e) { return 'pt'; }
}
export function setLang(l) {
  try { localStorage.setItem('promin_lang', l); } catch (e) {}
  location.reload();
}
