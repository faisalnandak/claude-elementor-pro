---
name: backlog-analysis
description: Use at the start of an Elementor Pro build to survey the WordPress install and read a product-backlog folder of HTML mockups. Covers serving mockups over HTTP, unpacking self-extracting bundles, harvesting design tokens, and mapping mockup files to live pages before any implementation begins.
---

# Reading the project and the backlog

Two surveys come before any implementation: what the site already has, and what the backlog
actually asks for. Skipping either produces work that has to be undone.

## 1. Survey the WordPress install

Run this before reading the backlog. You need to know what you are allowed to build with.

```bash
php scripts/el-inspect.php env
```

That reports: WordPress and PHP version, active theme and parent, Elementor / Elementor Pro
versions, active plugins, registered post types and taxonomies, Elementor kit ID, and every
Theme Builder template with its display conditions.

Read the output for these specifically:

- **Elementor Pro present and licensed?** Without Pro there is no Theme Builder, no Loop
  Grid, no dynamic tags — the whole approach changes.
- **Which theme?** A "Hello Elementor"-style base is ideal. A heavy commercial theme means
  its CSS and its own widgets will fight you; note it and expect specificity battles.
- **ACF present?** Determines whether derived fields are available at ladder rung 5.
- **Existing Theme Builder templates and their conditions.** These decide which template
  actually renders a given URL. Two templates matching the same condition is a common cause
  of "my change did nothing".
- **Post types.** A `product` CPT may come from WooCommerce or from ACF/CPT-UI. That changes
  which widgets and query controls are available.

## 2. Serve the mockups over HTTP

Mockups must be loaded through the web server, not `file://` — relative assets, fonts and
scripts break otherwise, and the measurements you take will be wrong.

Put the backlog inside the site root so it is servable:

```
http://localhost/<site>/product-backlog/<file>.html
```

Avoid the URL parameter `?tb=` for cache-busting; it collides with Elementor. Use something
neutral like `?cek=1`.

### Self-unpacking bundles

Some exported mockups are not plain HTML. The visible source is a small loader and the real
page sits inside a `<script type="__bundler/template">` block that unpacks on
`DOMContentLoaded`.

Symptom: `curl` shows almost nothing, but the browser shows a full page.

Consequence: **grep on the file is unreliable** for markup, though it still works for CSS
rules inside the bundled `<style>` blocks. Read structure through Playwright after the page
has rendered, not from the file.

## 3. Harvest the design tokens

Nearly every backlog carries a `:root` token block repeated identically across its HTML
files. Extract it once:

```bash
php scripts/backlog-tokens.php /path/to/product-backlog
```

It reports the tokens, flags any that disagree between files, and prints a mapping proposal
to Elementor **Global Colors** and **Global Fonts**.

Register those tokens as Elementor Globals early. Widgets bind to globals natively, so a
brand colour becomes one edit instead of hundreds. (In the reference project this was left
until late, and 460 widget settings had already been saved with raw hex values that had to
be rebound afterwards.)

The CSS `:root` block is still needed **in addition** whenever the backlog's own component
CSS is ported — plain markup cannot read Elementor globals. Both systems coexist on purpose:
globals for widgets, `:root` for ported component CSS.

## 4. Map mockup files to live targets

Build an explicit table before touching anything. For each mockup file record:

| mockup | live URL | rendered by | notes |
|---|---|---|---|
| `Homepage.html` | `/` | page #231 | |
| `Product_Liveaboard.html` | `/product/<slug>/` | Theme Builder single #19271 | condition: product_cat 205 |
| `Blog_Index.html` | `/blog/` | Theme Builder archive #19174 | |

"Rendered by" matters more than it looks. A page URL may be a normal page, a Theme Builder
singular template, or an archive template — and the fix for a difference lives in whichever
one actually renders.

Confirm it rather than assuming: load the URL and read the root element's classes, e.g.
`elementor-19271` tells you template 19271 rendered it.

## 5. Read the backlog for content, not only layout

The backlog usually carries the real copy. Three rules:

- **Use the backlog's words.** Do not paraphrase, and do not translate unless the project
  says to.
- **Content the backlog does not supply is a question, never an invention.** If a section
  needs eight testimonials and the site has three, ask.
- **Watch for per-page contradictions.** The same component often differs slightly between
  mockup files. Record the difference and decide deliberately — majority is not automatically
  right; the page context usually settles it.

## 6. Write down the plan before building

Produce, and keep updated:

- the mockup → live target table
- a block inventory per page: each visual block and the **native widget** intended for it
- the list of unknowns → these become the first Decision Questionnaire

Only after that does implementation start.

## What "done reading" means

You can answer, without opening anything again: which template renders each URL, which
widget will build each block, where the design tokens live, and what you still need the
user to decide.
