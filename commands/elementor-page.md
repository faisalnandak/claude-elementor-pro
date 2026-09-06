---
description: Take one page from backlog mockup to finished Elementor Pro implementation, verified at three widths
argument-hint: "<page name or URL>"
---

Work this page to completion: $ARGUMENTS

Load `elementor-build`, then `visual-verify`. One page at a time — do not spread half-finished
work across several.

1. Identify the document that actually renders the URL (`php scripts/el-inspect.php conditions`,
   then confirm from the rendered root class).
2. For every block, walk the decision ladder in order. Rungs 1–2 must be exhausted before any
   CSS: read the widget's real controls with
   `php scripts/el-widget-controls.php <widget>` rather than guessing names from the panel.
3. Make each change a reversible script with dry-run / apply / restore modes, using
   `scripts/elementor-lib.php`. Back up per document before writing; run the autosave fix and
   flush the CSS cache after.
4. Verify with Playwright at 1280 / 768 / 390 against the mockup: sections, grids, component
   boxes, typography, horizontal overflow — in that order. Take screenshots and look at them.
5. Close each measured difference and re-measure. The page is done when every remaining
   difference is explained, not merely small.
6. Sweep before reporting: `php scripts/site-sweep.php`.

Report what you measured, with numbers you read this session. If something is ambiguous, raise
a Decision Questionnaire instead of assuming.
