# Widget map

Which native widget builds which design block, and the control names you will actually need.

**Always confirm control names against the plugin source** — this file is a starting point,
and control names change between Elementor versions:

```bash
php scripts/el-widget-controls.php <widget-name>
```

Widget PHP lives in:

```
wp-content/plugins/elementor/includes/widgets/            free
wp-content/plugins/elementor-pro/modules/*/widgets/       Pro
```

---

## Text and headings

| Block | Widget | Key controls |
|---|---|---|
| Section title, eyebrow, kicker | `heading` | `title`, `header_size` (use `div`/`p`/`h2`, never `span`), `align`, `title_color`, `typography_*` |
| Paragraph, prose, rich text | `text-editor` | `editor`, `align`, `text_color`, `typography_*` |
| Post body inside a singular template | `theme-post-content` | none — it renders the post |
| Quote / pull quote | `blockquote` | `blockquote_content`, `blockquote_skin` |

Alignment belongs to the `align` control, not to inline `style="text-align:…"` in the editor
HTML. Inline alignment is invisible to the panel and to every later audit.

## Lists and specification rows

| Block | Widget | Key controls |
|---|---|---|
| Bulleted list, feature list | `icon-list` | `icon_list` repeater (`text`, `selected_icon`, `link`), `view` (`traditional`/`inline`), `space_between`, `icon_color` |
| Spec chips / meta grid | `icon-list` with `view: inline` inside a Grid container | grid columns on the **container** |
| Numbered steps | `icon-list` with number icons, or `counter` per step | |

Typography on `icon-list` prints to `.elementor-icon-list-item > .elementor-icon-list-text,
.elementor-icon-list-item > a`, and only once `icon_typography_typography` is set to `custom`.
Both catch scripts out — see `elementor-traps.md`.

## Buttons and calls to action

| Block | Widget | Key controls |
|---|---|---|
| Button | `button` | `text`, `link`, `size`, `align`, `selected_icon`, `icon_align`, `button_css_id` |
| Icon button (WhatsApp, phone) | `button` | `selected_icon` = `['value' => 'fab fa-whatsapp', 'library' => 'fa-brands']` |
| Button pair | two buttons in a flex container | container `flex_direction`, `flex_gap` |
| Sticky bottom bar | container + Responsive visibility | `position: fixed` in CSS (rung 3) |

A brand button style belongs in a CSS class applied through `_css_classes`, or better, in the
kit's global button styles — not repeated per widget.

## Listings and archives

| Block | Widget | Key controls |
|---|---|---|
| Card grid of posts/products | `loop-grid` | `template_id`, `columns` + `columns_tablet` + `columns_mobile`, `_skin`, `post_query_*` |
| The card itself | a **Loop Item** Theme Builder template | |
| Category filter pills | `taxonomy-filter` | `taxonomy`, `query_id` matching the grid's `post_query_query_id` |
| Search results, related posts | `loop-grid` with a query preset | |
| Pagination | built into `loop-grid` | `pagination_type` |

Responsive column counts are three separate controls. Setting `columns` alone leaves mobile
at the widget default.

## Post furniture

| Block | Widget |
|---|---|
| Date, author, terms, reading time | `theme-post-info` |
| Featured image | `theme-post-featured-image` |
| Title | `theme-post-title` |
| Breadcrumb | `shortcode` wrapping the SEO plugin's shortcode |
| Author bio block | `author-box` |
| Share row | `share-buttons` |
| Table of contents | `table-of-contents` |
| Prev/next links | `post-navigation` |

Ten blocks that looked like custom-widget work in the reference project were all on this list.
Check here before concluding something is not native.

## Media

| Block | Widget | Notes |
|---|---|---|
| Single image | `image` | alignment via `align`, not CSS |
| Gallery | `gallery` | binds an ACF gallery through a dynamic tag; beware `e-gallery--animated` |
| Carousel / slider | `media-carousel`, `image-carousel` | |
| Video | `video` | |
| Before/after | `image-comparison` | |

## Interactive

| Block | Widget |
|---|---|
| Accordion / FAQ | `accordion` (or `nested-accordion`) |
| Tabs | `tabs` (or `nested-tabs`) |
| Toggle | `toggle` |
| Star rating | `rating` |
| Counter, progress, countdown | `counter`, `progress`, `countdown` |
| Form | `form` |
| Popup trigger | Popup Theme Builder template + a button link |

## Header and footer

| Block | Widget |
|---|---|
| Logo | `theme-site-logo` |
| Menu | `nav-menu` |
| Search | `search-form` |
| Site title / tagline | `theme-site-title` |
| Cart / account | WooCommerce widgets, when Woo is present |

`theme-site-logo` renders without a `<figure>` wrapper. Do not write CSS for a wrapper that
is not there.

## Layout primitives

| Need | Use |
|---|---|
| Row / column layout | Container, `container_type: flex` |
| Real CSS grid | Container, `container_type: grid`, `grid_columns_grid` (accepts a custom value) |
| Full-bleed band with boxed content | Full-width container, boxed child |
| Divider, spacer | `divider`, `spacer` |
| Arbitrary HTML | `html` — last resort; nothing about it is stylable from the panel |

---

## When nothing fits

Work down the ladder, in order:

1. Re-read the widget source — the control probably exists under an unexpected name.
2. Combine two widgets in a container before inventing anything.
3. `shortcode` widget wrapping a function that already exists in a plugin.
4. A derived ACF field + a native widget bound to it.
5. Decision Questionnaire.

A custom Elementor widget is not on this list. The reference project shipped without one.
