---
name: elementor-workflow
description: Use when building or redesigning a WordPress site with Elementor Pro from a product-backlog folder of HTML mockups. Establishes the phase order, the native-first decision ladder, and which companion skill to load for each phase. Load this first when a project has a product-backlog and a local WordPress + Elementor Pro install.
---

# Elementor Pro build workflow

You are implementing a design that already exists — as HTML mockups in a `product-backlog`
folder — inside a live WordPress site running Elementor Pro. Your job is to reproduce the
design **using the page builder that is already installed**, not to write a parallel
implementation beside it.

Everything here is distilled from a full site rebuild that shipped with **zero custom
widgets**. That outcome was not luck; it came from taking step 2 of the decision ladder
seriously every single time.

## The decision ladder — apply to every block

Never skip a rung. Most of the value is in rungs 1–2.

1. **Is there a Pro widget for this?** → Use it. Build nothing.
2. **There is one, but it does not match?** → Configure it from the panel (Layout / Style /
   Advanced). **Open the widget's PHP file** in
   `wp-content/plugins/elementor-pro/modules/*/widgets/` to read the real control names —
   do not guess them from the UI. Control names and shapes are frequently surprising, and
   guessing is the single most common way to waste an hour here.
3. **The panel genuinely has no control?** → Custom CSS via **Site Settings → Custom CSS**.
   Write the reason in the tool's docblock and name the plugin file you checked.
4. **You need behaviour, not appearance?** → JavaScript via **Elementor → Custom Code**.
   Not the theme, not a manual `wp_enqueue_script`.
5. **You need DATA no widget owns?** → A derived **ACF field** populated by a script; the
   widget itself stays 100% native.
6. **Still impossible?** → **Stop.** Raise a Decision Questionnaire and wait.

Rungs 1 and 2 must be exhausted before you write one line of CSS.

### Why rung 2 pays

In the reference project, ten blocks that "obviously needed a custom widget" turned out to
be native all along: breadcrumb → Shortcode widget, author row → Post Info, article footer
→ Author Box + Share Buttons, timeline dots → Icon List with a dynamic tag.

## Hard rules

These override any technical preference:

- **Never add custom implementation to the theme** when Elementor Pro can do it.
- **Never replace an Elementor Pro feature** with another framework or library.
- **Never install a plugin without asking first.** Explain what it is for and wait.
- **Style and behaviour customisation goes through Elementor** (Site Settings → Custom CSS,
  Elementor → Custom Code), never loose files in the theme.
- **Never invent content.** No placeholder copy, no fabricated numbers, no invented
  reviews. If the backlog does not supply a value and the site has no data for it, that is
  a questionnaire item, not a gap to fill with plausible text.
- **When something is ambiguous, ask.** Use a Decision Questionnaire (see the
  `decision-questionnaire` section below) and wait for the answer.

## Phase order

Work the phases in order. Do not start building before the backlog is understood.

| Phase | What happens | Load skill |
|---|---|---|
| 1. Intake | Survey the WordPress install and read the backlog | `backlog-analysis` |
| 2. Plan | Map every backlog block to a native widget; list unknowns | `elementor-build` |
| 3. Build | One page at a time, one script per change | `elementor-build` |
| 4. Verify | Playwright at 1280 / 768 / 390 against the mockup | `visual-verify` |
| 5. Fix | Close each measured difference; re-measure | `visual-verify` |
| 6. Audit | Custom CSS audit; move what the panel can own | `css-audit` |

Phases 4 and 5 loop until the differences are only content-length differences.

## One page at a time, to completion

Do not spread half-finished work across many pages. Take one page through build → verify →
fix → done, then move on. A page is done when the measured differences against its mockup
are explained, not merely small.

## Every change is a reversible script

Never edit `_elementor_data` by hand or through ad-hoc one-liners. Every change is a script
in the project's `tools/` folder with three modes:

```
php tools/<name>.php            # dry run — prints the plan, writes nothing
php tools/<name>.php apply      # apply
php tools/<name>.php pulihkan   # restore from backup
```

The shipped library at `scripts/elementor-lib.php` gives you this shape for free. Read
`references/safe-tooling.md` before writing the first one — it covers the backup rules, the
autosave trap that silently reverts your work, and idempotency.

## Decision Questionnaire

When a choice is genuinely the user's — brand colour, missing content, a layout the backlog
does not settle, a plugin that would need installing — do not pick for them.

Present it as a **clickable question panel**, not prose and not a line buried in a document.
Each option states the trade-off honestly, including the option you did not recommend.
Ground every option in a measured fact, not an impression.

Batch related decisions into one panel rather than interrupting repeatedly. Then record the
answers in the project's decision log so the reasoning survives.

## Reporting

Report what you measured, not what you assume. When you state a number, it should be one
you read from `getBoundingClientRect()` or `getComputedStyle()` in this session. When a
check contradicts an earlier claim of yours, correct it plainly and move on.
