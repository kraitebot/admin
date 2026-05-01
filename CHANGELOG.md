# Changelog

All notable changes to the admin.kraite.com project.

## [0.1.1] — 2026-05-02

### Fixes
- [BUG FIX] Stress fill bar on dashboard now spans current TP marker → current price marker (was rendering from left edge / first TP price).

### Improvements
- [IMPROVED] Stress fill colour scale: 0–50% green, 50–75% warning, 75+ danger (removed mid-band info colour that made 31% read as blue).
- [IMPROVED] System dashboard layout rebuilt — Hero gauge + Direction + Stats now in a unified KPI strip card with proper spacing across breakpoints; BSCS panel stands alone with full-width manual override row; Exchanges panel stands alone. Killed broken 3-column wrapper that was cramping the centre column.
- [IMPROVED] /system/sql-query default page size dropped from 20 → 15 rows; per_page validation extended to accept 15.
