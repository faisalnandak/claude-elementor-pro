---
name: visual-verify
description: Use to compare an Elementor implementation against its backlog mockup with Playwright at desktop, tablet and mobile widths. Covers what to measure and in what order, how to avoid false alarms from wrapper elements, overflow hunting, and colour fingerprints for proving a refactor changed nothing.
---

# Verifying against the mockup with Playwright

Measure, do not read CSS. The value that actually applies often comes from a layer you did
not expect — kit defaults, a plugin stylesheet loaded later, a global binding, an inline
style in the mockup itself. `getBoundingClientRect()` and `getComputedStyle()` are the only
sources that settle an argument.

**Look at the screen too.** Numbers matching is not the same as the page looking right; a
screenshot catches what measurement misses (an image escaping its box, a scrim over text,
text with almost no contrast). Do both.

## Always three widths

| width | why |
|---|---|
| **1280** | desktop |
| **768** | tablet — Elementor's tablet breakpoint is ≤1024 |
| **390** | mobile — Elementor's mobile breakpoint is ≤767 |

Open the mockup over HTTP, never `file://`.

## Order of comparison

Top-down, coarse to fine. Do not start with typography.

1. **Section heights and paddings.** A table of per-section differences shows immediately
   where the problem is.
2. **Grids**: width, `grid-template-columns`, `gap`.
3. **Card / component boxes**: width × height, radius, padding.
4. **Typography**: font-size, line-height, weight, letter-spacing, colour.
5. **Horizontal overflow** at every width.

Differences under roughly ±50px in height are usually just longer text in the target
language, not a defect. Confirm rather than chase: if the box widths and paddings match and
only the height differs, it is content length.

## The false alarm that will catch you

**Backlog classes land on the widget wrapper, not on the text.** `.sec-title` sits on the
outer `<div>`; the text is in `.elementor-heading-title` inside it.

Two consequences:

- Measuring the wrapper gives the wrong answer. Measure the text element.
- A selector like `.mainnav a` may match the **logo link** rather than a menu item, because
  the logo is also an anchor. If a measurement looks surprising, print the element's text
  and box before believing the number.

The same applies to grids: a Loop Grid's gap is on `.elementor-grid` **inside** the widget
wrapper. Measuring `.my-grid` reads the wrapper's default gap, not the grid's.

> Real case: a homepage audit reported "grid gap 20 vs mockup 18" three times. The grid was
> already correct at 18 — the wrapper was being measured.

## Measurement recipes

The helper prints a comparable fingerprint of one page:

```js
// scripts/visual-compare.js — paste into browser_evaluate
```

Useful one-off patterns:

```js
// box + spacing of one element
const e = document.querySelector('.p-grid'), c = getComputedStyle(e), r = e.getBoundingClientRect();
({ box: Math.round(r.width)+'×'+Math.round(r.height), cols: c.gridTemplateColumns, gap: c.gap })

// section rhythm: distance from each section's top to its first heading
[...document.querySelectorAll('.sec')].map(s => {
  const h = s.querySelector('h2, .eyebrow');
  return Math.round(h.getBoundingClientRect().top - s.getBoundingClientRect().top);
})
```

## Hunting horizontal overflow

`document.documentElement.scrollWidth > window.innerWidth` says there is overflow but not
where. Two traps:

- **Text can overflow without the box growing.** `white-space:nowrap` pushes content past
  the edge while `getBoundingClientRect()` stays innocent. Only `element.scrollWidth` sees
  it.
- Scan with `el.scrollWidth > el.clientWidth`, and ignore elements that scroll on purpose
  (maps, carousels, thumbnail strips).

If nothing shows up, hide children one at a time from the root down while watching
`document.body.scrollWidth`.

## Proving a refactor changed nothing

When you move styling from CSS to the panel, or bind colours to globals, you are claiming
the rendering is unchanged. Prove it with a fingerprint captured **before** the change:

```js
const out = [];
document.querySelectorAll('.elementor-widget, .e-con, a.elementor-button, .elementor-heading-title')
  .forEach((el, i) => { if (i > 500) return; const c = getComputedStyle(el);
    out.push([c.color, c.backgroundColor, c.borderTopColor].join('|')); });
let h = 0; const s = out.join(';');
for (let i = 0; i < s.length; i++) h = ((h << 5) - h + s.charCodeAt(i)) | 0;
({ elements: out.length, fingerprint: h })
```

Capture it on two or three representative pages, apply the change, capture again. Identical
fingerprints are real evidence; "looks the same" is not.

## Measurement hygiene

- **Flush caches before measuring**: `\Elementor\Plugin::$instance->files_manager->clear_cache()`,
  then load the page twice. Some PHP notices only appear on the first render after a cache
  clear — a second `curl` looks clean and misleads.
- **`getComputedStyle` right after toggling a class reads one step behind.** Re-query in a
  separate call, or take a screenshot.
- **Elementor rebuilds some widgets after load** (galleries especially). A measurement taken
  too early sees a half-built DOM. Wait for the expected element rather than a fixed delay.
- **Diagnose a stubborn value by disabling stylesheets one at a time** (`sh.disabled = true`)
  and watching the computed value. Walking the CSSOM with `matches()` gives misleading empty
  results often enough not to trust it alone.

## When the mockup itself is wrong

Two things the mockups do that are not Elementor's fault:

- `@media` blocks ported out of order, so a 1024px rule wins at 390px.
- Inline styles on individual instances overriding the class rule they document.

If a computed value makes no sense, insert a probe element with the same class and compare.

Also expect mockups to **disagree with each other**: the same component may be styled one way
in one file and differently in another. That is a decision, not a bug — surface it.
