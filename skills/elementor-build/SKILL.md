---
name: elementor-build
description: Use when translating a backlog design block into Elementor Pro structure — choosing the native widget, building layout with containers, wiring Theme Builder templates, and binding dynamic content from ACF or post fields. Includes the widget map, container rules, and the safe scripting pattern for writing _elementor_data.
---

# Building the design with native Elementor Pro

Translate each visual block into the widget Elementor already ships. Before reading further,
the decision ladder from `elementor-workflow` applies to every block without exception.

## Read the widget source before configuring it

This is the highest-value habit in the whole workflow.

```bash
php scripts/el-widget-controls.php loop-grid        # by widget name
php scripts/el-widget-controls.php --file .../icon-list.php
```

It prints every control with its **type**, **default**, **condition** and **selectors**, read
from Elementor's live control stack — including the style-tab stack, which the public
`get_controls()` hides outside the editor. You need this because control names and shapes
routinely defy expectation:

- A control that looks like a spacing box may be a **SLIDER**, not Dimensions — feeding it
  `{top,right,bottom,left}` leaves `{{SIZE}}` empty and the rule is never printed.
- A switcher turns **on for any value**, including `'none'`.
- Every group control (Typography, Border, Box Shadow) is gated by its own `popover_toggle`.
  Writing `icon_typography_font_size` without also writing
  `icon_typography_typography => 'custom'` prints nothing at all.
- Selectors use `>`, so an extra wrapper in your markup means a perfectly configured control
  emits CSS that never matches.

`references/elementor-traps.md` catalogues the ones that have already cost time. Read it
once at the start of a project; check it before guessing.

## Widget map — backlog block to native widget

Start here; extend per project.

| Backlog block | Native widget |
|---|---|
| Heading, eyebrow, section title | **Heading** (`header_size` div/p/h2 — never `span`) |
| Body copy, prose | **Text Editor**, or **Theme Post Content** inside a singular template |
| Bullet list, spec chips, meta rows | **Icon List** |
| Button, CTA | **Button** |
| Card grid of posts/products | **Loop Grid** + a **Loop Item** template |
| Filter pills / faceted filters | **Taxonomy Filter** (bind via `query_id`) |
| Breadcrumb | **Shortcode** widget + the SEO plugin's shortcode |
| Post meta row (date, author, terms) | **Post Info** |
| Author block | **Author Box** |
| Share row | **Share Buttons** |
| Table of contents | **Table of Contents** |
| Image gallery | **Gallery** (reads an ACF gallery through a dynamic tag) |
| Accordion / FAQ | **Accordion**, or Heading + Text with Custom Code for bespoke markup |
| Tabs / showcase switcher | **Tabs** |
| Star rating | **Rating** |
| Countdown, progress, counters | **Countdown**, **Progress Bar**, **Counter** |
| Sticky CTA bar | Container with **Responsive → Visibility** + `position:fixed` in CSS |
| Map | Shortcode widget wrapping the project's map implementation |

If a block appears to need something not on this list, that is a signal to re-read the
widget source, not to build a custom widget.

## Layout: containers, never legacy sections

- Use **Container** (Flexbox or Grid), not the deprecated Section/Column.
- Match the backlog's content width with a **Boxed** container; full-bleed backgrounds use a
  **Full Width** container with a Boxed child.
- Padding, gap, direction, alignment, wrap: **from the panel**, not CSS.
- A Grid container takes `container_type: grid` plus `grid_columns_grid`. Elementor accepts a
  **custom** value there, so `repeat(auto-fit,minmax(160px,1fr))` is a panel setting, not CSS.

### Container defaults that bite

Elementor containers carry an unwritten **10px padding** and **20px gap** that do not appear
in `settings`. You cannot detect them with "if empty" — compare against the value you *want*
and set it explicitly. A recursive normaliser that zeroes padding and gap on containers with
no explicit value is usually the first thing a build script needs.

`width` on a container only applies when `content_width` is `full`.

## Theme Builder

Templates, not pages, render most repeated content.

- **Singular** templates for post types; **Archive** templates for listings; **Header**,
  **Footer**, **Loop Item** for the rest.
- Conditions live in post meta `_elementor_conditions`, e.g.
  `include/product/in_product_cat/205`, `include/archive/post_archive`, `exclude/product`.
- **After writing conditions from code you must rebuild the cache**, or the template is
  simply never used:

  ```php
  $mod = \ElementorPro\Plugin::instance()->modules_manager->get_modules( 'theme-builder' );
  $mod->get_conditions_manager()->get_cache()->regenerate();
  ```

  Deleting the `elementor_pro_theme_builder_conditions` option instead is **not** the fix —
  that makes every archive template stop matching.
- Keep a fallback template with a broader condition so URLs outside your specific conditions
  still render.

## Dynamic content

- Bind fields with **dynamic tags**, stored in a widget's `__dynamic__` map.
- In a repeater, the dynamic tag belongs to `__dynamic__` **of that row**, not of the control
  — otherwise the shortcode prints raw.
- There is **no `post-content` dynamic tag**. Use the **Theme Post Content** widget. An
  unknown tag fails silently: the widget simply prints nothing.
- Loop Grid category locking uses `post_query_include: ['terms']` plus
  `post_query_include_term_ids` holding **bare `term_taxonomy_id` values**. A prefixed form
  like `product_cat:205` silently matches everything.
- Give a Loop Grid a `post_query_query_id` when the theme needs to alter its query; that opens
  the `elementor/query/<id>` hook.

### When no widget owns the data

Rung 5: create a derived ACF field and populate it with a script, then bind a native widget
to it. The widget stays native; only the data is prepared. This is how a design that needs,
say, a formatted price string or a computed night count stays inside Elementor.

## Global Colors and Global Fonts

Register the backlog tokens as Elementor Globals and **bind widgets to them**, rather than
saving hex values into each widget.

- Binding lives in `__globals__`, e.g.
  `"__globals__": { "title_color": "globals/colors?id=ktcobalt" }`.
- A **global binding always beats a literal value.** Setting `title_color` on a widget that
  has a `__globals__` entry for it changes nothing — you must retarget or remove the binding.
  This surprises people repeatedly; it is the first thing to check when a colour "won't
  change".

`scripts/globals-bind.php` finds widget settings whose hex matches a registered global and
binds them, leaving already-bound settings alone.

## Writing changes safely

Every change is a script. The shipped library handles the boilerplate:

```php
require __DIR__ . '/elementor-lib.php';
$doc = el_load( 19271 );                    // decoded _elementor_data
el_walk( $doc, function ( &$el ) { ... } ); // recursive edit
el_save( 19271, $doc, 'my-change' );        // backup + write + autosave fix
```

Three rules that are not optional:

1. **Back up before writing, per document, in post meta.** One giant option overflows
   MySQL's `max_allowed_packet` and the backup silently fails.
2. **Run the autosave fix after every write.** `update_post_meta()` does not touch
   `post_modified`, so Elementor's editor keeps loading a stale autosave and your change
   appears to vanish. `el_save()` does this for you; if you write meta directly, call
   `php scripts/autosave-fix.php apply` yourself.
3. **Be idempotent.** Compare against the value you want, never against "is it empty".

Then flush Elementor's CSS cache so the generated stylesheet is rebuilt:

```php
\Elementor\Plugin::$instance->files_manager->clear_cache();
```

## Custom CSS, when it is genuinely needed

Only after rungs 1–2 fail. Write it to **Site Settings → Custom CSS**, in a block delimited
by markers owned by exactly one script:

```
/* KT <tool name> */
… rules …
/* KT <tool name> selesai */
```

- The closing marker must be a **complete comment**. Writing `/* x */ selesai` leaves the
  word outside the comment, and that stray text swallows the first rule of the next block.
- A tool rewrites **its own block only** — remove and re-add. Never append a new copy, and
  never truncate to end-of-file, or two tools will delete each other's work forever.
- Say in the docblock which panel control you checked and why it could not do the job.

## JavaScript, when behaviour is needed

Elementor → **Custom Code** snippets. Store the code in post meta `_elementor_code` (not
`post_content`), set `_elementor_template_type` to `code_snippet`, and set a
`_elementor_location`. From CLI, `wp_kses_post()` strips `<script>` unless a user with
`unfiltered_html` is set — call `wp_set_current_user()` with an administrator first.

Renaming a snippet leaves the old one published: the script then runs twice and handlers
fire twice. Delete the old one.
