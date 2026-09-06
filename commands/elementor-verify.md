---
description: Compare a live page against its backlog mockup with Playwright at 1280, 768 and 390
argument-hint: "<live URL> <mockup URL>"
---

Verify: $ARGUMENTS

Load `visual-verify` and follow it. Both URLs over HTTP, never `file://`.

At each of 1280, 768 and 390:

1. Paste `scripts/visual-compare.js` into `browser_evaluate` and run `KT.report()` on both
   pages. Diff the section table first.
2. Then grids (`KT.box`), then component boxes, then typography (`KT.type`), then
   `KT.overflow()`.
3. Screenshot both and look at them. A matching number and a right-looking page are different
   claims.

Guard against the usual false alarms: backlog classes sit on the widget wrapper, not the text;
a Loop Grid's gap is on `.elementor-grid` inside the wrapper; `.mainnav a` may be matching the
logo. Print an element's text before believing a surprising measurement.

Report a table of real differences with the measured values on both sides. Say plainly which
differences are content-length rather than defects.
