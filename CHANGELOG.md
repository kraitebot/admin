# Changelog

All notable changes to the admin.kraite.com project.

## [0.2.0] — 2026-05-04

### Features
- [NEW FEATURE] **Lifecycle configurator** at `/system/lifecycle`. Manual position-lifecycle walkthrough — Excel-style spreadsheet where rows are token positions and columns are T-frames the operator advances by hand. Per-token bot config (gap %, ladder size, multipliers, TP %, SL %, leverage, margin/position, base qty) is frozen into each scenario at creation so the math stays reproducible if the live config drifts later. Pure client-side calc engine (Alpine + JS): WAP, TP per ladder depth (L0–L3 = WAP±0.36%, L4 = breakeven), fixed SL at deepest-limit ± SL%, auto TP/SL exits on price-cross, realised + unrealised PnL, portfolio aggregation. Branching supported (full snapshot at branch point — parent edits do NOT propagate). Autosave debounced 500 ms. Side-by-side compare pane skeleton in place for v2. Sidebar entry under System group, admin-gated.

### Fixes
- [BUG FIX] **Restored `binance_listen_keys` table after orphan-migration cascade.** The April-6 `2026_04_06_103943_drop_binance_listen_keys_table.php` migration sat dormant in this repo for ~4 weeks; ran for the first time tonight as a side-effect of a routine `php artisan migrate --force` and silently destroyed the table that `kraitebot/core` had created on 2026-05-01 for the user-data WS daemon. Health check started firing every minute on `BinanceListenKey::query()->max('last_keep_alive_at')`. Fix: recreated the table with exact schema (FK + unique on `account_id`, all timestamp(3) columns) via raw SQL; deleted the orphan drop migration file (kept the migrations table row so it never re-fires); restarted the user-data daemon to repopulate (2 rows for accounts 1 + 5, both keepalive=success).

### Improvements
- [IMPROVED] **Hard rule: NO MIGRATIONS HERE.** `CLAUDE.md` now carries a documented rule plus the 2026-05-04 incident referenced. Three apps share one `kraite` DB with one `migrations` tracking table; two repos issuing migrations against the same DB is a guaranteed silent-collision trap. Going forward, ALL schema changes route through `kraitebot/core/database/migrations/`. Same applies to seeders. Only Laravel scaffolding migrations (`create_users_table`, `create_cache_table`, `create_jobs_table`) remain in this repo's `database/migrations/`.

## [0.1.2] — 2026-05-02

### Features
- [NEW FEATURE] Sub-tabs inside expandable position rows on `/accounts/positions` (active pair cards + closed history). New "PnL projections" tab renders a per-stage grid: MARKET → LIMIT N → STOP-MARKET, with cumulative size, WAP'd avg entry, per-row TP price (computed via the engine's WAP formula), PnL @ fill, and projected profit @ TP.

## [0.1.1] — 2026-05-02

### Fixes
- [BUG FIX] Stress fill bar on dashboard now spans current TP marker → current price marker (was rendering from left edge / first TP price).

### Improvements
- [IMPROVED] Stress fill colour scale: 0–50% green, 50–75% warning, 75+ danger (removed mid-band info colour that made 31% read as blue).
- [IMPROVED] System dashboard layout rebuilt — Hero gauge + Direction + Stats now in a unified KPI strip card with proper spacing across breakpoints; BSCS panel stands alone with full-width manual override row; Exchanges panel stands alone. Killed broken 3-column wrapper that was cramping the centre column.
- [IMPROVED] /system/sql-query default page size dropped from 20 → 15 rows; per_page validation extended to accept 15.
