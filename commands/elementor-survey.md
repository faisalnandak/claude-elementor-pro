---
description: Survey a WordPress + Elementor Pro install and read the product-backlog before any building starts
argument-hint: "[path to the backlog folder]"
---

Run the intake phase for this project. Load the `backlog-analysis` skill and follow it.

Backlog folder: $ARGUMENTS (if empty, look for `product-backlog/` in the site root).

Do all of this before proposing any implementation:

1. `php scripts/el-inspect.php env` — record Elementor Pro presence, theme, ACF, post types,
   and every Theme Builder template with its conditions.
2. `php scripts/backlog-tokens.php <folder>` — harvest the design tokens and note any token
   whose value disagrees between mockup files.
3. Serve the mockups over HTTP and open them; if `curl` shows almost nothing but the browser
   shows a full page, they are self-unpacking bundles and must be read through Playwright.
4. Build the mockup → live target table, confirming which document actually renders each URL
   by reading the root `elementor-<id>` class rather than assuming.
5. Produce a block inventory per page: each visual block and the **native widget** intended
   for it.

Finish by presenting the unknowns as a Decision Questionnaire — a clickable question panel,
one panel batching related decisions, each option grounded in something you measured. Do not
start building until it is answered.
