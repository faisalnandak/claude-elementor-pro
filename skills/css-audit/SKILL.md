---
name: css-audit
description: Use to audit Custom CSS across an Elementor Pro project and move every rule the panel can own back into native controls. Covers classifying rules, finding superseded and dead selectors, binding hex values to Global Colors, and proving the rendering did not change.
---

# Auditing Custom CSS

The goal is not "less CSS". The goal is that **every rule left in Custom CSS is there because
Elementor's panel genuinely cannot express it** — and that this is written down for each one.

Custom CSS accumulates for honest reasons: an early rule written before the right control was
found, a rule kept after the markup that needed it was replaced, a colour repeated in forty
places. An audit finds all three.

## Where CSS hides

Check every location, not just Site Settings:

| Location | How to read it |
|---|---|
| Kit → Site Settings → Custom CSS | post meta `_elementor_page_settings['custom_css']` on the kit |
| Per-page Custom CSS | the same meta key on each page/template |
| Per-element Custom CSS | `settings.custom_css` on any element inside `_elementor_data` |
| Custom Code snippets | `_elementor_code` on `elementor_snippet` posts |
| Theme stylesheets | outside the rules, but they still cascade — know what is there |

```bash
php scripts/css-audit.php inventory      # every block, every location, with sizes
php scripts/css-audit.php classify       # per-rule verdict
```

## Classify every rule

Give each rule exactly one verdict.

1. **Panel-able** — a native control produces this exact declaration. → Move it, delete the
   rule.
2. **Dead** — the selector matches nothing on any page. → Delete.
3. **Superseded** — a later rule overrides every declaration for the same selector. → Delete
   the earlier one.
4. **Duplicated value** — a hex or font repeated across many rules. → Register a Global and
   bind.
5. **Legitimate** — genuinely beyond the panel. → Keep, and document why.

### What is genuinely legitimate

Keep these without guilt:

- `@media` queries at breakpoints Elementor does not offer.
- Pseudo-elements (`::before`, `::after`) used for decoration.
- `:hover` / `:focus-within` on a **descendant** of the widget, not the widget itself.
- Sibling and structural selectors: `:nth-child`, `+`, `~`, `:not()`.
- Styling markup the widget does not expose as a control — most commonly, HTML written inside
  a Text Editor.
- `position: fixed` bars, scroll behaviour, `clip-path`, complex gradients on pseudo-elements.
- Typography that Elementor prints to a selector which does not match your markup. Selectors
  use the direct-child combinator, so one extra wrapper is enough — *Icon List writes to
  `.elementor-icon-list-item > .elementor-icon-list-text, … > a`.* Confirm with
  `php scripts/el-widget-controls.php <widget> --selectors`, then keep the CSS and record that
  reason in the block comment.

### What is almost always panel-able

- padding, margin, gap, width, alignment on containers
- `grid-template-columns` — including custom values like `repeat(auto-fit,minmax(160px,1fr))`
- font-size / weight / line-height / letter-spacing on a widget's own text control
- colour and background on the widget's own element
- border, radius, box-shadow on the widget wrapper
- responsive variants of all of the above — Elementor has per-breakpoint controls for each
- `display: none` at a breakpoint → **Advanced → Responsive → Visibility**
  (the stored value is `hidden-desktop` / `hidden-tablet` / `hidden-mobile`, *not* `hidden`)

## Finding dead rules honestly

A selector is dead only if it matches on **no page**. Test it against a representative set of
URLs — one of each template type — not just the homepage.

```js
// in Playwright, per URL
selectors.filter(s => { try { return !document.querySelector(s); } catch (e) { return false; } })
```

Two cautions:

- **A selector may only match after JS runs** (galleries, tabs, anything a snippet builds).
  Wait for the page to settle.
- **A selector may match only in the editor.** Check before deleting anything targeting
  `.elementor-editor-active` or similar.

## Finding superseded rules

Two rules with the same selector where the later one redeclares every property of the earlier
one: the earlier is dead weight.

When comparing declaration sets in PHP, **`!==` on arrays compares key order too**. Sort both
sides first or you will get an empty diff list and conclude, wrongly, that nothing is
superseded:

```php
ksort( $a ); ksort( $b );
if ( $a === $b ) { /* superseded */ }
```

## Binding repeated values to Globals

Register the backlog's palette and type scale as Elementor **Global Colors** and **Global
Fonts**, then bind widget settings to them instead of storing hex values.

```bash
php scripts/globals-bind.php            # dry run: what would bind, to which global
php scripts/globals-bind.php apply
```

Remember: **a `__globals__` binding beats a literal value.** After binding, changing a
widget's literal colour does nothing — which is the point, but it surprises the next person.

## Removing a legacy block

Old CSS from a previous theme or builder is usually the single largest block. Before deleting
it:

1. Confirm none of its selectors match anywhere (previous section).
2. Diff the rendered pages before and after with the colour fingerprint from `visual-verify`.
3. Keep the removed text in the backup so `pulihkan` can restore it.

> Real case: a 10,207-byte block inherited from a previous theme. Every selector was verified
> dead across 92 pages before removal, and the kit dropped from 125,728 to 108,285 bytes
> — 13.9% — with an identical colour fingerprint on every sampled page.

## Prove nothing changed

An audit that changes rendering is a regression, not an audit. Every stage ends the same way:

1. Colour fingerprint on representative pages, before and after — must be identical.
2. Screenshots at 1280 / 768 / 390 — look at them.
3. A sweep across every page for PHP notices and broken markup (`scripts/site-sweep.php`).

State the result plainly, including the byte count and the number of pages checked. If a
fingerprint differs, find out why before continuing — do not average it away.

## Order of work

Do the audit in stages, verifying between each. Later stages depend on earlier ones being
clean.

1. Inventory and classify.
2. Delete dead and legacy blocks.
3. Delete superseded rules.
4. Move panel-able rules to controls, one category at a time.
5. Register globals and bind repeated values.
6. Document every surviving rule with its reason.
