/**
 * visual-compare.js — measurement helpers for comparing a live page with its mockup.
 *
 * Paste the whole file into a Playwright browser_evaluate call (as the body of
 * `() => { ... }`), then call one of the helpers on the last line. Run the same
 * call on the mockup URL and on the live URL at 1280, 768 and 390, and diff the
 * two reports.
 *
 * Measure, do not read CSS: the value that actually applies often comes from a
 * layer you did not expect. And look at the screenshots too — numbers matching
 * is not the same as the page looking right.
 *
 * @license MIT
 */

const KT = {

  /**
   * Colour fingerprint of the page.
   *
   * Capture before a refactor, capture after, compare. Identical fingerprints
   * are evidence that moving styling from CSS to the panel — or binding
   * colours to Global Colors — changed nothing. "Looks the same" is not.
   */
  fingerprint(limit = 500) {
    const out = [];
    document
      .querySelectorAll('.elementor-widget, .e-con, a.elementor-button, .elementor-heading-title')
      .forEach((el, i) => {
        if (i >= limit) return;
        const c = getComputedStyle(el);
        out.push([c.color, c.backgroundColor, c.borderTopColor, c.fontSize, c.fontWeight].join('|'));
      });
    let h = 0;
    const s = out.join(';');
    for (let i = 0; i < s.length; i++) h = ((h << 5) - h + s.charCodeAt(i)) | 0;
    return { width: innerWidth, elements: out.length, fingerprint: h };
  },

  /**
   * Box, spacing and grid of one element.
   *
   * Remember that backlog classes usually land on the widget WRAPPER, not on
   * the text or the grid. `.sec-title` is the outer div; the text lives in
   * `.elementor-heading-title` inside it, and a Loop Grid's gap is on
   * `.elementor-grid`. Measuring the wrapper is the single most common source
   * of false differences.
   */
  box(selector) {
    const el = document.querySelector(selector);
    if (!el) return { selector, found: false };
    const r = el.getBoundingClientRect();
    const c = getComputedStyle(el);
    return {
      selector,
      found: true,
      tag: el.tagName.toLowerCase(),
      classes: el.className,
      box: `${Math.round(r.width)}×${Math.round(r.height)}`,
      padding: c.padding,
      margin: c.margin,
      gap: c.gap,
      cols: c.gridTemplateColumns,
      radius: c.borderRadius,
      font: `${c.fontSize}/${c.lineHeight} ${c.fontWeight} ${c.letterSpacing}`,
      color: c.color,
      background: c.backgroundColor,
      text: (el.textContent || '').trim().slice(0, 40),
    };
  },

  /** Same as box(), for every match — use when a class repeats. */
  boxes(selector, limit = 20) {
    return [...document.querySelectorAll(selector)].slice(0, limit).map((el) => {
      const r = el.getBoundingClientRect();
      const c = getComputedStyle(el);
      return {
        box: `${Math.round(r.width)}×${Math.round(r.height)}`,
        padding: c.padding,
        gap: c.gap,
        text: (el.textContent || '').trim().slice(0, 30),
      };
    });
  },

  /**
   * Section rhythm: height and vertical padding of each top-level section.
   *
   * Start a comparison here, not with typography. A per-section table shows
   * immediately where the difference lives. Height differences under about
   * 50px are usually just longer text in the target language — confirm by
   * checking that the widths and paddings match.
   */
  sections(selector = '.e-con, section, .sec') {
    return [...document.querySelectorAll(selector)]
      .filter((el) => el.getBoundingClientRect().height > 40)
      .map((el, i) => {
        const r = el.getBoundingClientRect();
        const c = getComputedStyle(el);
        const h = el.querySelector('h1, h2, h3, .eyebrow');
        return {
          i,
          classes: (el.className || '').split(' ').slice(0, 3).join(' '),
          height: Math.round(r.height),
          pad: `${c.paddingTop} ${c.paddingBottom}`,
          heading: h ? (h.textContent || '').trim().slice(0, 32) : '',
        };
      });
  },

  /**
   * Everything that overflows horizontally.
   *
   * `documentElement.scrollWidth > innerWidth` says there is overflow but not
   * where. Text can also overflow without the box growing: white-space:nowrap
   * pushes content past the edge while getBoundingClientRect() stays innocent,
   * so scan scrollWidth, not the rect. Ignore elements that scroll on purpose.
   */
  overflow(ignore = '.swiper, .elementor-image-carousel, .kt-thumbs, [data-scroll]') {
    const page = document.documentElement.scrollWidth > innerWidth;
    const hits = [];
    document.querySelectorAll('*').forEach((el) => {
      if (el.closest(ignore)) return;
      if (el.scrollWidth > el.clientWidth + 1 && el.clientWidth > 0) {
        const c = getComputedStyle(el);
        if (c.overflowX === 'auto' || c.overflowX === 'scroll') return;
        hits.push({
          tag: el.tagName.toLowerCase(),
          classes: (el.className || '').toString().split(' ').slice(0, 3).join(' '),
          scroll: el.scrollWidth,
          client: el.clientWidth,
          text: (el.textContent || '').trim().slice(0, 30),
        });
      }
    });
    return { pageOverflows: page, pageWidth: document.documentElement.scrollWidth, viewport: innerWidth, hits: hits.slice(0, 15) };
  },

  /** Typography of the first match, measured on the text element itself. */
  type(selector) {
    const el = document.querySelector(selector);
    if (!el) return { selector, found: false };
    const c = getComputedStyle(el);
    return {
      selector,
      found: true,
      family: c.fontFamily.split(',')[0].replace(/["']/g, ''),
      size: c.fontSize,
      weight: c.fontWeight,
      lineHeight: c.lineHeight,
      letterSpacing: c.letterSpacing,
      transform: c.textTransform,
      color: c.color,
      align: c.textAlign,
    };
  },

  /**
   * Which Elementor documents rendered this page.
   *
   * The fix for a difference lives in whichever document actually renders the
   * URL — a page, a singular template, or an archive template. Confirm rather
   * than assume; the first id here is usually the header.
   */
  documents() {
    const ids = new Set();
    document.querySelectorAll('[class*="elementor-"]').forEach((el) => {
      (el.className || '').toString().split(' ').forEach((cls) => {
        const m = cls.match(/^elementor-(\d+)$/);
        if (m) ids.add(Number(m[1]));
      });
    });
    return [...ids];
  },

  /** Selectors from css-audit.php that match nothing here. Pass the JSON array. */
  deadSelectors(selectors) {
    return selectors.filter((s) => {
      try {
        return !document.querySelector(s);
      } catch (e) {
        return false;
      }
    });
  },

  /** Whole-page summary for a first pass. */
  report() {
    return {
      url: location.pathname,
      viewport: innerWidth,
      documents: this.documents(),
      fingerprint: this.fingerprint(),
      overflow: this.overflow(),
      sections: this.sections().slice(0, 25),
    };
  },
};

// Choose one:
KT.report();
// KT.box('.p-grid');
// KT.type('.elementor-heading-title');
// KT.overflow();
