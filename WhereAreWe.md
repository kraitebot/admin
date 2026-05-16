# WhereAreWe

_Last updated: 2026-05-15_

## Mission for this project — private-beta registration completion

Backend onboarding flow already ships end-to-end up to the verify-link
click. The marketing site (`kraite.com`) creates the user on form
submit, the verify link redirects to
`https://admin.kraite.com/register/{user.uuid}`, and the
private-beta coupon is attached to the user the moment
`UserEmailConfirmed` fires inside the verify request (synchronous
listener in `kraitebot/core`). Every new user reaching admin's
register URL is already in the system with `status=confirmed`,
`email_verified_at` set, `users.uuid` populated, and the
`private_beta_25` coupon attached on their `coupon_user` pivot.

**What's still missing on THIS app: the `/register/{uuid}` route and
everything behind it.** Right now the verify-click lands on a 404
because admin has no controller, no view, no validation. This file
is the punch list for closing that.

## Pre-locked design (decisions made during the private-beta
onboarding elicitation; do NOT re-litigate)

### URL + gating

- Route: `GET /register/{uuid}` + `POST /register/{uuid}`
- Permanent URL (resumable). UUID column on `users` already exists
  and is auto-stamped at `User::create` via the
  `kraitebot/core` boot hook.
- 4-state branch on GET (and the same gate must be re-checked on
  POST):

  | User state | Behavior |
  |---|---|
  | `pending` (email not yet verified) | **404** — bypassing email-verify defeats the signup gate; also hides existence |
  | `confirmed` (verified, registration not submitted) | **200** — render registration form |
  | `active` (registration completed) | **302** redirect to `/login`, email pre-filled if practical |
  | Unknown UUID | **404** |
  | disabled / future states | **404** — treat as gone |

- Route throttled `throttle:10,1`.
- 122-bit-entropy UUID — enumeration not a real risk; rate limit
  exists for noise.

### Form sections (top to bottom)

1. **Identity** — Name + Password + Confirm Password
2. **Trading exchange** — exchange picker as **clickable SVG icons**
   (Binance, Bybit, KuCoin, Bitget). Clicking one **dynamically
   renders** the right credential fields:
   - Binance / Bybit → API key + secret (2 fields)
   - KuCoin / Bitget → API key + secret + passphrase (3 fields)
3. **Test connectivity** button (see "Connectivity test" below)
4. **Plan picker** — `Basic` or `Unlimited` (subscription rows
   already renamed in ingestion v1.45.0).
5. **Private-beta perk explainer** — verbatim copy:
   `"Since you registered for private beta, any top-up will add
   25% FREE to your account balance, forever."` — applied
   automatically by the system, no code shown.
6. **T&C checkbox** — `"I read the Terms & Conditions"`
7. **Submit** — button labelled `"Next"`. On click:
   `"Account created! Now redirecting to your account..."`
   then **straight to admin dashboard** — NO NowPayments
   redirect in this flow (trial-first, 7-day grace).

### Page chrome

- Dedicated page (no admin sidebar/topnav).
- Blurred background, centered card.
- `"Welcome to Kraite!"` header.
- Tailwind v4 + Alpine v3 — same stack as the rest of admin.

### Connectivity test (the hardest piece on this list)

Triggered by the "Test connectivity" button after credentials are
filled. **Architecture** Bruno locked:

1. **POST endpoint** (e.g. `POST /register/{uuid}/connectivity`)
   - Validates required fields for the selected exchange.
   - Creates a parent `Kraite\Core\Steps\TestExchangeConnectivityStep`
     (NEW step class — does NOT exist yet).
   - For each `servers` row where `needs_whitelisting = true`,
     creates a child `Kraite\Core\Steps\TestServerConnectivityStep`
     under the parent's `block_uuid`. Each child is queued on
     that server's `own_queue_name` so the API call originates
     from THAT server's IP.
   - Each child step makes exactly **two** exchange API calls:
     - account balance
     - open orders
     Both must succeed → child Completes; either fails → child
     surfaces the failure reason on `Step.response` (especially
     `"ip:X.X.X.X cannot reach"`).
   - Endpoint returns `{ block_uuid }` to the browser.

2. **GET poll endpoint** (e.g.
   `GET /register/{uuid}/connectivity/{block_uuid}`)
   - Reads child steps under `block_uuid`.
   - Returns:
     - `pending` — any child still Pending/Dispatched/Running.
     - `error` + the failing server(s) IP(s) — strict
       aggregation: ANY child failure = whole result is `error`.
     - `okay` — every child Completed cleanly.

3. **Aggregation rule: strict.** ALL servers must pass for `okay`.

4. **Failure UX: SOFT block.** UI shows two buttons:
   `[Try again]` and `[Continue and activate later]`. Clicking
   "Continue and activate later" still submits the registration
   but writes the account with `accounts.can_trade = false`. The
   user re-tests from their admin profile later — when that test
   eventually passes, the `AccountObserver::updated` hook in
   `kraitebot/core` v1.45.0 dispatches `PreparePositionsOpeningJob`
   immediately and trading kicks in within ~30s.

5. **Localhost dev**: `servers` table already has a row for the
   MacBook (id=1, hostname=`falcaob-33C4GD`, ip=`91.84.82.171`,
   `is_apiable=1`, `needs_whitelisting=1`) — created by
   `KraiteSeeder::seedServers` which runs once per host. Nothing
   extra to seed locally.

### Submit-handler responsibilities (POST `/register/{uuid}`)

In order, inside a DB transaction:

1. Re-check the 4-state gate (status must be `confirmed`).
2. Validate name + password (Laravel rules) + plan + T&C + exchange
   + credential fields per the selected exchange shape.
3. Update the `User` row:
   - `name` (from form input)
   - `password` (hashed)
   - `subscription_id` (FK to chosen plan row in `subscriptions`)
   - `trial_started_at = now()` (kicks off the 7-day trial)
   - `status = 'active'`
4. Create an `Account` row tied to the user:
   - `user_id`, `name` (sensible default), `api_system_id` (FK
     for the selected exchange), `is_active = true`,
     `can_trade = true` IF the latest connectivity test for this
     submission returned `okay`, else `false`.
   - Encrypted credentials columns per exchange (these are
     already cast `encrypted` on `Kraite\Core\Models\Account`):
     `binance_api_key`/`binance_api_secret`,
     `bybit_api_key`/`bybit_api_secret`,
     `kucoin_api_key`/`kucoin_api_secret`/`kucoin_passphrase`,
     `bitget_api_key`/`bitget_api_secret`/`bitget_passphrase`.
5. `Auth::login($user)` — session established.
6. Redirect to `/dashboard` with a one-shot flash banner
   `"Account created! Welcome to Kraite."` (and a connectivity-
   warning banner if `accounts.can_trade=false`).

When `accounts.can_trade` flips true (either at submit, or later
via the in-app retest), the existing
`Kraite\Core\Observers\AccountObserver::updated` hook auto-dispatches
`PreparePositionsOpeningJob` under the `trading` step prefix — first
trade attempt within ~30 s, no need to wait up to 3 min for the
`kraite:cron-create-positions` tick.

### What you SHOULD NOT do

- **No NowPayments redirect at registration.** Trial-first model;
  top-up happens later on dashboard.
- **No interstitial pages** between verify-click and form. The
  user lands directly on the registration form.
- **No new migrations from THIS app.** Every Kraite DB migration
  lives in `ingestion.kraite.test/database/migrations/` per the
  project-wide hard rule. If a new column is needed here, write
  the migration in ingestion.
- **No new subscription tier names.** `Basic` and `Unlimited` are
  the only two for v1.
- **No email confirmation step here.** The user already verified
  on kraite.com before they ever reached this URL; sending
  another mail is duplicate work.

## Punch list (in build order)

### Stage 1 — Route skeleton + 4-state branch

- [ ] Add `RegistrationController` under `app/Http/Controllers/`.
  - `show(string $uuid): Response` → resolves user by uuid,
    dispatches the 4-state response (404 / form view / login
    redirect).
  - `store(RegistrationRequest $request, string $uuid): RedirectResponse`
    → submit handler. Stub for now (just dump and 200).
- [ ] Add `RegistrationRequest` (FormRequest) with the validation
  rules from "Submit-handler responsibilities" step 2.
- [ ] Register the routes in `routes/web.php` (NOT
  `routes/auth.php` — the user has no session yet; it's not a
  Breeze auth surface). Both inside a `guest`/`web` middleware
  group, throttled `10,1`.
- [ ] Pest test asserting the 4-state matrix returns the right
  HTTP status for each user state + unknown uuid.

### Stage 2 — Registration form view (no connectivity test yet)

- [ ] Build a Blade view (dedicated layout, no admin chrome):
  `resources/views/register/show.blade.php`.
  - Centered card on blurred background.
  - "Welcome to Kraite!" header.
  - All form sections rendered as static fields with placeholder
    exchange-picker logic (HTML+Alpine for the dynamic credential
    fields, no API call yet — "Test connectivity" button is a
    stub that resolves `okay` after a 1-second timeout).
  - Tailwind v4 + Alpine v3.
- [ ] Wire `store()` to persist all the things in
  "Submit-handler responsibilities" steps 3–6 EXCEPT the
  connectivity test result — for stage 2 assume connectivity
  passed and set `accounts.can_trade=true`.
- [ ] Pest test: a confirmed user POSTing a valid form ends up
  with `status=active`, an `Account` row, `trial_started_at`
  set, session authenticated, redirect to `/dashboard`.

### Stage 3 — Real connectivity test

In `kraitebot/core` (the step classes live in the shared package
so all apps can address them):

- [ ] `Kraite\Core\Steps\TestExchangeConnectivityStep` (parent).
  - `compute()` makes itself a parent, then for every
    `servers` row with `needs_whitelisting=true` creates one
    child `TestServerConnectivityStep` under the child block,
    queued on the server's `own_queue_name`.
- [ ] `Kraite\Core\Steps\TestServerConnectivityStep` (child).
  - Args: `{userUuid, exchange, credentials}` (credentials
    serialized through the Step `arguments` JSON column —
    consider whether they need transient cache-only storage
    keyed by `block_uuid`, since `arguments` is plaintext).
  - Per-exchange dispatch via `JobProxy::with(...)` against an
    in-memory `Account` populated from the form credentials —
    no DB write yet (account doesn't exist yet).
  - Two API calls: account balance + open orders.
  - Both succeed → response `{ result: 'ok' }`.
  - Either fails → response `{ result: 'error', ip: X.X.X.X,
    message: ... }`.
- [ ] Pest spec for both step classes (mock exchange API
  responses, assert response shapes).

In `admin.kraite.test`:

- [ ] `POST /register/{uuid}/connectivity` → create parent
  step, return `{ block_uuid }`.
- [ ] `GET /register/{uuid}/connectivity/{block_uuid}` →
  read child states under the block, return aggregated
  `pending` / `error` / `okay` JSON.
- [ ] Replace the form's stubbed "Test connectivity" with a
  real POST + 1-second poll loop on the GET endpoint. Show
  per-server failure detail when `error`. Surface the
  `[Try again]` / `[Continue and activate later]` choice.
- [ ] Pest test: full happy path (mock both API calls to
  succeed → poll returns `okay`); a failure path (one
  child fails → poll returns `error` with the failing IP).

### Stage 4 — UX polish

- [ ] Real SVG icons for the four exchanges (copy from the
  marketing site or design fresh).
- [ ] Loading + disabled states during connectivity test.
- [ ] Inline validation messages for password rules (min
  length, etc.).
- [ ] Mobile responsiveness.
- [ ] T&C link target (currently undefined — point at a
  static page or a placeholder).

### Stage 5 — Dashboard arrival affordances

(Out of scope for the initial registration page but called out
so it doesn't slip on the floor.)

- [ ] If the user landed in admin with `accounts.can_trade=false`,
  show a persistent banner: "Activate trading — re-test your
  exchange connection." Link to a profile retest page that
  re-runs the connectivity test against the existing saved
  credentials. On success, flip `accounts.can_trade=true` and
  let `AccountObserver::updated` fire the first trade.

## Adjacent backend state (already shipped — do not redo)

- `kraitebot/core` v1.46.1 (HEAD = `d3cf22c`):
  - `Kraite\Core\Models\Coupon` + `CouponUser`
  - `Kraite\Core\Listeners\AttachPrivateBetaCoupon` (sync, not
    queued)
  - `Kraite\Core\Events\UserEmailConfirmed`
  - `Kraite\Core\Support\Billing\BillingManager` +
    `SubscriptionState` (`$user->billing()->subscription()->isActive()`)
  - `Account::isReadyToTrade()` 3-gate
  - `AccountObserver::updated` event-on-activation that
    dispatches `PreparePositionsOpeningJob` the instant
    `accounts.can_trade` flips true
  - `User::booted()` auto-stamps `users.uuid` via `Str::uuid()`
- `ingestion.kraite.test` v1.48.0 (HEAD = `7ff1e4a`):
  - Migrations: `coupons`, `coupon_user`, `users.uuid`,
    `kraite.in_private_beta`, `private_beta_25` seed,
    `subscriptions` rename starter→basic, notification-canonical
    renames waitlist→private_beta.
  - 31/31 Pest tests for the coupon foundation + readiness
    matrix + activation observer.
- `kraite.test` v0.12.0 (HEAD = `9b8587c`):
  - `PrivateBetaController@verify` dispatches
    `UserEmailConfirmed` after persisting the verify, then
    redirects to `admin.kraite.com/register/{uuid}` (the URL
    this app needs to handle).
  - Dev-reset affordance for `bruno.falcao@live.com` on the
    signup form so the loop can be retried indefinitely.

## How to test e2e (today, before any admin work ships)

1. Submit `bruno.falcao@live.com` on `https://kraite.test/`.
   Dev-reset wipes any prior row.
2. Click the verify link in the inbox; replace the domain to
   `kraite.test` if needed.
3. Browser lands on `https://admin.kraite.test/register/{uuid}`
   — currently **404** (that's THIS work).
4. Verify in DB:
   ```sql
   SELECT u.id, u.uuid, u.status, c.slug, cu.attached_at
   FROM users u
   LEFT JOIN coupon_user cu ON cu.user_id = u.id
   LEFT JOIN coupons c ON c.id = cu.coupon_id
   WHERE u.email = 'bruno.falcao@live.com'
   ORDER BY u.id DESC LIMIT 1;
   ```
   Expect: `status=confirmed`, `email_verified_at` populated,
   coupon `private_beta_25` attached at the same second.

Once stage 1 ships in this repo, step 3 will land on the
registration form instead of a 404 and the journey continues.
