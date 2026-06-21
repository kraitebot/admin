# Changelog

All notable changes to the admin.kraite.com project.

## [0.8.3] — 2026-06-21

### Improvements
- **Step Dispatcher KPI gauge** — the sysadmin Fleet-overview KPI strip swaps the static "Worker nodes" mock tile for a **Step dispatcher** tile rendered as a circular ring-dial (value centered in the dial, `92%`, sub "DISPATCH PERF · 4.2K STEPS/S"). New reusable `x-ui.mini-gauge` ring component with trading-safe perf bands (≥80 green / 60–80 warn / <60 red). The live Worker-fleet card below — the real fleet-health surface — is untouched.
- **Backtesting token card no longer clips its dropdown** — the Token card now renders `overflow-visible` so the token selector menu escapes the card bounds instead of being clipped behind the Config panel. Shared `x-ui.card-head` gained `rounded-t-surface` so card corners stay flush when a card is unclipped.
- **Token avatars in the backtesting selector** — the selector trigger and every dropdown row now show a circular token avatar (initial monogram, one harmonized hue per token via a golden-ratio hue spread). Dropdown rows render transparent, dropping the stray light-gray fill from the native button background.

## [0.8.2] — 2026-06-12

### Fixes
- **Top-up double-credit guard** — a NOWPayments IPN retry can no longer credit the same payment twice. The wallet credit now runs under a row lock with the idempotency check re-evaluated inside the transaction, closing the race where two concurrent "finished" webhooks both slipped past the `credited_at` guard before either stamped it.
- **Active-account ownership check** — switching plans can no longer point your active account at another user's account. `active_account_id` is now validated against the accounts you actually own; a foreign id is rejected instead of silently assigned.

### Tests
- Feature coverage for the active-account ownership rule — rejects an account belonging to another user, accepts one the caller owns.

## [0.8.1] — 2026-06-12

### Improvements
- **Fleet service dots → hover detail** — the supervisor-service status dots on the sysadmin dashboard Worker-fleet card and the Infrastructure page now reveal the service name + state in a hover tooltip (replacing the native `title` hint) and grow on hover for an easier target. The dashboard card also drops the redundant per-node "sync …" line — uptime stays.

### Tests
- **Fleet Redis connection regression guard** — pins that the unprefixed `fleet` Redis connection is registered (database 2, empty prefix) and still resolves after the Redis manager has already been resolved, locking in the `boot()`→`register()` fix behind the 2026-06-12 heartbeat incident.

## [0.8.0] — 2026-06-12

### Features
- **Live fleet health** — the sysadmin Worker-fleet card + the new Infrastructure page are wired to real data: node reachability + vitals (CPU / RAM / disk / uptime / services) from the live fleet-metrics heartbeat (`servers` table ⋈ Redis), the egress-IP allowlist from the real apiable hosts, and a Control-plane panel (host vitals + step-dispatcher pulse + slow-query count). Every fleet box now reports — the 7 PHP boxes via a self-rescheduling Horizon job, hyperion via a standalone systemd agent.

### Fixes
- **Dashboard data feed hardening** — a missing `bscs_override_reason` column no longer 500s the entire `system.dashboard.data` feed (and the live fleet card with it); the override-reason read is now gated on column existence. `serverMetrics()` is null-safe + cross-platform and stamps the reporting host.

## [0.7.3] — 2026-06-08

### Features
- **Sysadmin console surface** — sysadmins (`is_admin`, via the console host) get a staff-mode violet UI with their own 9-item rail (Overview / Positions / Engine / Dispatch / Infra / Exchanges / SQL / Revenue / Settings) and a Fleet overview dashboard (worker fleet table, market-regime, deploy rollout, revenue, exchange connectivity, incidents feed — mock data). Reuses the entire trader design system, swapping only the accent token; new shared `x-ui` components (`card-head`, `health-chip`, `health-dot`, `usage-bar`, `stat-tile`); placeholder pages for the not-yet-built nav surfaces; Sysadmin badge + accent avatar in the top bar.

### Improvements
- **WAP'd entry on position tiles** — once a position averages down, the tile shows the weighted-average entry (labelled "WAP", computed from the filled entry fills) instead of the original open, so the entry→TP relationship reads correctly (TP above entry for a long).
- **Activity feed — "Active only" filter** — a header toggle filters the feed to events whose source position is still open, keyed on **position id** (a re-used token's earlier closed position never leaks in). The filter persists across the 10-second sync.
- **Activity feed — WAP close badge** — closes from averaged-down positions are badged: "High profit" when the WAP recovered to a green close, neutral "WAP'd" on a loss.
- **Market-shock cooldown surfaced** — the trader dashboard now distinguishes the fast 1-minute shock circuit breaker from the slow BSCS score: when the breaker has paused opens it shows "MARKET SHOCK" and "resumes in …", with the cooldown expiry skew-corrected to UTC. The blocked banner names the real cause (shock vs regime gate) instead of always blaming the regime band.

### Fixes
- Activity CLOSE rows with an unknown P&L no longer render in the profit colour.

---

## [0.7.2] — 2026-06-07

### Features
- **Dashboard wired to real data** — KPI tiles (portfolio value + 30-point balance spark + 24h delta, today/30-day realized P&L with ROI %, open/long/short counts), recent bot activity feed (position opens / closes / WAPs, newest-first), Black Swan Composite panel (score, band, five sub-signals, block threshold), and top-bar identity (name, SYSADMIN/TRADER role, working logout). Served by one shared payload builder for both first paint and the 10-second polling endpoint.

### Improvements
- **Realized P&L now exchange-true** — sourced from `positions.pnl` (exchange-reported net, fees + funding included) instead of reconstructing from execution prices, which overstated by the omitted round-trip cost (~$0.62 over 70 trades).
- **Relative times corrected for DB clock skew** — activity ages, position ages and the BSCS computed-ago now subtract the measured DB-vs-UTC offset, so locally-ingested wall-clock timestamps no longer collapse to "just now" or read hours off.
- **Positions carousel** — restored pointer-drag swipe between pages with rubber-band ends and the stretch-then-settle dot-thumb animation; single-account users no longer see the account picker; pagination only appears past one viewport-page of tiles.
- **Monitoring row** — activity and right-column cards share one height; the activity feed carries 30 events and scrolls within the fixed card.
- **Sync UX** — dashboard auto-syncs every 10s; the sync spin holds a minimum of 1s so a sub-100ms local fetch reads as a sync, not a glitch. The BSCS footer shows the next scheduled compute countdown.

### Fixes
- **Rail highlight** — rewritten onto a global Alpine store with module-level handlers, fixing the departing link vanishing mid-transition and stale highlights after `wire:navigate` swaps (per-component `x-data` was re-initialising and desyncing).
- **Double Livewire/Alpine instance** — shell persistence uses raw `x-persist` divs instead of the `@persist` directive, which compiled to `forceAssetInjection()` and booted a second Livewire+Alpine alongside the Vite bundle.
- **Activity dot colour** — CLOSE rows with an unknown P&L no longer paint green (`Number(null) >= 0` was truthy); they render a neutral dot.
- **Zombie pollers** — `destroy()` hooks on dashboard / billing / accounts clear their interval timers on navigation, so a left page can't keep fetching from the next.

### Tests
- `TestCase` seeds a stub `kraite` singleton so core's regime/PnL reads work on the sqlite test DB; `PasswordResetTest` flushes the cache per test to stop rate-limiter bleed.

---

## [0.7.1] — 2026-06-06

### Config
- `composer.production.json` synced with the local manifest: `laravel/horizon ^5.47` added (was missing since the v0.6.1 install — the deploy swap would have uninstalled Horizon and crashed boot), stale `brunocfalcao/hub-ui` require + VCS repository removed.

---

## [0.7.0] — 2026-06-06

### Features
- **Positions page** — sortable open/closed tables with expandable per-position records (summary groups + orders table), inverted accent headers, and the exchange reconcile UI: out-of-sync orders flag amber, expand to an aligned EXCHANGE ghost row with diff highlighting, and re-sync inline.
- **Projections page** — monthly revenue calendar: realized history, today anchor, forward compounding under pessimistic/neutral/optimistic scenarios (per-segment daily rates), account picker, month picker (14 months back / 6 years forward), state-adaptive totals strip with REAL/PROJ split.
- **Accounts page** — accordion of exchange accounts with General-information and Connectivity tabs: constrained config dropdowns (backend-validated ranges), API credential handshake with live progressive per-server connectivity test, IP allowlist with copy affordances, test-gated save, trading-disabled banner. Introduces the system-wide form-control components (`x-form.field/input/select/toggle/group`).
- **Billing page** — prepaid-USDT wallet state machine: wallet hero with live credited moment, six lifecycle states (no-plan → trial-ready → trial → active → paused → read-only), plan switch with prorate breakdown + downgrade account picker, top-up flow with dynamic minimum and NOWPayments hand-off, ledger with running balance, collapsible billing terms.
- **Console domain split** — `console.kraite.com` (sysadmin) and `admin.kraite.com` (trader) served by one project via host-bound route groups (`ADMIN_DOMAIN`/`CONSOLE_DOMAIN`); `/system/*` URL prefix retired, host-aware login landing, admin-gated console group, surface-aware rail.
- **SPA navigation** — Livewire 4 `wire:navigate` with sequential content fade (out → swap → in), persisted shell (rail / top bar / footer), hover prefetch, Alpine-owned rail highlight that slides in parallel with the fade, theme toggle persisted across navigations.

### Architecture
- Trader rail rebuilt to the design spec: 112px, full labels, BSCS retired from nav, Profile added; rail item color transitions sync with the sliding pill.
- Livewire runtime bundled via Vite (`livewire.esm`) with auto-start and asset auto-injection disabled — fixes the double-boot `$persist` crash on every page load.

### Config
- `laravel/framework` 12.61.1, `laravel/horizon` 5.47.2.

---

## [0.6.1] — 2026-06-01

### Features
- [NEW FEATURE] **`laravel/horizon` installed.** `composer require laravel/horizon` + `php artisan horizon:install` scaffolds Service Provider + `config/horizon.php`. Admin gets its own Horizon master on pheme so its queued jobs (notifications, mail, anything `ShouldQueue`) execute under admin's autoloader — cross-app queue consumption is unsafe because each app's Job classes live only in its own vendor tree.

### Improvements
- [IMPROVED] **`config/horizon.php` reads `HORIZON_ENV` for the environments block.** Adds `'env' => env('HORIZON_ENV', env('APP_ENV', 'production'))` so the master picks `environments.<HORIZON_ENV>` (rewritten at boot by `kraitebot/core`'s transformer from `kraite.horizon.workers.<HORIZON_ENV>`). Without this, Horizon would look up `environments.production` (absent in the kraite topology) and the master would report "No supervisors are running".

## [0.6.0] — 2026-05-23

### Bug Fixes
- [FIXED] **Production CSS regression on helios.** `tailwind.config.js` referenced only the local dev path `../packages/brunocfalcao/hub-ui/...`, which doesn't exist on production (hub-ui is composer-installed under `./vendor/`). Tailwind silently scanned no hub-ui templates, purged all hub-ui-only utilities (`.h-screen`, structural classes, `.bg-emerald-*`), and shipped a 40KB no-utilities CSS — the entire dashboard sidebar lost its dark-theme styling. Added the `./vendor/brunocfalcao/hub-ui/...` glob alongside the dev path. `/kraite-deploy` also now drops `--silent` on `npm install`/`npm run build` and aborts with an explicit message if `.h-screen` is missing from the compiled CSS, so a stripped build can never reach warmup again.

### Features
- [NEW FEATURE] **Private-beta registration completion flow.** Confirmed users can complete onboarding at `/register/{uuid}` with Livewire server-side validation, exchange selection, API key capture, plan selection, terms acceptance, and account creation.
- [NEW FEATURE] **API key modal with connectivity testing.** API credentials now live in a blurred-backdrop modal; connectivity checks require filled credentials, lock the modal while running, and surface verified state before completion.
- [NEW FEATURE] **Password strength acceptance.** Registration passwords are accepted based on a server-backed strength threshold with a visual progress bar instead of rigid composition rules.
- [NEW FEATURE] **Binance and Bitget enabled for onboarding.** Bybit and KuCoin remain visible but disabled with grayscale styling and "Coming soon" badges.

### Improvements
- [IMPROVED] **Registration terms link now uses `kraite.website_url`.** Local admin resolves to `kraite.test`, production resolves to the public website, and tests can override via `KRAITE_WEBSITE_URL`.
- [IMPROVED] **Admin auth login title refreshed** to match the Kraite console branding.

### Tests
- [NEW FEATURE] **Playwright registration E2E suite.** Adds a deterministic browser registration fixture, setup script, test environment, and `npm run test:e2e`.
- [NEW FEATURE] **Feature coverage for registration validation and completion.** Locks server-side Livewire validation, password strength, disabled exchanges, API key requirements, and the dynamic terms link.

### Dependencies
- [DEPENDENCIES] Added `@playwright/test` as a dev dependency.
- [DEPENDENCIES] `kraitebot/core` path-package reference bumped to `5e15c70`.

## [0.5.1] — 2026-05-13

### Features
- [NEW FEATURE] **"Send password reset email" action on system user detail.** Admin-only action that dispatches a password-reset link to the selected user via the standard `Password::broker()` flow. Used as the explicit "you're in" gate for private-beta approvals coming through the kraite.com waitlist — admin clicks the button, the user receives the branded Resend email, sets a password, and signs in.

### Tests
- [NEW FEATURE] **Feature test for the new admin password-reset dispatch.** Covers the happy path (admin acting-as → notification sent to target user, redirect with status flash) and the guest case (unauthenticated POST redirects to login, no mail sent).

### Dependencies
- symfony/console v7.4.9 → v7.4.11 (security/patch)
- symfony/http-kernel v7.4.10 → v7.4.11 (security/patch)

## [0.5.0] — 2026-05-13

### Features
- [NEW FEATURE] **Self-service password reset on admin login.** "Forgot password?" link on `/login` → email entry → neutral status (no enumeration leak) → 15-min single-use reset link → set new password (live strength meter + checklist) → redirect to login with success toast. First-time captures the user's full name when the record has none on file. Email sent via Resend, branded "Kraite", from `no-reply@kraite.com`. Per-email rate limit 5/60s on top of the route's IP throttle.
- [NEW FEATURE] **Resend mail integration on admin.** `MAIL_MAILER=resend` enabled; `services.resend.key` is auto-injected by `kraitebot/core` `CoreServiceProvider` from the encrypted `kraite` singleton column — no API key in `.env`.
- [NEW FEATURE] **Branded transactional email theme.** Vendor mail markdown overridden to use the krait-500/600 brand greens for the action button, krait-700 for the header wordmark, and krait-500 for panel rules. "Kraite" header, "Kraite" sender, "— The Kraite team" salutation.

### Tests
- [NEW FEATURE] **Pest installed as the project test runner.** First Pest battery covers the password-reset flow: 25 tests across 8 describe blocks (silent success, per-email rate limit, email branding, name capture conditional, password rules, single-use enforcement, expired-token redirect). 43 tests / 138 assertions green.

### Infrastructure
- [INFRA] **Dual-manifest pattern (`composer.json` + `composer.production.json`).** Mirrors the kraite (web-app) profile. Local Mac stays path-symlinked; the deploy block swaps the production manifest over `composer.json` after `git checkout`, regenerates `composer.lock` via `composer update`, and never leaves the server with hand-edited composer state.

### Fixes
- [BUG FIX] **Removed obsolete Breeze tests (`RegistrationTest`, `ExampleTest`).** Admin has no `/register` (users created on the landing site) — the test was a 404 against a non-existent route.

## [0.4.1] — 2026-05-10

### Fixes
- [BUG FIX] **Backtesting approval now saves TP% and SL% to exchange symbol.** Previously only gap percentages were persisted on approve — profit_percentage and stop_market_percentage were silently dropped.
- [BUG FIX] **"Not reviewed" filter now shows symbols with `pending` status.** Filter only matched `null` — symbols reverted to pending were invisible in the dropdown.

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
