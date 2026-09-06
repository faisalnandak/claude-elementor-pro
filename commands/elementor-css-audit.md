---
description: Audit every Custom CSS block in the project and move what the Elementor panel can own back to native controls
---

Load `css-audit` and run the full audit across the whole project — not one page.

1. `php scripts/el-inspect.php css` and `php scripts/css-audit.php inventory` — every
   location: kit, per page, per element, and Custom Code snippets.
2. `php scripts/css-audit.php classify` — panel-able / dead / superseded / duplicated value /
   legitimate. The classifier is a heuristic; confirm each verdict before acting on it.
3. Dead selectors need a browser: `php scripts/css-audit.php selectors`, then test them
   against one URL of every template type with `KT.deadSelectors()`.
4. `php scripts/globals-bind.php` for repeated colours.
5. Work in stages — dead and legacy blocks, then superseded rules, then panel-able rules by
   category, then globals — verifying between each.

Every stage ends with proof that rendering did not change: an identical colour fingerprint on
representative pages, screenshots at three widths, and `php scripts/site-sweep.php` clean.

The goal is not less CSS. It is that every surviving rule has a written reason why the panel
could not express it.
