# Elementor traps

Every entry here cost real time on a real build. Symptom first, because that is how you will
meet them.

---

## Data model

### `css_classes` does nothing — the key is `_css_classes`

**Symptom:** you write a CSS class into a widget's settings, the value is stored, the page is
unchanged.

Elementor's *Advanced → CSS Classes* control is named `_css_classes` (leading underscore).
`css_classes` exists on some widgets for other purposes and is not what gets printed.

When scripting, write **both** — harmless, and it survives whichever the widget uses:

```php
$el['settings']['_css_classes'] = $classes;
$el['settings']['css_classes']  = $classes;
```

### A `__globals__` binding always beats a literal value

**Symptom:** you set `title_color` to a new hex, save, flush cache — the old colour renders.

If the widget has `settings['__globals__']['title_color']`, the literal is ignored entirely.
Retarget the binding, or unset it, before writing a literal.

This is the first thing to check whenever a colour "refuses to change".

### `__dynamic__` in a repeater lives on the row

**Symptom:** the shortcode or tag prints as raw text.

A dynamic tag inside a repeater belongs to that **row's** `__dynamic__` map, not to the
widget's.

### There is no `post-content` dynamic tag

**Symptom:** the widget renders nothing at all, with no error.

Use the **Theme Post Content** widget instead. Unknown dynamic tags fail silently — a blank
widget is the only signal you get.

### JSON escapes the forward slash

**Symptom:** you count occurrences of `globals/colors` in `_elementor_data` and find zero, or
far fewer than expected.

`wp_json_encode()` writes `globals\/colors`. Count both forms, or decode first.

> This produced a false "all the bindings were lost" panic. The real count was 820.

---

## Controls

### Read the control type before writing a value

A control that looks like a spacing box in the UI may be a **SLIDER**. Writing
`{'top'=>16,'right'=>0,…}` to a slider leaves `{{SIZE}}` empty, and Elementor prints no rule
at all. `scripts/el-widget-controls.php` prints the real type.

### A switcher is on for any non-empty value

Including `'none'`. If you mean off, use an empty string.

### `hide_desktop` takes `hidden-desktop`, not `hidden`

**Symptom:** the class prints as `elementor-hidden` twice and the element stays visible.

From `element-base.php`: `'return_value' => 'hidden-' . $breakpoint_key`. So the stored values
are `hidden-desktop`, `hidden-tablet`, `hidden-mobile`.

### A group control does nothing until its popover toggle is set

**Symptom:** you write `icon_typography_font_size` from a script, the value is stored, no CSS
is printed.

Every group control (Typography, Border, Box Shadow, Background) starts with a
`popover_toggle` control, and **every sub-control is conditioned on it**. For Icon List:

```
icon_typography_typography    popover_toggle  return_value=custom
icon_typography_font_size     slider          when {"icon_typography_typography!":""}
```

So the group's own key must be set too:

```php
$el['settings']['icon_typography_typography'] = 'custom';
$el['settings']['icon_typography_font_size']  = [ 'unit' => 'px', 'size' => 15 ];
```

The panel does this for you; a script does not. Verified against Elementor 4.2 with
`php scripts/el-widget-controls.php icon-list --grep=typography`.

### `get_controls()` hides every style control outside the editor

**Symptom:** you enumerate a widget's controls from CLI and typography, colour and spacing are
simply absent — Icon List reports 228 controls and not one of them mentions typography.

In Elementor 4.x the style tab lives in a **separate stack**. `Controls_Stack::get_controls()`
merges `$stack['style_controls']` only when `Performance::is_use_style_controls()` is true,
which it is not on a CLI request. Read the stack directly:

```php
$stack = $widget->get_stack();
$all   = $stack['controls'] + ( $stack['style_controls'] ?? [] );
```

`scripts/el-widget-controls.php` already does this.

### `>` in a selector means direct child

Icon List typography prints to
`.elementor-icon-list-item > .elementor-icon-list-text, .elementor-icon-list-item > a`.
Text sitting one wrapper deeper than the widget's own markup gets nothing, and no error says
so. Check the printed selector with `--selectors` before concluding a control is broken; when
the markup genuinely cannot match, that is a legitimate rung-3 Custom CSS case — record the
reason.

### Loop Grid term filtering needs bare `term_taxonomy_id`

`post_query_include => ['terms']` plus `post_query_include_term_ids => [205]`.

A prefixed value like `product_cat:205` does not error; it silently matches everything, and
every category page shows every product.

---

## Containers

### Invisible defaults: 10px padding, 20px gap

They are not present in `settings`, so "if empty, set it" is not a valid test — the element
already renders with them. Compare against the value you want and write it explicitly.

### `width` only applies when `content_width` is `full`

Setting a width on a boxed container changes nothing.

---

## Theme Builder

### Conditions need a cache rebuild after a scripted write

**Symptom:** the template's conditions look right in the database and in the UI, but the URL
renders a different template.

```php
$mod = \ElementorPro\Plugin::instance()->modules_manager->get_modules( 'theme-builder' );
$mod->get_conditions_manager()->get_cache()->regenerate();
```

Deleting the `elementor_pro_theme_builder_conditions` option is **not** an alternative — that
breaks archive matching site-wide.

### Confirm which template actually rendered

Read the root element's class on the live page: `elementor-19271` means document 19271
rendered it. Assumption here wastes whole afternoons.

---

## Writing to the database

### `update_post_meta()` does not touch `post_modified`

**Symptom:** the front end shows your change; the Elementor editor shows the old version, and
opening and saving the editor wipes your work.

Elementor compares the autosave revision's timestamp against `post_modified`. Because the meta
write left `post_modified` alone, the stale autosave wins. After every `_elementor_data`
write, bump `post_modified` and clear the offending autosave:

```bash
php scripts/autosave-fix.php apply
```

`el_save()` in the shipped library does this for you.

### Then flush the CSS cache

```php
\Elementor\Plugin::$instance->files_manager->clear_cache();
```

Elementor serves a generated stylesheet per document; without this your panel settings are in
the database but not in any CSS file.

### A single backup option can exceed `max_allowed_packet`

**Symptom:** the backup "succeeds" but the option is empty, and there is nothing to restore.

MySQL's default `max_allowed_packet` is 1MB. A whole-site `_elementor_data` snapshot passes
that easily. Store backups **per document, in post meta**, and **write them before** applying
any change.

### Custom Code snippets

- The code lives in post meta `_elementor_code`, not `post_content`.
- `_elementor_template_type` is `code_snippet`, and a `_elementor_location` must be set.
- From CLI, `wp_kses_post()` strips `<script>` unless the current user has `unfiltered_html`.
  Call `wp_set_current_user( <admin id> )` first.
- Renaming a snippet by creating a new one leaves the old one **published**: the code runs
  twice and every handler fires twice. Delete the old post.

### Custom CSS block markers must be complete comments

```
/* KT my-tool */
…
/* KT my-tool end */      <- correct
/* KT my-tool */ end      <- the stray word swallows the next block's first rule
```

A tool owns exactly one marker pair and rewrites only between them. Never append a second
copy; never truncate to end of file.

---

## PHP inside WordPress

### Do not name a global-scope variable `$acf`, `$l10n`, `$wp`, `$post`

**Symptom:** `Call to a member function init() on int`.

A script running at global scope shares the scope with WordPress's own globals. Assigning
`$acf = 5;` overwrites `$GLOBALS['acf']`, the live ACF instance. Prefix every variable in a
CLI script, or wrap the whole script in a function.

### `acf_flush_field_cache()` throws on a bare key

`Undefined array key "parent"`. Use `wp_cache_flush()` instead.

### `!==` on arrays compares key order

Two identical declaration sets in different order compare as different. `ksort()` both sides
before comparing, or your diff comes back empty and you conclude nothing needs doing.

### A by-reference parameter cannot be rebound inside a function

```php
function f( array &$els ) { $x = &$els; }   // breaks the caller's link
```

`array_splice(): Argument #1 must be of type array, null given` is the usual outcome. Locate
elements by **index path** and resolve the reference at the top level instead.

---

## Front end

### `e-gallery--animated` makes items `position: absolute`

**Symptom:** on mobile, gallery items flicker or jump during scroll.

Elementor's animated gallery layout absolutely-positions items and recalculates on resize.
On mobile, browser chrome collapsing counts as a resize. Use the non-animated layout, or pin
the geometry in CSS.

### Elementor rebuilds widgets after load

A gallery's final DOM does not exist at `DOMContentLoaded`. Three approaches failed in order:
`DOMContentLoaded` (too early), a `MutationObserver` that disconnects on first hit (fires on
an intermediate state), and an observer scoped to the widget (never fired at all — the reason
was never fully established).

What works: observe `document.body` with `subtree: true`, plus a **bounded** polling interval
as a backstop (150ms × 40), and an idempotent handler.

### Use capture-phase listeners on rebuilt widgets

Elementor attaches its own handlers to gallery items. A bubble-phase listener runs after
Elementor has already opened its lightbox. Capture phase, plus `ev.detail === 0` to tell a
keyboard activation from a mouse click.

### `header_size: span` breaks section height

A `<span>` heading is inline; the container computes a different height and the section
collapses. Use `div`, `p`, or a real heading level.

### `ch` units resolve against the wrapper's font

`max-width: 60ch` on a widget wrapper is computed from the **wrapper's** font-size, which is
usually the body font, not the heading you were trying to constrain. Put the rule on the text
element.

### Unbalanced markup in `post_content` moves things silently

**Symptom:** a button inside a wrapper is not clickable, or a section ends in the wrong place.

An unclosed `<div>` in post content re-parents everything after it. In the reference project a
"read more" button was pushed out of its overview wrapper on 34 products; the fix was 56 bytes
each. Balance the tags before blaming CSS or JS.
