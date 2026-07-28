# WhereAreWe

_Last updated: 2026-07-29_

## Session 2026-07-29 — transferred money stopped counting as profit

- A 1,100 deposit into a ~3,900 account exposed three places where cash paid
  in was reported as performance. Daily rates now divide by the wallet each
  day actually opened with, window returns chain those rates instead of
  leaning on one frozen opening balance, and the monthly/yearly outlook is
  what was delivered chained with growth on today's wallet.
- The dashboard's portfolio tile shows trading movement only — a transfer no
  longer paints a green double-digit gain, and a day without closes shows no
  chip at all.
- The public site's daily return, overall return, projected month, and
  sparkline inherit the same engine fix.
- Left as-is deliberately: the system dashboard's "capital under management"
  delta still counts a transfer, because that tile measures capital, not
  performance.
- Targeted coverage green: 31 tests / 197 assertions in admin, plus the
  public-stats fleet path on `kraite.test`.

## Session 2026-07-25 — ladder vocabulary, last close, expired sign-ins

- Fleet positions: a rung that has filled leaves the ladder drawing, and the
  pending-rung distance is now called **alpha limit** instead of "next rung".
- Trader dashboard: the open-positions heading states how many positions are
  active and how long ago the account last closed one.
- Sign-in pages no longer dead-end on the "419 PAGE EXPIRED" wall. A stale page
  refreshes itself when the tab is looked at again (never while something is
  typed), and a stale submission bounces back with the email kept and a plain
  explanation.
- Admin's production lock moved to the fleet's current engine code
  (`kraitebot/core` v1.84.2, `brunocfalcao/step-dispatcher` v1.20.1) — it was
  the only application still pinned to the older pair.
- Full suite green: 255 tests / 1322 assertions.

## Session 2026-07-23 — selected-token direction (v0.28.2)

- The backtesting selector now keeps the selected token's concluded LONG or
  SHORT direction visible after the dropdown closes.
- Focused Backtesting coverage passes: 7 tests / 41 assertions; the production
  frontend build passes.

## Session 2026-07-23 — honest backtest history coverage (v0.28.1)

- Fresh, gap-free candles are no longer enough to display **Complete & live**;
  the archive must also reach the requested history boundary.
- A run attempts to fill that complete window first. If source history remains
  thin, the final result stays advisory and the operator sees the warning.
- Focused regression coverage passes: 15 tests / 58 assertions.

## Session 2026-07-06 — backtesting hardening (v0.13.0, uncommitted)

Deep review of the backtesting stack surfaced and fixed one real risk
hole plus smaller defects — all in CHANGELOG v0.13.0:

- **Decision proposal now requires evidence** — a run that simulated
  nothing (thin/no history, sizing rejections, fleet-fetch timeout) can
  no longer read as "Recommend approve"; Approve locks on zero-resolved
  runs. This guarded the real-money gate v0.12.0 accidentally widened.
- Sizing-skipped sims counted and visible (core v1.60.0:
  `totals.skipped`, `meta.days_to_ignore` — additive).
- Stop-loss coverage tiers finally rendered; dead filter chips replaced;
  AI-prompt fact fixes; sample-size unit honesty.
- Cookie auto-login lands by role (sysadmin → console).

## Current State

Admin is at **v0.12.0** (2026-06-27). The June arc (v0.8.x → v0.12.0) was
the backtesting console: the operator can now vet any Binance token —
fetch/verify candle history, run the ladder simulator, read a
stop-loss-driven decision proposal, search for a safe config adjustment,
and approve/reject with the result pushed to the live trader's gates.

Key behaviours locked in during that arc:

- **Decision is stop-loss-count driven** — under 5 stops → recommend
  approve, 5–10 → adjust (one-click safe-adjustment search), over 10 →
  recommend reject. Pass rate is visible but demoted.
- **Coverage gate is advisory, not blocking** (v0.11.0) — runs grade on
  the candles present and warn on imperfect data; approve/reject is the
  operator's final call.
- **Token dropdown lists all Binance symbols** (v0.12.0) — approval state
  no longer gates visibility (it used to hide everything not already
  enabled, a chicken-and-egg trap).

## Session 2026-07-05 — commands bridge + doc drift

- **Fixed: `/system/commands` was 500ing in production** since the
  2026-06-01 pheme web split. The page reads command list + schedule
  through the ingestion app; admin no longer lives next to ingestion, and
  the SSH bridge the code was designed to use was never provisioned.
  Bridge is now live (pheme → athena over the private network, key owned
  by the web-server user, config re-cached). Verified: schedule rows +
  38 kraite commands load over the bridge.
- **Hardened (uncommitted):** the commands page now degrades to an empty
  console instead of erroring if the bridge ever breaks again; command
  details return a readable error. Covered by
  `tests/Feature/SystemCommandsTest.php` (2 tests, green).
- **Doc drift fixed:** `04-admin/pages/system-commands.md` no longer
  claims admin and ingestion are co-located; deploy-notes Entry 94
  records the incident and rules.

## Pending

- Nothing — v0.13.0 released 2026-07-06 (includes the composer.lock
  dependency maintenance, the commands-console guard, the role-aware
  landing, and the backtesting hardening above).

## Open Items (carried)

- **Registration multi-server connectivity** — the per-host probe queues
  exist fleet-wide; whether the registration flow drives them end-to-end
  is unverified since the May snapshot. Re-check before onboarding real
  users.
- **Terms & Conditions** — still a product/legal draft; needs qualified
  legal review before public launch.
