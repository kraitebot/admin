# WhereAreWe

_Last updated: 2026-04-29 (evening)_

## Session summary

Pivoted billing from **daily debit** to **monthly renewal**. The
2026-04-28 daily-debit code is now decommissioned. This evening's
pass aligned the code surface to the new schema/spec elicited
earlier today AND rewrote the billing test suite to match. All 61
billing tests pass. NOWPayments webhook is still deferred per the
original plan.

## Current state

- Migrations: 2 new core-package migrations applied clean (rename
  `daily_rate_usdt` → `monthly_rate_usdt`; add
  `subscription_renews_at` + `subscription_paused_at` to users).
- Routes: `/billing`, `/billing/start-trading`, `/billing/subscription`,
  `/billing/pause`, `/billing/resume` — all registered.
- Command: `kraite:cron-renew-subscriptions` registered, dry-run
  smoke-tested clean (`renewed=0 · pre-warned=0 · trial-warned=0 ·
  closing-mode=0` against current DB).
- npm build: clean (`app-BjUPeMOA.css` 56 kB, `app-BKQJ_D1-.js`
  305 kB).
- Tests: **all green** — 61 billing tests pass (142 assertions).
  Five test files rewritten/replaced this evening:
  - `tests/Unit/Models/UserBillingTest.php` — trial helpers,
    pause/resume, isInClosingMode under all branches,
    subscriptionCoversNextRenewal/renewalShortfallUsdt boundary.
  - `tests/Unit/Support/Billing/WalletTest.php` — credit/debit
    primitives + Wallet::runRenewal happy path, explicit anchor,
    insufficient-funds rollback, no-tier guard.
  - `tests/Feature/Billing/WalletLedgerContractTest.php` — ledger
    contract for credit/debit/runRenewal/admin overrides + prorate
    refund. Bonus rows dropped.
  - `tests/Feature/Billing/HasTradingGuardsBillingTest.php` —
    trading guard integration with renewal-anchored closing-mode +
    paused users + active-account gate on Starter.
  - `tests/Feature/Billing/RenewSubscriptionsCommandTest.php`
    (replaces the deleted `DeductSubscriptionsCommandTest.php`) —
    renewal cron coverage: due-renewal processing, anchor push,
    paused/trial/inactive skips, low-balance pre-warning at
    renews_at-7d, trial-ending pre-warning at trial_end-2d,
    closing-mode notification on insufficient funds, dry-run, live
    rate read.

## What changed

### Schema (kraitebot/core migrations, both applied clean)

- `2026_04_29_120000_switch_subscriptions_to_monthly_rate.php` —
  rename `subscriptions.daily_rate_usdt` → `monthly_rate_usdt`,
  multiply existing values × 30 (Starter 75, Unlimited 150).
- `2026_04_29_120100_add_renewal_columns_to_users.php` —
  `subscription_renews_at` + `subscription_paused_at` (both
  nullable timestamps).

### kraitebot/core code

- `Models/Subscription.php` — PHPDoc + `$casts` realigned to
  `monthly_rate_usdt`.
- `Models/User.php` — added `subscription_renews_at` and
  `subscription_paused_at` to PHPDoc + `$casts`. Replaced
  `walletRunwayDays()` with `subscriptionCoversNextRenewal(): bool`
  and `renewalShortfallUsdt(): float`. Rewrote `isInClosingMode()`
  for renewal-anchored semantics (paused → closed; trial active →
  not closed; no renews_at OR renews_at in past → closed). Added
  `isPaused()`, `pause()`, `resume()` helpers (resume pushes the
  renewal anchor forward by the pause duration).
- `Models/WalletTransaction.php` — added new ledger type constant
  `TYPE_CREDIT_PRORATE_REFUND`. `TYPE_CREDIT_TOPUP_BONUS` kept for
  ledger-history compatibility but no new rows of that type are
  ever written.
- `Support/Billing/Wallet.php` — dropped `bonusPercentFor()` static
  helper (bonus killed). Added `runRenewal(User, ?Carbon
  $newRenewsAt = null): WalletTransaction` — atomic debit + push
  renewal anchor forward. Default anchor = current renews_at + 1
  month (or now + 1 month if unset). Read-only-unlock callers pass
  `now()->addMonth()->subDay()` so the day of unlock counts as day
  1 of the new cycle.
- `Commands/Cronjobs/RenewSubscriptionsCommand.php` — new file,
  signature `kraite:cron-renew-subscriptions`. Replaces the
  retired `DeductSubscriptionsCommand`. Three responsibilities in
  one pass:
  1. Renew users whose `subscription_renews_at <= now` (skip
     paused, skip trial-active).
  2. Fire `subscription_low_balance` 7 days before each renewal
     when wallet is short.
  3. Fire `subscription_trial_ending` 2 days before trial expiry
     when wallet won't cover the first renewal.
  Renewal failure fires `subscription_closing_mode` and leaves the
  anchor in the past (trading guards then read-only the user's
  accounts via `User::isInClosingMode()`).
- `Commands/Cronjobs/DeductSubscriptionsCommand.php` — **removed**.
- `CoreServiceProvider.php` — registration switched from
  `DeductSubscriptionsCommand` to `RenewSubscriptionsCommand`.
- `Support/NotificationMessageBuilder.php` — all 4 billing arms
  rewritten:
  - `subscription_low_balance` reads `renews_at`, `monthly_rate_usdt`,
    `shortfall_usdt`. Title now "Renewal in 7 days — wallet short".
  - `subscription_closing_mode` reads `monthly_rate_usdt`,
    `shortfall_usdt`. Title "Renewal failed — read-only mode".
  - `subscription_trial_ending` reads `monthly_rate_usdt`,
    `shortfall_usdt`. Title "Trial ends in 2 days — wallet short".
  - `subscription_topup_confirmed` extended with
    `monthly_rate_usdt`, `shortfall_usdt`, `renewal_ran` flags.
    Three message branches: renewal applied, balance covers next
    renewal, still short.

### admin.kraite.com

- `app/Http/Controllers/BillingController.php` — full rewrite:
  - Constructor-injects `Wallet`.
  - `index()` view-model now exposes `trialActive`, `isPaused`,
    `inClosingMode`, `rateCovered`, `shortfall`, `monthlyRate`,
    `renewsAt`, plus the user's accounts (for the active-account
    picker).
  - `startTrading()` initialises `subscription_renews_at` to
    `trial_started_at + trial_days` so the first renewal lands at
    trial-end midnight naturally.
  - `changeSubscription()` implements the prorate flow atomically:
    refund unused current-tier days as USDT credit (ledger type
    `credit_prorate_refund`), debit full new monthly rate, set
    `renews_at = now + 1 month - 1 day`. Trial-active users get a
    free flip (no prorate, no debit). Paused users are rejected.
    Downgrade to a capped tier requires `active_account_id` in the
    payload.
  - `pause()` / `resume()` endpoints delegate to the User model
    helpers.
- `routes/web.php` — added `billing.pause` + `billing.resume`.
- `resources/views/billing.blade.php` — full reskin to renewal-
  anchored UI:
  - Trial / paused / read-only banners (mutually exclusive).
  - Wallet card with green "Renewal covered" or red "Need X USDT
    more" badge.
  - Plan card surfacing tier name, monthly rate, renews_at,
    accounts cap. Plan switcher uses Alpine to reveal an
    active-account picker when the chosen tier is capped at 1.
  - Pause / Resume controls (visible only after trial is started
    and not currently in trial).
  - Transaction history table unchanged in structure, still uses
    `wallet_transactions` rows.
- `resources/views/system/users/index.blade.php` — sysadmin user
  detail panel now shows monthly rate, renews_at, paused state,
  and the green/red coverage badge in place of the runway-days
  block. Plan switcher dropdown labels updated to "X USDT/mo".

### ingestion.kraite.com

- `routes/console.php` — schedule entry now points at
  `kraite:cron-renew-subscriptions`. Same midnight cadence,
  `withoutOverlapping`, `onOneServer`.

## Pending / next

### NOWPayments webhook (first pass shipped)

First-pass scaffolding is in place. Bruno still needs to open the
NOWPayments merchant account and provide credentials before the
flow can be exercised end-to-end.

#### What shipped

- `config/services.php` — `nowpayments` block reads
  `NOWPAYMENTS_API_KEY`, `NOWPAYMENTS_IPN_SECRET`,
  `NOWPAYMENTS_BASE_URL` (default `https://api.nowpayments.io/v1`),
  `NOWPAYMENTS_IPN_CALLBACK_URL`, `NOWPAYMENTS_SUCCESS_URL`,
  `NOWPAYMENTS_CANCEL_URL`.
- `2026_04_29_130000_create_payments_table.php` (core migration,
  applied) — `payments` table tracking each top-up: user_id,
  nowpayments_payment_id (unique), order_id, pay_currency,
  pay_amount, price_amount, outcome_amount, outcome_currency,
  status, invoice_url, credited_at, raw_payload, timestamps.
- `Models/Payment.php` (core) — Eloquent model with status
  constants and `CREDITABLE_STATUSES` = [finished, partially_paid].
- `Support/Billing/NowPaymentsClient.php` (core) — thin Http
  wrapper. Methods: `createInvoice()` and `getPayment()`. Static
  `fromConfig()` factory pulls credentials from services config.
- `app/Http/Middleware/VerifyNowPaymentsSignature.php` — verifies
  `x-nowpayments-sig` HMAC-SHA512 against the recursively
  key-sorted JSON body using the IPN secret. Aborts 401 on
  mismatch, 503 if secret is unconfigured.
- `app/Http/Controllers/NowPaymentsWebhookController.php` —
  receives IPN. Idempotent: looks up Payment by order_id
  (`kraite-payment-{id}`), updates status + raw_payload + payment
  IDs/amounts. On first hit of a creditable status (`finished` or
  `partially_paid`) AND the row not yet credited, runs
  `Wallet::credit` for the outcome_amount. If the user is in
  read-only mode and the new balance covers the monthly rate,
  immediately runs `Wallet::runRenewal` with anchor = now + 1
  month - 1 day. Fires `subscription_topup_confirmed` afterwards
  with `renewal_ran` flag for the message branch.
- `BillingController::topUp` — validates amount (≥ 1 USDT),
  creates a pending Payment row, calls
  `NowPaymentsClient::createInvoice`, persists invoice URL,
  redirects user to the hosted invoice page. Failures are logged
  and surface a flash error.
- `routes/web.php` — POST `/billing/topup` (auth) + POST
  `/webhooks/nowpayments` (public, signature-verified,
  CSRF-exempt).
- `bootstrap/app.php` — adds `webhooks/nowpayments` to the CSRF
  validation exemption list.
- `resources/views/billing.blade.php` — Top-up card replaced with
  a real form (number input + "Continue to payment" button).

#### Still to wire (post-credentials)

- `.env` entries on whichever environment talks to NOWPayments
  (likely `~/.env.kraite`):
  - `NOWPAYMENTS_API_KEY`
  - `NOWPAYMENTS_IPN_SECRET`
  - `NOWPAYMENTS_IPN_CALLBACK_URL` (e.g.
    `https://admin.kraite.com/webhooks/nowpayments`)
  - Optional success/cancel URLs (default to `/billing`).
- An end-to-end smoke test once credentials land — initiate a
  small invoice, pay it, verify the webhook credits the wallet,
  ledger row appears, and (if the user was in read-only mode) the
  renewal runs and the trading guard flips back to write.
- Tests for the webhook are NOT written yet — the signature
  verification + creditable-status idempotency + auto-renewal
  branch are obvious unit-test targets but were skipped this pass
  to keep the scaffold focused. Suggested coverage: signature
  middleware accepts a valid signature and rejects mismatches,
  webhook double-fire credits only once, partially_paid credits
  outcome_amount as-received, finished without prior partial also
  credits once, auto-renewal runs only when user was closing-mode
  and now covers, no auto-renewal when active subscription gets a
  top-up.



When this lands:
- Webhook controller validates signature, credits the wallet 1:1
  via `Wallet::credit()` with type `TYPE_CREDIT_TOPUP`.
- After credit, if user is in read-only mode AND new balance
  covers `monthly_rate_usdt`, call `Wallet::runRenewal($user,
  newRenewsAt: now()->addMonth()->subDay())`. The trading guards
  flip back to write-mode automatically because
  `User::isInClosingMode()` now returns false.
- Fire `subscription_topup_confirmed` with `renewal_ran` flag set
  when the renewal was applied immediately.

### Notification seeder description drift

`2026_04_28_020300_seed_billing_notifications.php` populated the
`notifications` table with `detailed_description` and
`usage_reference` text that mentions `kraite:cron-deduct-subscriptions`
and the daily-debit semantics. The runtime notification
canonicals still work — only the documentation text in those rows
is stale. A small follow-up `UPDATE` migration can rewrite those
descriptions when convenient.

### Existing trial users without a renewal anchor

Bruno's seed accounts that have `trial_started_at` set but
`subscription_renews_at` null will be skipped by the renewal cron
(the WHERE clause excludes nulls). For each such user the
sysadmin user panel can be used to manually credit + flip them
into a healthy state, or a one-shot data migration can backfill
`renews_at = trial_started_at + trial_days` for users who already
started their trial pre-deployment.

## Key decisions made this session

- Pause is gated by `subscription_paused_at IS NOT NULL` rather
  than nulling `subscription_renews_at`. The behavioural outcome
  is identical to Bruno's elicitation wording, but keeping the
  cycle anchor live across pause means resume just adds N days to
  the existing value rather than juggling a snapshot column.
- `Wallet::runRenewal()` accepts an optional explicit anchor so
  one method covers both regular cron renewals (default = current
  anchor + 1 month) and read-only-unlock renewals (explicit = now
  + 1 month - 1 day per Bruno's literal rule).
- Plan-change prorate writes two ledger rows (refund credit + new
  renewal debit) inside one DB transaction. The new ledger type
  `credit_prorate_refund` makes the refund explicit in the ledger
  view rather than netting the two into one row.
- During trial, plan-change is a free flip (no prorate, no
  debit) — trial users haven't paid, so there's nothing to
  prorate.
- Paused users cannot change plan (server-side rejection).
- Renewal cron handles three responsibilities (renewals, low-
  balance pre-warnings, trial-ending pre-warnings) in one pass to
  keep the schedule single. Splitting into separate commands is a
  refactor option if responsibilities diverge.
- Pause overrides trial in `User::isInClosingMode()` — a user
  paused mid-trial counts as closing-mode (read-only) even though
  the trial counter keeps wall-clocking. Surface both effects:
  pause stops new opens, trial keeps depleting.
- Day-window matching in `RenewSubscriptionsCommand` uses
  `isSameDay()` against today + N rather than `diffInDays`
  arithmetic. Avoids fractional-day off-by-one when the cron fires
  at slightly different microseconds than the test setup.
- `Wallet::runRenewal` accepts `?CarbonInterface` rather than
  `?Carbon` so callers can pass either mutable Carbon or
  CarbonImmutable — Laravel's facade helpers vary.

## Documentation state

- `~/docs/kraite/02-features/billing-payments/README.md` — full
  monthly-model spec is the operative source of truth.
- `~/docs/kraite/README.md` — billing-payments pointer still
  valid.
- `~/docs/kraite/00-context/system-overview.md` — could add a
  note on `kraite:cron-renew-subscriptions` joining the schedule;
  not done this session.
