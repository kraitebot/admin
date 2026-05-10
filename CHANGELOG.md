# Changelog

All notable changes to the admin.kraite.com project.

## [0.4.0] — 2026-05-10

### Features
- [NEW FEATURE] **System dashboard redesign — two-column ops layout.** Main column shows KPI strip, per-prefix step dispatcher cards (Default + Trading fleet with Pending/Dispatched/Running/Throttled/Failed/Completed state grid + throughput saturation bar), and exchanges table. Sticky sidebar shows vitals gauges, compact BSCS tile, fleet cooldown toggles, and slow query count. Max-width 1600px container. Both columns auto-stretch to equal height.
- [NEW FEATURE] **Per-prefix step dispatcher cards on dashboard.** Each fleet card fetches `/system/steps/{prefix}/data` independently, showing leaf-step state counts and throughput saturation. Unavailable fleets (missing table) show "No data" gracefully instead of an infinite spinner.

### Fixes
- [BUG FIX] **CommandsController ingestion path hardcoded to VPS.** Replaced hardcoded `/home/waygou/ingestion.kraite.com` with `config('kraite.ingestion_path')` which auto-detects local dev via `is_dir` fallback.

### Dependencies
- kraitebot/core bumped to v1.37.3 (updateOrCreate api_systems seeder + ingestion_path config)

## [0.3.0] — 2026-05-08

### Features
- [NEW FEATURE] **Per-prefix Steps dashboard split.** The single `/system/step-dispatcher` page is replaced by two prefix-isolated views — `/system/steps/default` (the `steps_*` calculation fleet) and `/system/steps/trading` (the `trading_steps_*` trade-critical fleet). Sidebar gains a "Steps" parent group with Default + Trading sub-links. Each route's pivot, throughput gauge, and per-class health signals query the correct prefixed table set via `Steps::normalise()`; cache keys are suffixed `system.steps.{slug}.*` so the two fleets never collide. Old `system.step-dispatcher.*` route family removed.
- [NEW FEATURE] **Per-fleet cooldown chips on the system dashboard.** Single cooling-down chip is replaced by two side-by-side chips (Default + Trading), each toggling its own `Kraite\Core\Support\MaintenanceMode` prefix flag independently — pausing one fleet does NOT pause the other (no mutex). Backed by `MaintenanceMode::pauseStepsDispatch / resumeStepsDispatch / isStepsDispatchPaused` so the ingestion-side `routes/console.php` per-prefix skip-gates honour the chip state per fleet. Endpoints: `GET /system/steps/cooling-down` returns both fleets' state in one payload, `POST /system/steps/{prefix}/toggle-cooling-down` flips one. The legacy `kraite.is_cooling_down` Eloquent flag is no longer admin-toggleable.

### Fixes
- [BUG FIX] **Weighted-avg entry price now credits PARTIALLY_FILLED rungs against the executed gap.** `PositionsController::computeWeightedAvgEntry()` previously summed only FILLED entry-side orders, missing the executed portion of any LIMIT mid-fill at sample time. Symptom: positions mid-ladder-fill flagged false entry-price drift. Fix walks PARTIALLY_FILLED entry-side rows in id ascending order (= ladder ascending), credits each at its limit price up to the gap between exchange-truth `posQty` and the FILLED total. Filter order also swapped to side-first / status-second so the cheaper predicate runs first.

### Improvements
- [IMPROVED] **Projections controller offloaded onto `kraitebot/core` financial helpers.** `ProjectionsController::data()` no longer carries inline daily-revenue / wallet / scenario math — it delegates to `AccountFinancials` + `Window` from core, dropping ~120 lines of controller-side BCMath and DB scans. Same JSON contract for the front-end; the calc engine now lives where every consumer (admin, ingestion, kraite.com) can reach it. `Carbon` swapped for `CarbonImmutable` in this controller for pointer-safety on the windowed math.
- [IMPROVED] Step dispatcher view stale `isCoolingDown` Alpine state + `toggleCoolingDown()` method removed (the per-fleet toggle now lives on the system dashboard).
- [DEPENDENCIES] Vendor bumps via `composer.lock`: nine packages incl. `nunomaduro/collision`, `phpunit/phpunit`, `pest`, `symfony` family, `nikic/php-parser`, `psr/log`, `egulias/email-validator`. Patch / minor — no API breaks observed.

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
