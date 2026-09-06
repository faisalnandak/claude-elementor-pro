# claude-elementor-pro

A Claude Code plugin for building WordPress sites with **native Elementor Pro** from a folder
of HTML mockups.

It exists because the hard part of this work is not writing code — it is *not* writing code.
The temptation on every design block is to drop a snippet into the theme or a rule into Custom
CSS. This plugin encodes the opposite habit: find the Pro widget, read its real controls, and
configure it.

Distilled from a full site rebuild — 92 pages, 34 products, six Theme Builder templates — that
shipped with **zero custom Elementor widgets**.

---

## Install

```bash
git clone https://github.com/faisalnandak/claude-elementor-pro.git
```

Then, in Claude Code:

```
/plugin install /path/to/claude-elementor-pro
```

Or drop the folder into a marketplace directory you already load.

The scripts need PHP on the PATH and a local WordPress install. They find `wp-load.php` by
walking up from the working directory; pass `--wp=/path/to/wordpress` or set `WP_ROOT` when
running from elsewhere.

---

## The decision ladder

Every design block goes down this ladder in order. Most of the value is in rungs 1–2.

| Rung | Question | Answer |
|---|---|---|
| 1 | Is there a Pro widget for this? | Use it. Build nothing. |
| 2 | There is one, but it does not match? | Configure it from the panel — **read the widget's real controls first** |
| 3 | The panel genuinely has no control? | Custom CSS via Site Settings, with the reason written down |
| 4 | You need behaviour, not appearance? | JavaScript via Elementor → Custom Code |
| 5 | You need data no widget owns? | A derived ACF field; the widget stays native |
| 6 | Still impossible? | Stop. Raise a Decision Questionnaire and wait. |

Ten blocks that "obviously needed a custom widget" in the reference project turned out to be
native all along: breadcrumb → Shortcode widget, author row → Post Info, article footer →
Author Box + Share Buttons, timeline dots → Icon List with a dynamic tag.

---

## What is inside

### Skills

| Skill | Loads when |
|---|---|
| `elementor-workflow` | Start here. Phase order, the ladder, the hard rules, which skill each phase needs |
| `backlog-analysis` | Surveying the install and reading the mockups, before any building |
| `elementor-build` | Translating a block into a widget; containers, Theme Builder, dynamic content |
| `visual-verify` | Playwright comparison at 1280 / 768 / 390, and how to avoid false alarms |
| `css-audit` | Auditing Custom CSS and moving what the panel can own |

### Commands

| Command | Does |
|---|---|
| `/elementor-survey` | Intake: install survey + backlog read + mockup→target table |
| `/elementor-page` | Take one page from mockup to verified implementation |
| `/elementor-verify` | Three-width comparison of a live page against its mockup |
| `/elementor-css-audit` | Full-project Custom CSS audit |
| `/elementor-decide` | Turn the open unknowns into a Decision Questionnaire |

### Scripts

All read-only unless they say otherwise. Every writing script is dry-run by default.

| Script | Does |
|---|---|
| `elementor-lib.php` | The library: load, walk, back up, save, autosave fix, CSS blocks |
| `el-inspect.php` | `env`, `docs`, `tree`, `settings`, `find`, `widgets`, `css`, `conditions` |
| `el-widget-controls.php` | A widget's real controls: type, default, condition, selectors |
| `backlog-tokens.php` | Harvest `:root` tokens from the mockups; propose Globals |
| `globals-bind.php` | Bind literal widget colours to Global Colors *(writes)* |
| `autosave-fix.php` | Stop Elementor's editor reverting scripted changes *(writes)* |
| `css-audit.php` | Inventory, owned blocks, rules, verdicts, selector list, colours |
| `site-sweep.php` | Fetch every public URL; check for fatals, notices, broken markup |
| `visual-compare.js` | Playwright measurement helpers, including the colour fingerprint |

### References

| File | Contains |
|---|---|
| `elementor-traps.md` | Every trap that cost real time, symptom first |
| `widget-map.md` | Design block → native widget, with the controls you will need |
| `safe-tooling.md` | How to write a script that changes a site without losing work |

---

## Quick start

```bash
# 1. what am I working with?
php scripts/el-inspect.php env

# 2. what does the backlog ask for?
php scripts/backlog-tokens.php /path/to/product-backlog

# 3. what controls does this widget really have?
php scripts/el-widget-controls.php loop-grid --selectors

# 4. what is in the Custom CSS?
php scripts/css-audit.php inventory
php scripts/css-audit.php classify

# 5. did anything break?
php scripts/site-sweep.php
```

---

## Three things that will save you an afternoon

**`update_post_meta()` does not touch `post_modified`.** Elementor keeps loading a newer
autosave, so the front end shows your change, the editor shows the old version, and the next
editor save destroys your work. Run `php scripts/autosave-fix.php apply` after every write —
or use `el_save()`, which does it for you.

**A `__globals__` binding beats a literal value.** Writing a new hex to a bound colour setting
changes nothing at all. This is the first thing to check when a colour refuses to change.

**`get_controls()` hides the whole style tab outside the editor.** In Elementor 4.x, style
controls live in a separate stack. Enumerate a widget from CLI and typography, colour and
spacing simply are not there. `el-widget-controls.php` reads `get_stack()` and merges both.

The rest are in [`references/elementor-traps.md`](references/elementor-traps.md).

---

## House rules the skills enforce

- Never add a custom implementation to the theme when Elementor Pro can do it.
- Never replace an Elementor Pro feature with another framework.
- Never install a plugin without asking first, with reasons.
- Style and behaviour go through Elementor — Site Settings → Custom CSS, Elementor → Custom
  Code — never loose files in the theme.
- **Never invent content.** No placeholder copy, no fabricated numbers, no invented reviews.
  A gap the backlog does not fill is a question for the user.
- Every change is a reversible script with a backup, not an editor session.
- Verify by measuring, at three widths, and then by looking at the screenshot.

---

## Provenance

Built from the Karang Travel project — a Spanish-language Indonesian dive and adventure travel
site on WordPress 7 / Elementor Pro 4.2 / ACF Pro, with only four active plugins. Cases quoted
in the skills are from that build: the 10,207-byte legacy CSS block removed after every
selector was verified dead across 92 pages, the 726 widget colour settings bound to Global
Colors, the gallery whose wrapper took three attempts to build.

Where a lesson is version-specific it says so, and where the root cause was never fully
established the text says that too.

## Licence

MIT — see [LICENSE](LICENSE).
