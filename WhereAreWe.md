# WhereAreWe

_Last updated: 2026-04-21_

## Session summary

Long hardening pass that started as UI polish and expanded into
trading-pipeline correctness. All commits shipped, all tests locally
clean, Horizon running with 20 default workers.

## Current state

- **admin.kraite.com**: all system pages refactored onto new hub-ui
  components. Animated counters, richer heartbeat, step-dispatcher
  grid now surfaces Max Retry + Oldest Running diagnostics
- **ingestion.kraite.com**: schedule includes sync-orders every
  minute. Horizon bumped then dialed back to 20 workers (memory
  tradeoff; prod VPS will need its own tuning)
- **kraitebot/core @ 1.3.9**: seven separate correctness fixes landed
  across create / sync / close / WAP paths (see
  `~/docs/kraite/03-logs/2026-04-21_system_hardening.md`)
- **brunocfalcao/step-dispatcher @ 1.8.3**: recover-stale treats
  `timeout=0` as 300s, skips parents with live children
- **brunocfalcao/hub-ui @ 1.4.0**: new components shipped
- **Verified end-to-end**: 3 positions opened → TP filled → closed
  cleanly during the session. 1 position (pos 9 AIOT SHORT) still
  active at end of session

## What is NOT yet autonomous

- `kraite:cron-create-positions` is **intentionally unscheduled** per
  operator instruction. Ready to schedule once operator is confident
  after a few more manual cycles. All safety guards in place

## Known unknowns

- Root cause of corrupt `limit_quantity_multipliers` values on one
  exchange symbol (BAS) remains unidentified. No PHP code writes
  non-default values. Strong hypothesis: manual SQL intervention from
  a prior session. Pre-placement guard in
  `HasOrderCalculations::calculateLimitOrdersData()` catches the
  symptom

## Environment quirks (local dev)

- IP `127.0.1.1` not whitelisted on Binance → `StoreAccountBalanceJob`
  fails with -2015. Expected, prod has proper IP
- Only Bruno's Binance account (id=1) is `can_trade=true`. Bybit,
  Kucoin, Bitget, Karine's Binance are disabled
- Multiple Claude Code sessions were the single biggest RAM consumer
  (~5 GB across 6 concurrent sessions / tmux windows)

## Pending / next steps

1. Decide whether to schedule `cron-create-positions` — primary
   blocker to full autonomy
2. Optional: investigate the `limit_quantity_multipliers` corruption
   vector if it recurs
3. Optional: additional idempotency guards on other orchestrator
   jobs (`DispatchPositionSlotsJob`, `ClosePositionJob`,
   `CancelPositionJob`) — `PreparePositionsOpeningJob` already
   guarded, others may be vulnerable to the same retry pattern

## Docs

Full functional specs and session log at `~/docs/kraite/`:
- `00-context/system-overview.md`
- `02-features/position-lifecycle.md`
- `02-features/step-dispatcher.md`
- `03-logs/2026-04-21_system_hardening.md`
