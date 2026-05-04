# WhereAreWe

_Last updated: 2026-05-03_

## Session summary

Two unrelated maintenance changes:

1. Renamed `/system/backtracking` → `/system/backtesting` across
   routes, route names, view file, sidebar, and controller view
   reference. Approve flow on the backtesting console now also
   sets `exchange_symbols.is_manually_enabled=true` on the row
   it approves; reject leaves both flags untouched.
2. Fixed `Position::nextPendingLimitOrderPrice()` so recovered
   positions resolve their next pending DCA rung. Switched from
   id-anchored ordering (relied on native open-flow row insertion
   sequence) to quantity-ascending ordering (matches the
   martingale ladder convention). Restores `alpha_limit_pct`,
   the lifecycle bar, and the two internal `HasGetters`
   path-fraction calcs across every recovered position.

## Current state

- Routes: six `system.backtesting.*` routes registered cleanly
  (`php artisan route:list --name=system.backtesting` confirms).
- Vite build: clean (`app-B58qykzb.css` 56 kB, `app-BKQJ_D1-.js`
  305 kB).
- Position helper fix verified on five sampled positions
  (#101 XRP SHORT → 1.4769, #103 YFI LONG → 2529, #119 XRP LONG
  → 1.3201, #125 YFI LONG → 2529, #131 APE SHORT → 0.1988).
- No schema change. No migration. No test run this session.

## What changed

### admin.kraite.com (this repo)

- `routes/web.php` — six routes + comment renamed.
- `resources/views/layouts/app.blade.php` — sidebar `routeIs`
  predicate, `data-nav-item`, `highlight` token, link `href`.
- `resources/views/system/backtracking.blade.php` →
  `resources/views/system/backtesting.blade.php` (`git mv`).
  Inside: `:activeHighlight`, Alpine x-data factory name, four
  `route('system.backtesting.*')` calls.
- `app/Http/Controllers/System/BacktrackingController.php` —
  `view('system.backtesting', ...)` return; comment-block path
  references; `toggleApproval` now sets `is_manually_enabled=true`
  inside the approve branch.

### kraitebot/core

- `src/Concerns/Position/HasGetters.php` —
  `nextPendingLimitOrderPrice()` rewritten to sort pending LIMITs
  by quantity ascending and return the first row's price. Drops
  the previous id-anchor filter that broke on recovered positions.

## Pending / next

Nothing on this thread. Two unrelated open items in the package
working tree (BTC correlation stability migration + token-scoring
support) belong to a different session — not touched here.

## Key decisions made this session

- Controller class kept as `BacktrackingController`. Surface
  rename only; class rename was out of scope for the request.
- Backtest approve auto-enables `is_manually_enabled`. Reject
  leaves the flag alone — `was_backtesting_approved=false` is the
  authoritative selection block, so the inverse path doesn't need
  a flag flip.
- `nextPendingLimitOrderPrice` now uses quantity ordering, not
  id ordering. The id-anchor was a hidden coupling to the native
  open flow's row-creation sequence; quantity ordering is the
  explicit contract that matches the ladder math the rest of
  the system already uses, and is robust to recovery's different
  insertion order.

## Documentation state

- `~/docs/kraite/02-features/disaster-recovery.md` — appended
  "Post-recovery follow-ups" section documenting the
  `nextPendingLimitOrderPrice` bug + qty-anchored fix.
- `~/docs/kraite/02-features/position-lifecycle.md` — appended
  one paragraph noting the `/system/backtesting` approve path
  flips `is_manually_enabled` back to `true`.
- `~/docs/kraite/03-logs/2026-05-03_backtesting_rename_and_recovery_fix.md`
  — new session log.
