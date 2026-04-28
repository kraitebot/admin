# WhereAreWe

_Last updated: 2026-04-28_

## Session summary

Three threads:

1. **Documentation cleanup** across `~/docs/kraite/` — every UNVERIFIED
   marker, "unresolved", "deferred" item removed. Docs now reflect
   live shipped state only.
2. **Billing & payment gateway** — full elicitation session, locked
   product spec at `~/docs/kraite/02-features/billing-payments/`,
   then **shipped the entire engine minus the NOWPayments webhook**.
3. **Two small dashboard fixes** — account dropdown label
   de-duplication and transient-tile colours driven off
   `position.status` instead of `filter: grayscale(1)`.

## Billing implementation — what shipped

### Schema (kraitebot/core migrations)
- `2026_04_28_020000_add_billing_columns_to_subscriptions.php`
  — adds `daily_rate_usdt` + `trial_days` to existing `subscriptions`
- `2026_04_28_020100_add_billing_columns_to_users.php`
  — adds `wallet_balance_usdt`, `trial_started_at`, `active_account_id`
  to `users`
- `2026_04_28_020200_create_wallet_transactions_table.php`
  — append-only ledger with type, signed amount, `balance_after`
  snapshot, description, JSON meta
- `2026_04_28_020300_seed_billing_notifications.php`
  — seeds 4 canonicals: `subscription_low_balance`,
  `subscription_closing_mode`, `subscription_trial_ending`,
  `subscription_topup_confirmed`

### Tier seeding (kraitebot/core seeder)
- Starter: 2.5 USDT/day, 1 acct, 10K cap, 7-day trial
- Unlimited: 5 USDT/day, no caps, 7-day trial
- Live-tunable from the `subscriptions` table — cron reads fresh
  every run

### Models / service (kraitebot/core)
- `Models/Subscription.php` — added `daily_rate_usdt` + `trial_days`
  property/cast
- `Models/WalletTransaction.php` — new model with type constants
- `Models/User.php` — added wallet/trial/active-account columns,
  helper methods (`isTrialActive`, `isTrialExpired`,
  `walletRunwayDays`, `isInClosingMode`), relations
  (`activeAccount`, `walletTransactions`)
- `Support/Billing/Wallet.php` — single point of mutation for the
  wallet. Atomic credit/debit with `lockForUpdate`, always writes
  ledger row alongside balance change. Static helper
  `Wallet::bonusPercentFor()` returns 5/10/15 based on top-up size
- `Support/Billing/InsufficientFundsException.php`

### Cron
- `Commands/Cronjobs/DeductSubscriptionsCommand.php`
  — `kraite:cron-deduct-subscriptions`, supports `--dry-run` and
  `--output`. Skips trial-active users; on insufficient funds fires
  `subscription_closing_mode` notification (no row written, balance
  untouched). On low runway (< 7 days) fires
  `subscription_low_balance` after a successful debit.
- Schedule entry added in `ingestion.kraite.com/routes/console.php`
  at `00:00` daily, `withoutOverlapping`, `onOneServer`.

### Trading guard
- `Trading/Concerns/HasTradingGuards::canOpenNewPositions()` extended:
  - blocks new opens when `User::isInClosingMode()` is true
  - on tiers capped at 1 account, blocks new opens on accounts other
    than `users.active_account_id`
  - existing positions unaffected — only new opens gated

### Notifications
- 4 canonicals seeded via migration (rather than separate seeder
  file, matching the precedent set by
  `2026_04_28_010000_seed_drift_spotter_notifications.php`)
- Match arms added in
  `Support/NotificationMessageBuilder.php` for each canonical
- Cron dispatches `subscription_low_balance` and
  `subscription_closing_mode` via existing `NotificationService`
- `subscription_trial_ending` and `subscription_topup_confirmed`
  arms ready; dispatch sites will be wired when needed (trial-end
  cron warning + admin top-up flow / NOWPayments webhook v2)

### Admin UI (admin.kraite.com)
- `app/Http/Controllers/System/UsersController.php` — list, show,
  adjust credit (POST), change subscription (POST). Routes under
  `/system/users` + `/system/users/{user}` + `/system/users/{user}/credit`
  + `/system/users/{user}/subscription`. All under `admin` middleware.
- `resources/views/system/users/index.blade.php` — table with email,
  tier, wallet, trial state, can_trade, is_active, manage link
- `resources/views/system/users/show.blade.php` — wallet card,
  subscription card with tier switcher, trial card, manual
  credit-adjustment form, recent ledger (50)
- Sidebar entry "Users" added to System section

### User-facing billing page
- `app/Http/Controllers/BillingController.php` — `/billing` index,
  POST `/billing/start-trading`, POST `/billing/subscription`
- `resources/views/billing.blade.php` — start-trading prompt when
  trial not yet started, alerts for trial-active and closing-mode,
  wallet/plan/top-up cards, transaction history. Top-up button is
  visibly disabled with "coming soon" copy until NOWPayments
  integration ships.
- Sidebar entry "Billing" added next to BSCS

## Migrations
All four migrations ran clean against the local DB.

## Pending / next

- **NOWPayments webhook integration** — explicitly deferred per
  Bruno's request. Top-up button on the billing page is a hook
  point; webhook controller, signature verification, payment
  status state machine, and bonus-credit logic still to build.
- **Trial-ending notification dispatch site** — canonical and
  message-builder arm exist; need to add a sweep (likely as part
  of the daily-deduct cron itself or a separate hourly checker)
  that fires once when a trial has < 24h remaining.
- **Existing test users** still have `subscription_id = NULL`. To
  make them billable Bruno needs to visit `/system/users/{id}` and
  pick a tier (or manually credit them in the admin panel which
  also surfaces the tier switcher).

## Pre-existing dashboard fixes shipped this session

- `dashboard.blade.php:460-464` — account dropdown label trimmed of
  the redundant `· acc.exchange` segment that triggered the
  "Karine Esnault (Binance) · Binance · Binance Only Account"
  viewport overflow when the native `<select>` popup expanded
- `dashboard.blade.php:160-378` — every coloured inline style on
  position tiles now driven off `position.status` via helpers
  (`stressColor`, `pnlColor`, `directionStyle`, `tpColor`,
  `currentPriceMarkerStyle`). The `filter: grayscale(1)` wrapper
  was removed — colours are now baked into the helpers, no
  compositing tricks.

## Documentation state

- `~/docs/kraite/02-features/billing-payments/README.md` — full
  functional spec (pre-implementation; still load-bearing as the
  source of truth)
- `~/docs/kraite/README.md` — updated with billing-payments pointer
- All historical "unresolved/deferred/UNVERIFIED" markers stripped
  from kraite docs

## Key decisions made this session

- Billing tables use the **existing** `subscriptions` table (not a
  new `subscription_types` table) — added two columns instead of
  creating a sibling. Minimises churn; the existing
  `Subscription` model already has helper methods like
  `hasUnlimitedAccounts()`.
- Tier rates are **read live every cron run** — a price change
  five minutes before midnight is honoured immediately.
- Closing-mode is **per-user, evaluated at gate time** —
  `User::isInClosingMode()` is computed from current balance vs
  current tier rate. No persisted "is_closing_mode" flag; state
  is always derived. Avoids drift between flag and reality.
- Wallet ledger row is mandatory for every balance change —
  `Wallet::credit/debit` are the only public writes. Concurrent
  webhook + cron + admin-credit calls are serialised via
  `lockForUpdate` on the user row.
- Top-up bonus tiers (5/10/15%) are encoded as a static helper on
  the Wallet class; the `bonusPercentFor()` lookup is the single
  source of truth.
- Admin credit adjustments write through the same ledger as a
  regular debit/credit (`credit_admin` / `debit_admin` types),
  with the operator's email captured in `meta` for audit.
- The `/billing` top-up button is **visibly disabled** with
  "coming soon" copy — Bruno's call to ship the engine without the
  gateway and revisit the UX once NOWPayments lands.
