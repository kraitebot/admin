# WhereAreWe

_Last updated: 2026-05-17_

## Current State

The private-beta onboarding path is now wired end to end across the
Kraite apps.

1. A user signs up on `kraite.test` / `kraite.com`.
2. The public site sends the private-beta email verification message.
3. The verification email now includes both a button and the raw
   copy/paste URL.
4. When the user verifies, the public site redirects to
   `admin.kraite.test/register/{user.uuid}` or production equivalent.
5. The admin registration form opens for confirmed users.
6. The user completes identity, password, exchange/API keys, plan,
   and terms acceptance.
7. The admin app creates the account, activates the user, logs them
   in, and sends them to the dashboard.

The implementation has been committed and pushed:

| Repo | Branch | Commit | Purpose |
|---|---|---:|---|
| `kraitebot/core` | `master` | `5e15c70` | Shared email/config/seeding support |
| `ingestion.kraite.test` | `master` | `8e94ab4` | Seeder fixes and Resend credential test |
| `kraite.test` | `main` | `4f92fd4` | Terms page, verification resend, email fallback tests |
| `admin.kraite.test` | `master` | `709268e` | Livewire registration flow and Playwright E2E |

## What Changed

### Shared Core

- `kraite.website_url` was added to shared config.
  - It derives from `APP_URL`.
  - Admin hosts map back to the public website host
    (`admin.kraite.test` -> `kraite.test`,
    `admin.kraite.com` -> `kraite.com`).
  - `KRAITE_WEBSITE_URL` can override it explicitly.
- Shared `.env.kraite` loading now syncs Resend and ZeptoMail service
  config values after loading.
- Private-beta verification emails include the direct verification URL
  under the button.
- `KraiteSeeder` now seeds admin users with `uuid` and
  `status = active` when model events are disabled.
- `KraiteSeeder::migrateKraiteCredentials()` seeds
  `resend_api_key` into the `kraite` credentials row when configured
  and does not clear an existing key if no key is configured.
- Seeder behavior avoids public IP resolution under `APP_ENV=testing`.

### Ingestion

- `BusinessSeeder` now marks seeded admin/trader users as active.
- The Karine trader fixture is seeded in `testing` as well as `local`
  so browser tests have stable account data.
- Added seeder coverage for Resend API key persistence into the
  shared `kraite` row.

### Public Site

- Pending private-beta users can resubmit their email to receive a
  fresh verification token/email.
- Confirmed/completed users keep the anti-enumeration response without
  refreshing tokens.
- Added `/terms-and-conditions`.
  - Responsive legal page.
  - Feather SVG icons through `brunocfalcao/blade-feather-icons`.
  - Smooth sidebar chapter scrolling.
  - Dynamic canonical URL from `APP_URL`.
  - Crypto risk disclosures.
  - No-financial-advice language.
  - Remaining-balance refund language.
  - Public-statements/reputation clause for false, misleading,
    defamatory, malicious, or unlawful statements, with carve-outs for
    truthful reviews, lawful complaints, regulator reports, and
    good-faith factual discussion.
  - Contact email is `support@kraite.com`.
- Landing footer links to the Terms page.

### Admin Registration

- Added `GET /register/{uuid}` and Livewire registration component.
- Access rules:
  - unknown UUID: 404
  - `pending`: 404
  - `confirmed`: registration form
  - `active`: redirect to login/dashboard path
- Form uses Livewire server-side validation and avoids full page
  refresh on validation errors.
- The browser form has `novalidate`; HTML client validation is not the
  authority.
- Validation messages show inline under fields; the old top summary
  was removed.
- The submit button stays labelled `Next`; it disables briefly while
  Livewire validates instead of flashing `Saving`.
- Password is accepted by strength threshold, not rigid composition
  rules.
  - Visual progress bar under password field.
  - Server-side strength check mirrors the UI.
- Exchange picker:
  - Binance and Bitget are enabled.
  - Bybit and KuCoin are disabled, grayscale, and marked
    `Coming soon`.
- API keys moved into a modal opened by `Add API Keys`.
  - Background blur while open.
  - Modal width adjusted for readability while staying mobile-first.
  - Connectivity cannot be tested with empty keys.
  - During connectivity testing, modal controls are locked.
  - Success copy is `Connectivity verified, all good!`.
- Terms link uses `config('kraite.website_url')`, so it resolves to
  `kraite.test/terms-and-conditions` locally and `kraite.com` in
  production.
- Plans are loaded from the database subscriptions table.
- Browser E2E setup was added:
  - `.env.testing`
  - `playwright.config.js`
  - `scripts/prepare-e2e.sh`
  - deterministic registration fixture
  - `tests/e2e/registration.spec.js`

## Verification Completed

### Core

- PHP syntax checks passed on touched files.
- Pint passed.

### Ingestion

- `vendor/bin/pint --dirty --format agent`
- `php artisan test --compact tests/Feature/KraiteSeederTest.php`

### Public Site

- `vendor/bin/pint --dirty --format agent`
- `php artisan test --compact tests/Feature/LandingPageTest.php tests/Feature/PrivateBetaSignupResendTest.php tests/Unit/PrivateBetaEmailVerificationMessageTest.php`
- `npm run build`
- Browser render check confirmed the Terms page renders on desktop and
  mobile, with SVG icons and smooth scrolling.

### Admin

- `vendor/bin/pint --dirty --format agent`
- `php artisan test --compact tests/Feature/RegistrationTest.php`
- `npm run test:e2e -- registration.spec.js`
- `npm run build`

Known build note: admin still prints the existing Vite warning about
`/logos/snake-white.svg` remaining unresolved until runtime. The build
passes.

## Decisions Locked In

- Registration is trial-first. No NowPayments redirect during
  onboarding.
- User lands directly on the admin registration form after email
  verification.
- The registration form is a dedicated page without admin sidebar/topnav.
- Server-side Livewire validation is the source of truth.
- Only Binance and Bitget are selectable for now.
- Bybit and KuCoin stay visible but unavailable with `Coming soon`.
- API keys are collected in a modal to keep the main form height stable.
- Password acceptance is strength-based.
- Terms URL is dynamic through app/config, not hardcoded.
- Support/legal contact shown to users is always `support@kraite.com`.

## Important Open Items

### Real Multi-Server Connectivity

The registration UI has the modal and connectivity UX, but the real
multi-server step-dispatcher flow is still the next backend phase.

Target architecture remains:

1. Admin receives a connectivity test request with exchange credentials.
2. A parent connectivity test step is created.
3. A child step is dispatched to every server that needs whitelisting.
4. Each server tests the exchange from its own IP.
5. Aggregation is strict: every required server must pass.
6. Failures show the failing server/IP.
7. If the user continues without passing connectivity, account creation
   should set `accounts.can_trade = false`.
8. A later successful retest flips `accounts.can_trade = true`, which
   triggers the existing `AccountObserver` flow to dispatch trading
   preparation.

Security detail to decide before implementation: do not persist raw API
credentials in step arguments if those arguments are plain JSON. Prefer
encrypted transient storage keyed by block UUID, or another short-lived
secure handoff.

### Terms Review

The Terms and Conditions page is a strong first product/legal draft,
but it still needs review by qualified legal counsel before production
launch.

### Production Deployment Checks

Before deploying:

- Confirm production `APP_URL` values:
  - public site: `https://kraite.com`
  - admin: `https://admin.kraite.com`
- Confirm `KRAITE_WEBSITE_URL` is either unset or explicitly
  `https://kraite.com`.
- Confirm `.env.kraite` contains the correct Resend key.
- Run ingestion seed/migration flow in the expected deployment order so
  the `kraite` credentials row has `resend_api_key`.

## Next Suggested Work

1. Implement real multi-server connectivity checks using step
   dispatcher.
2. Add profile/dashboard retest flow for accounts created with
   `can_trade = false`.
3. Tighten post-registration success dashboard copy around trial,
   private-beta coupon, and next steps.
4. Send the Terms and Conditions draft for legal review.
