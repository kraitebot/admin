# Changelog

All notable changes to the admin.kraite.com project.

## [0.52.0] — 2026-08-08

### Directional position target movement

- [NEW] The mobile dashboard payload exposes exact LONG/SHORT percentages to
  the take-profit and next-limit prices for compact position cards.
- [PRESERVED] Existing exchange-precision price fields and dashboard metrics
  remain unchanged.

## [0.51.1] — 2026-08-05

### Shared runtime dependency refresh

- [DEPENDENCY] Production ships `kraitebot/core` 1.102.2 and Step Dispatcher
  1.20.3 while preserving the existing Laravel runtime dependency set.
- [VERIFIED] The full Pest 5/TIA suite passes: 407 tests and 1,862
  assertions, with a clean production-only Composer install.

## [0.50.1] — 2026-08-04

### Laravel-owned operational monitoring

- [CHANGED] Admin no longer owns or re-seeds the fleet heartbeat schedule;
  ingestion is the single cadence owner and the dashboard remains a reader.
- [VERIFIED] Targeted Pest 5/TIA coverage passes: 11 tests and 62 assertions;
  PHP syntax and Pint checks pass.

## [0.50.0] — 2026-08-02

### Trader-day BTC market context

- [NEW] The mobile dashboard payload includes BTC's trader-day percentage
  change from the exact 15-minute candle at the configured reporting midnight.
- [VERIFIED] The reporting-day calculation uses the authenticated trader's
  configured UTC offset and preserves exchange-precision live marks.

## [0.49.0] — 2026-08-02

### BTC market context for the trader Dashboard

- [NEW] The mobile dashboard payload includes Binance BTC's exchange-precision
  mark, 17-point four-hour 15m series, icon/name, and four active timeframe
  direction signals.
- [PRESERVED] The payload remains bounded and read-only, using existing candle
  data without adding exchange requests or ingestion work.

## [0.47.2] — 2026-08-01

### Strict role separation and accurate open P&L

- [SECURITY] Sysadmins are confined to the system workspace across browser,
  mobile API, token, registration-handoff, billing, and account-data paths;
  trader routes can no longer expose client surfaces or data to an admin
  session left open on the same computer.
- [FIXED] Open-position entry prices and unrealized P&L use the weighted
  average of filled entry orders, matching the exchange after ladder fills
  instead of overstating movement from the first fill alone.

## [0.47.1] — 2026-08-01

### Secure frontend dependency refresh

- [DEPENDENCY] Axios is updated to 1.19.0 after a new high-severity advisory
  made the previously tested lock fail the live registry audit.
- [VERIFIED] The npm dependency audit reports zero vulnerabilities.

## [0.47.0] — 2026-08-01

### Laravel 13 and safer database diagnosis

- [ADDED] The System SQL workspace accepts bounded read-only queries with
  binding support and pagination, so production diagnosis no longer requires
  direct database access or permits mutations.
- [CHANGED] Production runs Laravel 13.23.0 with `kraitebot/core` 1.98.0,
  AI Bridge 1.4.0, Step Dispatcher 1.20.2, and Blade Feather Icons 6.0.3.
- [CHANGED] Local tests use Pest 5 with automatic TIA. Production installs an
  exact runtime-only lock with no test, TIA, or development scope.
- [VERIFIED] The full suite passes: 388 tests and 1,779 assertions, plus the
  frontend build, static analysis, formatting, and production-lock audit.

## [0.46.1] — 2026-08-01

### Background trader notifications show one unread event

- [DEPENDENCY] Production ships `kraitebot/core` 1.96.1 so background iPhone
  pushes mark the Kraite Home Screen badge with `1` until the app reads them.
- [UNCHANGED] The production lock moved for `kraitebot/core` alone.

## [0.46.0] — 2026-07-30

### The trader app owns its notification history

- [ADDED] Authenticated iPhones can register their Expo token and safely move
  the same physical device between trader accounts.
- [ADDED] The mobile API returns only the current trader's app-channel
  notification history, newest first, with cursor pagination.
- [ADDED] Signing out disables that phone for the current trader before the
  access token is removed.
- [DEPENDENCY] Production ships `kraitebot/core` 1.96.0 for push delivery,
  encrypted device storage, audit history, and app-only breaker events.
- [VERIFIED] Mobile notification API coverage passes: 4 tests and 39
  assertions.

## [0.45.0] — 2026-07-29

### Engine refresh

- [CHANGED] Ships `kraitebot/core` 1.95.0, which meters every Binance call
  against the shared per-address budget instead of only checking once when a
  job starts. Admin makes exchange calls through the same client, so the
  console can no longer contribute to an unmetered burst either.
- [CHANGED] Third-party dependencies refreshed for local development
  (Guzzle, Resend, Pint, Symfony polyfills). Production resolves nothing on
  the server — its lock moved for `kraitebot/core` alone, verified by diff.

## [0.44.0] — 2026-07-29

### Daily profit is now counted the way your exchange counts it

- Your daily figure is built from the exchange's own record of every fee and
  fill, each counted on the day it was actually charged. A position you open
  one evening and close the next morning now leaves its opening fee on the
  first day, and funding on a position you are still holding counts the day
  it is taken — not whenever that position eventually closes.
- That was the last gap against Binance: for the same hours we read +11.51
  where the exchange read +9.83, because we were piling a trade's whole cost
  onto its closing day. Same money, wrong day.
- Older months are untouched. They keep the figures they always had, since
  the exchange only serves recent history.
- Landing in another country now offers to move your trading day to local
  time — once, with Keep and Switch, on the dashboard and on the phone. It
  never changes on its own: your trading day is set to match your exchange,
  and that setting does not travel with you.
- Production engine pinned to core v1.93.1.

## [0.43.0] — 2026-07-29

### Your trading day now starts when your exchange says it does

- Your profile carries a **Trading day basis** — the hour your trading day
  rolls over. Match it to your exchange (on Binance: Settings → Trade
  Preference → Change Basis) and daily profit here covers exactly the same
  hours it does there. Everyone stays on UTC until they change it.
- P&L today, the projections calendar squares, the scenario band, the
  month's opening and closing edges and the clock in the header all follow
  that basis. A close at 22:30 UTC lands on tomorrow's square for a UTC+2
  trader, exactly as it does on their exchange statement.
- The phone shows which basis its calendar is drawn on and opens on the
  month you are actually in, rather than the one your handset's clock is in.
- Stored times never move. Position history, order history and audit records
  stay UTC — only the day a figure is counted under follows the basis.
- Note it does not close the whole gap against an exchange's own daily
  figure: exchanges usually report total equity change including open
  positions, often converted to a display currency, while Kraite reports
  realised profit from closed trades in the settlement currency.
- Production engine pinned to core v1.92.0.

## [0.42.0] — 2026-07-29

### Money paid in is no longer counted as profit

- Every return percentage now divides by the capital that actually did the
  trading. Each day is scored against the wallet it opened with, and a
  window's return chains those daily rates together instead of measuring
  today's balance against the one the window opened with. Paying money into
  an account raises the euros it can earn and leaves its reported rate alone.
- The monthly and yearly outlook is what the period has already delivered
  chained with the growth still expected from today's wallet, so a deposit
  can no longer appear inside a projected return.
- The dashboard's portfolio tile reports trading movement only. A transfer
  stops painting a green double-digit gain, and a day with no closed
  positions shows no change at all rather than wallet drift.
- The projections calendar reads the month's own return from the engine
  instead of deriving it from balances, so its headline and its
  "real vs projected" split agree with everything else.
- Unchanged on purpose: the system dashboard's capital-under-management
  delta still counts transfers, because that tile measures capital rather
  than performance.
- Production engine pinned to core v1.91.0, which carries the shared fix for
  admin and kraite.com alike.

## [0.41.0] — 2026-07-28

### The mobile API now promises the next market assessment

- The mobile dashboard's market-regime summary carries a countdown to the
  next hourly score recompute (`next_compute_in`, pre-phrased "in 37m" /
  "about now"), so the phone can show when the market gets reassessed instead
  of only how old the current reading is.
- Production engine pinned to core v1.90.0, the newest published tag.

## [0.40.0] — 2026-07-27

### The dashboard now names every pause, including the error-storm monitor

- The "openings paused" banner covers all three pause sources and says which
  one is holding: market shock breaker (with resumption countdown), black-swan
  regime gate (score + countdown), or the error-storm monitor — which shows
  "holds until cleared from Runtime Settings" instead of a countdown it would
  not honour. Until now the monitor pause was invisible: on 2026-07-27 opens
  sat parked for four hours while the widget read calm.
- The mobile API dashboard tile carries the same pause surface (reason,
  until-when, human countdown) so the phone can display it.

## [0.39.0] — 2026-07-27

### The circuit-breaker pause is now tunable from Runtime Settings

- New "Cooldown window" field under BSCS safety: how many hours new openings
  stay paused after a critical black-swan score (1–168). Blank inherits the
  engine default, now 12 hours. Changes apply on the next hourly analysis and
  land in the settings audit trail like every other runtime control.
- Ships engine v1.88.0.

## [0.38.1] — 2026-07-27

### Position projections can no longer be poisoned by an unpriced fill

- A market entry whose fill price has not landed yet (or was historically
  corrupted to zero, as in the TOSHIUSDT incident) is now excluded from the
  ladder projection grid instead of contributing cost-free quantity — which
  dragged the average entry toward zero and made every projected outcome,
  including the stop-loss row, look like enormous profit.
- The four corrupted historical entries were corrected in the trading records
  from the archived exchange fill events, so old TOSHIUSDT and AWEUSDT rows
  now display truthfully too.

## [0.38.0] — 2026-07-26

### Backtesting shows when a decision was taken, and what it was taken on

- [NEW FEATURE] A token that already carries an approve or reject now states
  when that call was made, right under the Decision header. Decisions older
  than the audit trail say so plainly instead of showing nothing.
- [NEW FEATURE] "Reload current configuration" puts the token's live
  take-profit, stop-loss and both ladder gaps back into the form, so re-testing
  an approved token starts from the conditions the standing decision was taken
  against instead of the account defaults.
- [IMPROVED] Every approve and reject — including a reversal — stamps its own
  date from now on, and the date follows the decision to the token's other
  listings.
- [DEPENDENCIES] Ships the shared trading core 1.86.0, which carries the
  decision-date field and the audit-trail backfill.
- [VERIFIED] Full suite green: 272 tests / 1,387 assertions, plus the
  production frontend build.

## [0.37.1] — 2026-07-26

### Search control sits straight

- [BUG FIX] The magnifier in the closed-positions search sat below the text it
  belonged to. Icon, field and clear button now share one row on the same
  centre line, and the whole control highlights on focus.
- [IMPROVED] The field reads "Search positions…" and no longer renders the
  browser's own clear widget beside ours.
- [VERIFIED] Full suite green: 269 tests / 1,375 assertions, plus the
  production frontend build.

## [0.37.0] — 2026-07-26

### Search the closed-position history

- [NEW FEATURE] Closed positions can be searched by market. Typing the token
  or the full pair narrows the list to that market — the stored token and the
  pair a trader actually types both match, along with side and close reason.
- [IMPROVED] The pager follows the search: page count recalculates, the view
  returns to page one, and the footer marks a narrowed list as filtered so it
  cannot be mistaken for the whole 48-hour window.
- [IMPROVED] A search that matches nothing says so, instead of leaving an empty
  table above a pager. Escape or the clear button restores the full list, and
  the query survives the ten-second refresh.
- [VERIFIED] Full suite green: 269 tests / 1,372 assertions, plus the
  production frontend build.

## [0.36.1] — 2026-07-26

### Dots that name their spans, one account stated plainly

- [IMPROVED] The price-direction help now names the spans behind the dots and
  the order they are read in, instead of saying "a few different time spans".
  The list comes from the engine's own configuration, so changing the spans
  changes the explanation. It also says a span with no fresh data is left out,
  which is why the dot count sometimes differs.
- [IMPROVED] The dashboard states the account plainly when there is only one —
  same status dot, name and exchange, nothing to click. This matches the change
  made to Projections, so both pages now behave the same way.
- [VERIFIED] Full suite green: 268 tests / 1,362 assertions, plus the
  production frontend build.

## [0.36.0] — 2026-07-25

### Landing, exposure line, and single-account clarity

- [BUG FIX] Signing in never dumps anyone on the "403 ADMIN ACCESS REQUIRED"
  wall any more. When a sysadmin session expires on a sysadmin page, that page
  waits as the login's destination and the next person inherits it — a trader
  now drops it and lands on their own dashboard. A sysadmin still returns
  exactly where they were, and a waiting page the trader may open is still
  honoured. The decision reads the page's own access rules rather than a URL
  prefix, so an admin-only page added elsewhere is covered by default.
- [IMPROVED] Portfolio value states its exposure on one line — shorts and longs
  as a share of the portfolio. The dollar figures were dropped from the tile;
  they already sit on every position card.
- [IMPROVED] Projections shows the account plainly when there is only one:
  same exchange, name and equity, with nothing to click. The picker appears
  once a second account exists.
- [VERIFIED] Full suite green: 265 tests / 1,352 assertions, plus the
  production frontend build.

## [0.35.1] — 2026-07-25

### Sign-up hand-off covered, engine code realigned

- [IMPROVED] The last hop of sign-up is now covered: the one-time link that
  drops a brand-new trader straight into their dashboard is pinned as working
  once, dying after use, and refusing both unknown links and accounts that were
  never activated.
- [DEPENDENCIES] Ships the shared trading core 1.85.0, which carries the
  columns recording a trader's acceptance of the Terms and Conditions. Admin
  itself reads and writes nothing new; this keeps it on the same engine code as
  the rest of the fleet.
- [VERIFIED] Full suite green on the refreshed core: 260 tests /
  1,341 assertions.

## [0.35.0] — 2026-07-25

### Ladder clarity, last close, and expired sign-ins

- [IMPROVED] A ladder rung that has already filled no longer sits on the
  position's corridor drawing. Only the rungs the price is still travelling
  toward stay on the line; how many have filled is still reported alongside it.
- [IMPROVED] The pending-rung distance is called **alpha limit** everywhere,
  matching alpha path. The improvised "next rung" wording is gone.
- [NEW FEATURE] The trader dashboard's open-positions list is headed by how
  many positions are active and how long ago the account last closed one, and
  the elapsed time ages live between refreshes.
- [BUG FIX] A sign-in page left open no longer dead-ends on the raw
  "419 PAGE EXPIRED" wall. A stale page quietly refreshes itself when the tab
  is looked at again — never while something has been typed into it — and a
  stale submission bounces back to the sign-in page with the email still
  filled in and a plain explanation. Callers expecting JSON get the same
  explanation as a message.
- [SAFETY] That bounce only ever returns the visitor to one of our own pages,
  so a cross-site submission cannot use it to send them somewhere else.
- [DEPENDENCIES] Admin now installs the same engine code the rest of the fleet
  already runs (shared trading core v1.84.2, step dispatcher v1.20.1). It was
  the only application still pinned to the older pair.
- [VERIFIED] Full suite green on the refreshed engine: 256 tests /
  1324 assertions, plus the production frontend build.

## [0.34.1] — 2026-07-25

### Reproducible production dependencies

- [FIXED] The release tag now determines exactly which engine code production
  runs. A production lock is committed alongside the production manifest, and
  deploys install from it instead of resolving versions fresh each time.
- [FIXED] A from-scratch rebuild of the server can install its dependencies
  again. The previous lock resolved local development packages and could never
  install against the production manifest.
- [SAFETY] The lock is pinned to the versions production was already running,
  so this release changes no engine behaviour. Resolving without pinning would
  have moved the shared trading core and the step dispatcher to newer releases
  as an invisible side effect.
- [SAFETY] Tagging now refuses to proceed when the production lock is missing
  or pins any development version.
- [VERIFIED] A clean install from the committed lock reproduces the exact
  package versions running in production, with no development versions present.

## [0.34.0] — 2026-07-25

### Mobile account configuration

- [NEW FEATURE] The mobile API serves the trader's own accounts with
  connectivity health, subscription state, open-position count, current trading
  configuration, and the curated option lists the app may offer.
- [NEW FEATURE] Traders can save an account's name, quote currencies, profit
  target, stop-loss, and per-direction slots, leverage, and margin from the
  phone, and stop new trading immediately without saving other edits.
- [SAFETY] Account writes sit behind a separate `accounts:write` ability, so a
  device token can read everything while changing nothing. Tokens issued before
  account editing existed keep reading and are refused on writes.
- [SAFETY] The sysadmin cross-user override granted in the browser is disabled
  on every mobile route; an administrator on the phone sees and edits only
  accounts they own. The API still cannot place, change, or close an order.
- [VERIFIED] Focused account and mobile coverage passes: 49 tests /
  297 assertions.
- [SKIPPED] The complete suite was intentionally omitted by the light-release
  policy after targeted coverage passed.

## [0.33.0] — 2026-07-25

### Database-backed runtime settings

- [ADDED] The sysadmin Settings page can persist trading gates, notification
  delivery, correlation and elasticity controls, BSCS freshness, and
  position-trail retention on the shared singleton.
- [ADDED] Nullable controls can return to their configured defaults; the page
  shows the effective opening posture, read-only BSCS state, and five latest
  changes.
- [SAFETY] Every effective change is row-locked, validated, and audited with a
  sanitized administrator before/after snapshot; crafted boolean aliases are
  rejected and no-op saves create no database write or audit event.
- [VERIFIED] Focused Settings coverage passes: 7 tests / 63 assertions;
  frontend assets, Blade templates, routes, and operator documentation compile
  successfully.
- [SKIPPED] The complete suite was intentionally omitted by the light-release
  policy after targeted coverage passed.

## [0.32.4] — 2026-07-25

### Directional disaster exposure

- [IMPROVED] The Portfolio Value tile separates maximum-pain exposure into
  SHORT and LONG lines, each with its own loss amount and percentage of the
  current portfolio.
- [SAFETY] Missing maximum-pain data only hides the affected direction, while
  an ungroupable position keeps both directional figures unknown.
- [VERIFIED] Focused dashboard coverage passes: 8 tests / 32 assertions;
  frontend assets, Blade templates, and operator documentation compile
  successfully.
- [SKIPPED] The complete suite was intentionally omitted by the light-release
  policy after targeted coverage passed.

## [0.32.3] — 2026-07-25

### Portfolio disaster exposure

- [ADDED] The Portfolio Value tile shows the combined frozen maximum pain if
  every open position reaches its stop, including its percentage of the
  current portfolio.
- [ADDED] The Today P&L tile separately shows the current signed unrealised
  P&L across all open positions.
- [SAFETY] An incomplete maximum-pain set stays unknown instead of presenting
  a partial subtotal, and the mobile dashboard payload remains unchanged.
- [VERIFIED] Focused dashboard coverage passes: 8 tests / 29 assertions;
  frontend assets, Blade templates, and operator documentation compile
  successfully.
- [SKIPPED] The complete suite was intentionally omitted by the light-release
  policy after targeted coverage passed.

## [0.32.2] — 2026-07-25

### Position risk visibility

- [ADDED] Open-position cards show the frozen maximum-pain amount between the
  live price and unrealised P&L.
- [FIXED] Open-position table values align centrally beneath their headers.
- [SAFETY] Ambiguous legacy positions keep maximum pain unknown instead of
  recalculating against a mutable order graph.
- [VERIFIED] Focused position coverage passes: 5 tests / 24 assertions;
  frontend assets compile successfully.
- [SKIPPED] The complete suite was intentionally omitted by the light-release
  policy after targeted coverage passed.

## [0.32.1] — 2026-07-25

### Fleet service diagnostics

- [IMPROVED] Every non-running service is named below its server with the
  reported state; missing and stale heartbeats receive explicit issue lines.
- [FIXED] Service tooltips escape the Fleet card and remain above adjacent
  dashboard panels.
- [VERIFIED] Focused Fleet coverage passes: 13 tests / 82 assertions; frontend
  and operator-documentation builds pass.
- [SKIPPED] The complete suite was intentionally omitted by the light-release
  policy after targeted coverage passed.

## [0.32.0] — 2026-07-24

### Mobile position history and projections

- [ADDED] The owner-scoped mobile API now exposes closed-position history with
  expandable trade data and cursor pagination.
- [ADDED] The mobile API exposes calendar profit, five-year projections, and
  pessimistic, neutral, and optimistic scenarios using the admin calculation
  services.
- [SAFETY] Authentication, dashboard-read ability, account ownership, and API
  throttling protect both endpoints.
- [VERIFIED] Focused mobile and projection coverage passes: 34 tests / 279
  assertions.
- [SKIPPED] The complete suite was intentionally omitted by the light-release
  policy after targeted coverage passed.

## [0.31.4] — 2026-07-24

### Binance-compatible daily amplitude

- [FIXED] Max daily amplitude now matches Binance's range by normalizing the
  UTC-day high-low spread against the previous daily close.
- [IMPROVED] The card displays two decimals and explains that the first
  available day has no prior close.
- [SAFETY] Ships `kraitebot/core` 1.82.3 without changing simulations, grades,
  risk scores, proposals, or approval rules.
- [VERIFIED] Focused Backtesting coverage passes: 12 tests / 58 assertions;
  production frontend and operator documentation builds pass.
- [SKIPPED] The complete suite was intentionally omitted by the light-release
  policy after targeted coverage passed.

## [0.31.3] — 2026-07-24

### Backtest daily amplitude

- [IMPROVED] The backtest summary replaces Max MAE with the largest price
  amplitude recorded inside one UTC calendar day.
- [IMPROVED] The result identifies the day and explains the high-to-low
  calculation without changing grading or approval rules.
- [SAFETY] Ships `kraitebot/core` 1.82.2 for selected-window UTC-day
  aggregation while preserving MAE inside the existing risk grade.
- [VERIFIED] Focused Backtesting coverage passes: 10 tests / 55 assertions;
  production frontend and operator documentation builds pass.
- [SKIPPED] The complete suite was intentionally omitted by the light-release
  policy after targeted coverage passed.

## [0.31.2] — 2026-07-24

### Projection navigation icons

- [IMPROVED] Monthly and Yearly projection links now include compact,
  distinct icons in the desktop rail and mobile navigation drawer.
- [REMOVED] The unused surface-switch and notification controls no longer
  occupy the top bar.
- [VERIFIED] Focused Projections and sidebar browser coverage passes; the
  console shell, production frontend, and Blade templates compile successfully.
- [SKIPPED] The complete suite was intentionally omitted by the light-release
  policy after targeted coverage passed.

## [0.31.1] — 2026-07-23

### Projection navigation highlight

- [FIXED] Monthly and Yearly keep their active rail highlight aligned with
  the selected child link instead of drawing the marker near the rail header.
- [FIXED] Active projection children remain visible in short desktop rails
  after direct navigation, resizing, and rapid section toggles.
- [VERIFIED] Focused sidebar browser coverage passes: 2 tests; related
  Projections and shell coverage passes: 22 tests / 143 assertions.
- [SKIPPED] The complete suite was intentionally omitted by the light-release
  policy after targeted coverage passed.

## [0.31.0] — 2026-07-23

### Five-year portfolio projections

- [ADDED] Projections expands into Monthly and Yearly views while preserving
  the existing Monthly account calendar and milestone behavior.
- [ADDED] Yearly combines the trader's visible account wallets and projects
  the current year-end plus four following year-ends.
- [ADDED] Pessimistic, neutral, and optimistic paths report projected wallet,
  profit from today, growth, portfolio multiple, and compounding days.
- [SAFETY] Trader portfolios remain owner-scoped; sysadmin retains global
  visibility. Invalid complete-loss rates are shown as unavailable.
- [SAFETY] Ships `kraitebot/core` 1.82.0 for exact long-range decimal
  compounding without floating-point drift.
- [VERIFIED] Focused Projections coverage passes: 15 tests / 122 assertions;
  production frontend, Blade, and operator documentation builds pass.
- [SKIPPED] The complete suite was intentionally omitted by the light-release
  policy after targeted coverage passed.

## [0.30.0] — 2026-07-23

### Profit-funded milestone

- [ADDED] Projections shows when account profit can replace all personal
  capital still funding the bot under three compound-growth scenarios.
- [ADDED] The investment basis is assessed automatically from wallet history
  and exchange-reported PnL, including a warning when closed-position PnL is
  incomplete.
- [ADDED] A temporary additional-investment simulation recalculates the wallet,
  target, profit still needed, and projected dates without changing account
  data.
- [SAFETY] Ships `kraitebot/core` 1.81.0 so wallet ordering and investment-basis
  boundaries are shared across callers.
- [VERIFIED] Focused Projections coverage passes: 10 tests / 57 assertions;
  production frontend and operator documentation builds pass.
- [SKIPPED] The complete suite was intentionally omitted by the light-release
  policy after targeted coverage passed.

## [0.29.2] — 2026-07-23

### Toast positioning

- [FIXED] Backtesting and Engine status messages use transform-free viewport
  centering and remain above the application footer.
- [VERIFIED] WebKit and Chromium geometry checks pass at desktop and mobile
  widths; focused coverage passes with 15 tests / 92 assertions.

## [0.29.1] — 2026-07-23

### Backtesting toast centering

- [FIXED] Backtesting status messages remain horizontally centered while their
  entrance animation runs.
- [IMPROVED] Long warning messages wrap within the viewport.

## [0.29.0] — 2026-07-23

### Stop-loss recency

- [ADDED] Each LONG and SHORT stop-loss coverage panel shows how long ago its
  newest simulated stop occurred.
- [UNCHANGED] Recency is review context only; approval bands and automatic
  trading eligibility remain unchanged.
- [SAFETY] Ships `kraitebot/core` 1.80.2 with accurate rejected-slot
  diagnostics.
- [VERIFIED] Focused Backtesting coverage passes: 9 tests / 46 assertions;
  production frontend build and formatting pass.

## [0.28.2] — 2026-07-23

### Selected-token direction

- [IMPROVED] The backtesting selector keeps the selected token's concluded
  LONG or SHORT direction visible after the dropdown closes.
- [VERIFIED] Focused Backtesting coverage passes: 7 tests / 41 assertions;
  production frontend build passes.

## [0.28.1] — 2026-07-23

### Honest backtest coverage

- [FIXED] A short but fresh candle series is labelled **Thin history** instead
  of **Complete & live**.
- [FIXED] Running a backtest now attempts the full requested history window,
  and the final verdict keeps the warning when sources cannot fill it.
- [SAFETY] Ships `kraitebot/core` 1.80.1 so pre-fetch and post-fetch coverage
  decisions share the same history-depth rule.
- [VERIFIED] Focused coverage passes: 15 tests / 58 assertions; production
  frontend build passes.

## [0.28.0] — 2026-07-23

### Operator clarity

- [ADDED] Account settings explain how runtime BSCS controls can reduce saved
  trading limits without changing profit target or stop-loss.
- [ADDED] Backtesting token rows show their concluded direction.
- [CHANGED] The retired Revenue navigation entry is removed.
- [SAFETY] Ships `kraitebot/core` 1.80.0 and uses its typed backtest timeframe
  contract across validation and tests.
- [VERIFIED] Focused coverage passes: 38 tests / 192 assertions; after a
  compatibility correction, the full suite passes: 191 tests / 919 assertions.

## [0.27.7] — 2026-07-23

### Strict Top 100 intersection

- [FIXED] Top 100 filters now exclude unranked symbols, including when
  combined with Immediate Tradeable.
- [VERIFIED] Focused Backtesting coverage and production frontend build pass.

## [0.27.6] — 2026-07-23

### Immediate tradeable approval queue

- [ADDED] Backtesting has an **Immediate Tradeable** filter for Binance
  symbols where approval is the final action before live eligibility.
- [CHANGED] Every checked token filter now intersects, including **Immediate
  Tradeable** + **Top 100**.
- [SAFETY] Ships `kraitebot/core` 1.79.2 so the filter uses the live
  new-position eligibility policy instead of a UI approximation.
- [VERIFIED] Focused Backtesting coverage passes: 7 tests / 30 assertions;
  production frontend and operator documentation builds pass.

## [0.27.5] — 2026-07-23

### Immediate trading stop

- [FIXED] Switching an account to **NOT TRADING** persists immediately and
  changes only its opening permission; unrelated unsaved configuration stays
  untouched.
- [FIXED] The switch shows the stored account permission and remains available
  for turning off during degraded connectivity or an inactive subscription.
- [SAFETY] Ships `kraitebot/core` 1.79.1 so queued openings recheck trading
  readiness before market entry while existing exposure remains managed.
- [VERIFIED] Account endpoint/UI regressions pass: 23 tests / 130 assertions;
  production frontend build passes.

## [0.27.3] — 2026-07-22

### Engine polling reliability

- [FIXED] Dispatcher state aggregates now remain readable through the locked
  production cache policy, keeping repeated Engine polling healthy.
- [VERIFIED] Targeted Engine and dispatcher-cache tests pass; production
  frontend build passes.

## [0.27.2] — 2026-07-22

### Engine processing filter

- [FIXED] **Only processing** now keeps every class with a visible Running
  count, including active parent block classes.
- [VERIFIED] Targeted Engine tests pass: 8 tests / 39 assertions; production
  frontend build passes.

## [0.27.1] — 2026-07-21

### BSCS and mobile dashboard

- [IMPROVED] Admin BSCS consumers use the shared account-scoped facade for
  state and effective directional position caps.
- [IMPROVED] The mobile dashboard reports the selected account's latest clean
  position close and its current BSCS-adjusted LONG/SHORT cap.

## [0.27.0] — 2026-07-21

### Mobile passkeys and market-risk clarity

- [NEW FEATURE] The read-only mobile API supports single-use passkey
  registration, sign-in, listing, and deletion, with exact Apple
  web-credentials association for the signed Kraite app.
- [IMPROVED] Password and passkey login share one revocable 30-day
  `dashboard:read` token contract.
- [IMPROVED] The trader dashboard explains each BSCS signal and shows the
  account-specific temporary LONG and SHORT cap without changing saved limits.
- [FIXED] Mobile BSCS serialization uses the selected account when computing
  its effective position cap, and malformed passkeys return validation errors.
- [SECURITY] Guzzle and PSR-7 are refreshed to advisory-free releases.

### Verification

- [VERIFIED] Full admin suite passes: 179 tests / 860 assertions.

## [0.26.1] — 2026-07-20

### Shared trading reliability

- Ships `kraitebot/core` 1.77.2 so every Kraite application uses the same
  working-order synchronization and fresh signed-retry contract.

## [0.26.0] — 2026-07-20

### Position lifecycle clarity

- [IMPROVED] The open-position lifecycle rail now ends at the live stop-loss.
  After all averaging orders fill, the next adverse-price point changes from
  the final ladder rung to SL while TP, price, and completed rungs remain clear.
- [ADDED] Serialization and geometry regression coverage for active and
  cancelled protection orders, WAP progress, and the fully-filled ladder.

## [0.25.1] — 2026-07-20

### Core 1.76.0 + account tuning

- Ships `kraitebot/core` 1.76.0 — Bitget unified-account reads, the
  evidence-driven own-activity protection flags, and connectivity probe
  message translation reach the operator console (step records now carry
  plain-language probe errors).
- Account settings accept a single-position slot configuration
  (total positions long/short may now be 1).

## [0.25.0] — 2026-07-19

### Mobile market-risk visibility

- [NEW FEATURE] **The iPhone dashboard receives a compact BSCS summary.**
  Traders can see the current score, band, posture, freshness, block state,
  and configured block threshold without gaining any trading control.
- [IMPROVED] The mobile contract remains bounded: BSCS sub-signals and
  cooldown internals stay on the operator surfaces.

### Tests

- [ADDED] Regression coverage proves the exact mobile BSCS response, empty
  first-compute state, critical boundary, read-only behavior, and omission of
  operator-only fields.
- [VERIFIED] Full admin suite passes: 161 tests / 775 assertions.

## [0.24.0] — 2026-07-19

### Read-only mobile API

- [NEW FEATURE] **Mobile users can authenticate with revocable device
  tokens.** Valid trader credentials issue a 30-day token limited to dashboard
  reads, while logout revokes only the current device.
- [NEW FEATURE] **The first mobile dashboard is trader-owned and read-only.**
  It returns the user's accounts, selected-account KPIs, and open positions
  without exposing trading, account edits, billing, or engine controls.
- [IMPROVED] Mobile routes have application throttling, failed-login lockout,
  bounded cached payloads, and the same security headers as browser routes.
- [IMPROVED] Gemini configuration uses the current 3.1 Pro Preview identifier
  and the active 3 Flash Preview model.

### Tests

- [ADDED] Regression coverage for token issue, rejection, ability enforcement,
  account isolation, bounded dashboard reads, and current-token logout.
- [VERIFIED] Full admin suite passes: 158 tests / 749 assertions; production
  frontend build passes.

## [0.23.1] — 2026-07-18

### Billing and local clones

- [IMPROVED] **Wallet top-ups open NOWPayments directly in a new tab.** The
  intermediate confirmation is removed while the billing page stays available.
- [FIXED] **Local user quick-picks use the configured clone password.** The
  shortcut remains local-only and never renders user credentials in production.

### Tests

- [ADDED] Regression coverage for direct hosted checkout and environment-gated
  cloned-user quick-picks.
- [VERIFIED] Targeted admin release tests pass: 21 tests / 110 assertions;
  production frontend build passes.

## [0.23.0] — 2026-07-17

### Account safety and connectivity

- [NEW FEATURE] **Account cards show final trading readiness and fleet
  connectivity separately.** Subscription state, open exposure, active server
  blocks, and engine auto-stop state now produce honest controls and status.
- [IMPROVED] **A successful full-fleet retest repairs connectivity state.** It
  can reactivate accounts automatically stopped after every safe route was
  blocked and clears only that account's connectivity bans.
- [FIXED] **Quote changes cannot invalidate live exposure.** Portfolio and
  trading quotes stay locked while positions are open or opening remains
  enabled; Bitget cards expose both USDT and USDC products once unlocked.
- [FIXED] Connectivity actions no longer promise new positions when the user's
  subscription is inactive.
- [FIXED] The production dependency manifest now resolves tagged Core and AI
  Bridge releases instead of development branches.

### Tests

- [ADDED] Regression coverage for subscription gates, quote locks, connectivity
  health, scoped ban cleanup, auto-reactivation, and live JSON saves.
- [FIXED] CI checks out public local packages through Git transport so a
  degraded GitHub API cannot block the release before tests start.
- [VERIFIED] Full admin suite passes: 146 tests / 691 assertions; production
  frontend build passes.

## [0.22.2] — 2026-07-16

### Bitget USDC futures
- [FIXED] Production now ships Kraite Core 1.73.3, preserving tiny Bitget
  USDC tick sizes so admin trading metadata never displays a zero increment.

### Tests
- [VERIFIED] Full admin suite passes: 133 tests / 613 assertions; production
  frontend build passes.

## [0.22.1] — 2026-07-16

### Deployment safety
- [FIXED] Production now ships Kraite Core 1.73.2, so slow-query monitoring
  cannot interrupt fresh schema migrations before admin settings exist.

## [0.22.0] — 2026-07-16

### Changed
- [CHANGED] **Customer billing no longer offers subscription pause or
  resume.** The controls and public action endpoints are removed; plan
  changes and top-ups remain available, while existing paused back-end
  state continues to be honoured safely.
- [IMPROVED] **Plan-switch buttons use the primary action treatment,**
  making the next billing action visually clear.

### Tests
- [ADDED] Regression coverage proves pause/resume controls and endpoints
  are absent while the remaining billing actions still render.
- [VERIFIED] Full admin suite passes: 133 tests / 613 assertions.

## [0.21.1] — 2026-07-16

### Fixed
- [BUG FIX] **Dashboard position cards no longer count cancelled replacement history as extra ladder orders.** The lifecycle bar and Filled total now use only exchange-placed current or filled rungs, while the position's configured ladder size remains authoritative.

## [0.21.0] — 2026-07-15

### Features
- [NEW FEATURE] **Customer billing now performs real subscription actions.** Plan selection, paid-plan trials, pause/resume, wallet top-ups, live gateway minimums, signed quick top-ups, and payment history are connected to the existing billing controllers instead of changing browser-only state.
- [NEW FEATURE] **Sysadmins can operate billing from the UI.** The new Billing section exposes users, plans, and top-up coins with working wallet adjustments, subscription assignment, trial controls, plan/coin management, and clear validation feedback.

### Fixed
- [FIXED] **Black is shown as free forever, never as a broken trial.** Complimentary plans no longer display "trial ends now", a fake renewal date, or paid-plan trial controls. Switching away from a legacy complimentary account cannot accidentally grant a fresh paid trial.
- [FIXED] **NOWPayments webhooks credit every received increment exactly once.** Partial and out-of-order events apply only the positive uncredited delta, preserve forward status progress, reject mismatched gateway payment IDs, and use the same database-backed credentials as invoice creation.
- [FIXED] **Billing edge cases now fail safely.** Public users cannot select the invite-only Black plan, excessive admin debits return a useful error, trial renewal anchors are repaired, gateway actions are rate-limited, and payment-signature failures no longer log digest material.

### Improved
- [IMPROVED] **The customer billing page explains the current state plainly.** Trial countdowns use the real plan duration, renewal and wallet coverage copy matches the actual subscription state, and the sidebar now exposes Billing to the correct audience.
- [IMPROVED] **PHP and frontend dependencies were refreshed** through the requested project update while preserving the production manifest workflow.

## [0.20.2] — 2026-07-14

### Fixed
- [FIXED] **The bottom-left shell corner is now one clean surface.** The navigation rail and footer share one full-width border instead of drawing overlapping seams, and the active console item remains visible by scrolling the rail on shorter screens.

## [0.20.1] — 2026-07-14

### Improved
- [IMPROVED] **Account trading settings now explain themselves.** Profit target, stop-loss, position slots, leverage, and margin each show a short plain-language explanation below the input. The leverage and margin heading also renders its ampersand correctly.

## [0.20.0] — 2026-07-14

### Features
- [NEW FEATURE] **Account connectivity is now a real fleet test.** The account editor loads the eligible API-calling servers from the live `servers` table, starts the existing per-server connectivity workflow, polls each result, and only reports Connected after that server succeeds. Saved credentials can be re-tested without exposing them; replacement credentials are isolated in a draft and saved only after the completed result is applied.

### Fixed
- [FIXED] **Stale connectivity results cannot enable untested credentials.** Saved credentials are snapshotted for the test and the result is rejected if the account keys change before application.
- [FIXED] **Backtesting page compiles again.** Component-like text inside a JavaScript comment no longer gets parsed by Blade as two unclosed components.

## [0.19.3] — 2026-07-14

### Changed
- [CHANGED] **Expanded drift comparison stays open when the order resyncs.** Previously, expanding an out-of-sync order's exchange comparison and then having the 5-min re-check resolve it would snap the row shut mid-look. Now the row is tied to your toggle (not the live drift state): it shows the amber comparison while drifting, swaps to a green "back in sync — now matches the exchange" panel once resolved, and you close it yourself. An order that resolves before you ever opened it is left untouched.

## [0.19.2] — 2026-07-14

### Fixed
- [FIXED] **Runaway memory in long-open tabs.** Every page with a live auto-refresh — the trader positions and dashboard, plus the system console's engine / infra / positions / dashboard — now pauses its polling and view-rebuild while the browser tab is hidden. A backgrounded tab was rebuilding its whole live view every 10–15s for hours, growing memory until the browser force-reloaded the page ("This webpage was reloaded because it was using significant memory"). Polling resumes on the next tick once the tab is visible again. (Billing's 1s trial counter is display-only and left untouched.)

## [0.19.1] — 2026-07-14

### Fixed
- [FIXED] **False out-of-sync alarm on recently re-placed orders.** The positions comparator no longer flags an order as drifting when the exchange snapshot it compares against is older than the order's last change (e.g. a take-profit re-priced by a ladder-fill WAP). A stale exchange picture is not proof of drift — the order now reads "unverified" and the position stays "in sync." Verified against the live exchange: DB and Binance matched exactly; only the 2h-old snapshot disagreed.

### Features
- [NEW FEATURE] **Full [?] coverage on the dashboard position cards.** Added the remaining help popups — Filled, Entry, Take-profit, Next buy, and Profit/loss — so every metric on the tile is explained in plain language.

## [0.19.0] — 2026-07-14

### Features
- [NEW FEATURE] **Reusable help popups.** A global `<x-ui.help-dot>` "[?]" affordance plus one shared explainer modal (blurred backdrop, fade in/out, Alpine `help` store) — drop it on any page with no per-page boilerplate. Added to the projections month-type badge and the four dashboard position-card metrics (price direction, path, limit, live price), written in plain beginner language. The backtesting console's help was migrated onto the same shared system.
- [NEW FEATURE] **Out-of-sync fields are highlighted.** On the positions detail, the exact position field that drifts from the exchange (quantity / leverage / margin / opening price) lights up amber with a ⚠ marker so the eye lands on it — the banner previously only named the field.
- [IMPROVED] **Daily PnL calendar shows 2-decimal precision** (e.g. `+$3.14`) instead of rounded whole dollars, matching the exchange.

### Changed
- [CHANGED] **Timezone handling simplified.** The admin DB connection is pinned to UTC (`+00:00`) and the home-grown clock-skew / whole-hour age band-aids were removed, now that the engine records true UTC. The dashboard no longer masks a future timestamp as "just now" — a genuine clock/timezone regression now surfaces loudly instead of hiding.

## [0.18.0] — 2026-07-13

### Features
- [NEW FEATURE] **Registration handoff endpoint.** `/register-handoff/{token}` consumes the one-time, sha256-hashed login token minted by kraite.com's new public registration wizard: logs the fresh trader in, clears the token, and shows a welcome popup on the dashboard.

### Removed
- [REMOVED] **Invite-based registration surface deleted.** Registration now lives on kraite.com. The `/register/{uuid}` routes, RegistrationController, both form requests, all six `App\Support\Registration` classes and their tests are gone. Draft connectivity-test accounts keep the same naming convention, so account-list filtering is unaffected.

## [0.17.5] — 2026-07-12

### Fixes
- **Projections first pass — three bugs fixed.** Fast month-flipping could render a stale response into the wrong month (latest request now always wins); a hung response could leave the loading skeleton up and the Sync button dead forever (8-second cap, same guard as the rest of the panel); around the UTC midnight edge the calendar could open on the browser's idea of the current month instead of the server's (first response now snaps the view). Data lookups also moved out of the page template into the controller. First test coverage for the page: feed shape, owner gate, input validation.

### Improvements
- **Account picker cleaned up** — decorative "Linked" badge and status dot removed, long account names truncate instead of running under the check mark, and the wallet amount only shows once it's actually loaded.

## [0.17.4] — 2026-07-11

### Fixes
- **Freshly-opened positions no longer flash "out of sync".** A position opened after the engine's last exchange snapshot can't be in that snapshot — its absence now reads "unverified" until the next snapshot lands, while proven value mismatches on matched rows still flag. Also fixed the negative "AGE −1200" artifact and the "0s old" mislabel on the exchange-picture age (trading apps stamp rows in their own timezone while the database clock runs UTC — ages are now normalized).

## [0.17.3] — 2026-07-11

### Fixes
- **Positions page no longer cries wolf — "12 out of sync" was a false alarm.** The DB↔exchange comparator fetched live exchange state from the web server, whose IP is deliberately outside the egress allowlist — the exchange rejected every call and the resulting empty picture rendered as full-page drift. The comparator now reads the trading engine's own exchange snapshots (written continuously by the whitelisted fleet) and says what it knows: the ribbon shows how old the exchange picture is, "No exchange picture yet" replaces fake alarms when snapshots don't exist, stop-loss orders (on an exchange endpoint the engine doesn't snapshot yet) read "unverified" instead of drifting, and mid-flight positions no longer count as out-of-sync. The web box now makes zero exchange calls from this page. 3 new tests pin the behavior.

## [0.17.2] — 2026-07-11

### Fixes
- **Connectivity check now actually starts.** The card's first live run hit "Server Error": the engine-side permission gate on the shared API route expects the engine's own user type and rejected the panel's logged-in users outright. The card now starts the workflow through a panel-owned endpoint with the same owner-only scoping as the dashboard feed (existence not leaked to other users, rate-limited), and polls the account panel's existing status route. The engine-side gate gets the proper fix in the next core release.

## [0.17.1] — 2026-07-11

### Fixes
- **Connectivity card hardening**: a saved check that no longer exists is forgotten instead of being polled every 3 seconds forever; "Checked Xs ago" only appears for checks run in this session (a rehydrated old result reads "Last result"); the server roster is cached for a minute so the 10-second payload poll stops re-reading invariant fleet data.

## [0.17.0] — 2026-07-11

### Added
- **Server connectivity card on the trader dashboard is live.** It answers "can every Kraite server operate my exchange account right now?" — idle state lists the API-calling fleet; "Run check" fires the engine's real connectivity workflow, testing the account's saved key from every server's own IP. Each row resolves to CONNECTED or BLOCKED (a forgotten IP whitelist shows red, naming the exact IP to add, with a fix hint). The last result is remembered per account and re-hydrated on the next visit; re-checks are one click. No new engine machinery — the card drives the same owner-guarded workflow used at registration.

## [0.16.11] — 2026-07-11

### Improvements
- **Trader dashboard Sync button became the passive "Auto-sync · 10s" indicator** — the page already refreshed itself every 10 seconds; the icon spins while a refresh is in flight, same pattern as the rest of the panel.

## [0.16.10] — 2026-07-11

### Fixes
- **Trader dashboard review pass — three bugs fixed.**
  - Activity-feed close rows showed the wrong profit on averaged-down (WAP'd) positions: they recomputed PnL from the very first fill price instead of the blended entry, and ignored fees — so the feed could claim a profit on a close the KPI strip counted as a loss (and badge it "High profit"). Close rows now show the exchange's own stored net figure, the exact number the KPI totals sum; the price recompute survives only as a fallback for closes that predate the stored figure.
  - The 10-second refresh could die silently forever if a single response hung — same 8-second cap the positions and infra pages got, plus a quiet retry on the next tick.
  - A degenerate fallback could serialize a whole related record into a tile's symbol label; it now falls back to the exchange symbol's trading pair.

## [0.16.9] — 2026-07-11

### Improvements
- **Infra page redesigned around one tile per server.** The three separate cards (egress allowlist / control plane / node reachability) merged into a single fleet grid — each box gets a tile with hostname + role, reachability status, its IP with an Allowlisted mark and copy button (API-calling hosts only), CPU / memory / disk bars, supervisor service dots with hover detail, uptime and last heartbeat age. "Copy egress IPs" sits on the card head for the exchange-side allowlist. The two platform-wide signals that aren't per-server — dispatcher pulse (both fleets) and slow queries — moved to a slim strip above the grid.

## [0.16.8] — 2026-07-11

### Fixes
- **Infra page second pass — four bugs found and fixed ahead of testing.**
  - The page was starting its refresh loop twice, stacking duplicate 15-second polls (and the extra timer survived leaving the page). One loop now.
  - A single stalled response could freeze the refresh loop permanently, leaving stale vitals with no visible sign — every request is now capped at 8 seconds, same guard as the positions page.
  - "Step dispatcher: tick Xs ago" was computed in the browser's clock against a server timestamp written in another timezone — off by the timezone gap (an hour in summer). The age now comes computed from the database's own clock.
  - The dispatcher pulse only watched the calculation fleet — the trading fleet could stall while the panel stayed green. Running now means BOTH fleets ticked in the last 2 minutes.
- **Control-plane feed hardened**: each section (host vitals / dispatcher pulse / slow queries) degrades to an honest empty value on failure instead of blanking the whole panel; the slow-query feed no longer ships raw SQL text to the browser it never displayed.

## [0.16.7] — 2026-07-11

### Improvements
- **Auto-sync badge back to its quiet form** — static "Auto-sync · 10s" with the spinning icon during refresh; the last-sync timestamp experiment is gone. The 8-second request cap that keeps the loop alive stays.

## [0.16.6] — 2026-07-11

### Fixes
- **Auto-sync can no longer silently die.** Every 10-second refresh (account ribbon + whatever's expanded) now caps each request at 8 seconds — before, a single stalled response wedged the refresh loop permanently and the page froze on old numbers with no sign anything was wrong. The Auto-sync badge now shows the wall-clock time of the last completed refresh, so it's always visible that the numbers on screen are fresh even when the values themselves barely move between ticks. Sync scope unchanged: collapsed accounts refresh only the ribbon; expanded accounts also refresh their tiles.

## [0.16.5] — 2026-07-11

### Fixes
- **Margin ratio no longer reads 0.00%.** The exchange balance snapshot carries no maintenance-margin figure (Binance's balance endpoint simply doesn't have one), so the ratio always computed to zero. The page now estimates maintenance margin itself from each open position — mark notional × the symbol's stored bracket rate minus the bracket deduction, the exact formula the exchange uses — and divides by the snapshot's margin balance. Exchange-agnostic (works off stored bracket tables, no per-venue API quirk) and refreshes with the mark price on every sync; shows an honest — when it genuinely can't be computed instead of a misleading 0.00%.

### Improvements
- **Mark price pill removed from the ladder** — the moving label crowded the drawing; the dot alone marks the price, and the exact mark value lives in the stats strip below (still refreshing every sync).

## [0.16.4] — 2026-07-11

### Improvements
- **Live TP on the ladder.** The left anchor is the original first TP and never moves (verified against the engine: recorded once at placement, the WAP recalculation never rewrites it). Once a rung fills and the engine recalculates the average entry, the real TP order moves deeper — the ladder now draws that live TP as a second green marker sliding right, with the anchor relabelled TP1 the moment they split. Until the first fill the two coincide and only the anchor shows.

## [0.16.3] — 2026-07-11

### Improvements
- **Position tiles readable at a glance.** The ladder drawing moved to fixed geometry: bigger, brighter price labels (pending rungs no longer fade into the background), the live price dot now sits dead-centre on the track, and the corridor is inset so the last rung's label never clips at the tile edge. The mark price itself is now on the tile — as a pill above the price marker and as the first entry in the stats strip — refreshing with every 10-second sync. Token name and PnL in the tile header grew a size.

## [0.16.2] — 2026-07-11

### Improvements
- **Expanded positions are a mini dashboard — one big tile per position with the ladder drawing.** The row grid gave way to tiles: token + side chip + PnL up top, then the corridor visual — TP anchor on the left, every live rung as a tick at its true proportional price position (filled rungs highlighted, L1–L4 labelled with prices), and the live price marker sitting exactly at the alpha-path percent (pulsing when the position is yellow/red). Stats strip below carries rungs filled, alpha path and next-rung distance. Two-up on desktop, single column on phone.

## [0.16.1] — 2026-07-11

### Fixes
- **Fleet positions rows now speak the engine's numbers.** Rows were computed from the position's reference opening price, which is not the actual fill — PnL read near-zero on fresh positions while the account header (exchange truth) disagreed. Rows now call the engine's own position methods: PnL = cost-weighted average entry across filled orders vs live mark (identical to the trader surfaces, truncated to symbol precision), alpha path via the engine getter with a live-mark fallback when the candle-based price source is stale (production's web host holds no fresh candles — the engine read a dead 0 there).
- **Alpha limit column added** — distance price still has to travel to fill the NEXT pending rung, as % of mark; 0 when the rung is already reached.
- **Column labels** on the expanded position rows (Token / Rungs filled / Alpha path / Alpha limit / PnL).

## [0.16.0] — 2026-07-11

### Added
- **Positions page** (`/system/positions`): dedicated live-positions console — dedicated controller replacing the dashboard-embedded view, per-position drill-down with order ladder, TP/SL state and drift verdicts. Feature test suite included (`SystemPositionsTest`).

## [0.15.2] — 2026-07-09

### Improvements
- **Auto-sync everywhere it polls.** Engine and Fleet overview both refresh every 10 seconds; the manual Sync buttons became a passive "Auto-sync · 10s" indicator whose icon spins while a refresh is in flight (with a short grace so fast responses still visibly register). The fleet card's redundant Sync button is gone.
- **Tradeable tokens tile replaces the step-dispatcher gauge on the overview.** A mini table — one row per exchange, Long (green) / Short (red) columns — delegating to the live trader's exact tradeable definition. No headline number; the split per venue is the signal.
- **Engine throughput tile is per fleet.** Calculation and Trading each show steps genuinely processing (running leaf steps, orchestrators excluded) against their pending backlog, with a fill bar per fleet. Replaces the orders/min sparkline.
- **KPI tiles keep one shared baseline.** Labels are single-line with ellipsis; the direction chip that wrapped and pushed a tile's value off the row's baseline was redesigned away.

## [0.15.1] — 2026-07-09

### Features
- **Engine failures: "Mark all as resolved" master button.** First tap arms it (red, auto-disarms in 3 s), second tap sweeps every unresolved failure across live + archive tables of both fleets — no time window, so the failures gauge clears with the list.
- **Engine fleet tabs: "Only processing" switcher.** Filters each fleet pivot to leaf classes with steps actually Running — parents and waiting work excluded, same definition as the Total Processing tile. Class counter follows the filter.

### Fixes
- **Error toasts state the real reason.** Framework failures that carry only a `message` (503 maintenance window, 419 expired session, 429 throttle) now surface it — a deploy's maintenance window had 503'd four backtesting Reject clicks and the toast said only "Could not save decision".
- **Resolving failures busts the server caches** so resolved rows can't flicker back on the next poll.

## [0.15.0] — 2026-07-09

### Features
- **Engine page is real — step-dispatcher performance in five seconds.** The placeholder gave way to a live console with a 10-second auto-sync (only the active tab polls):
  - **Health strip**: *Total processing* (leaf steps genuinely running right now — orchestrator steps and anything waiting excluded), *Chew rate* (steps/min with a **peak steps/min over the last hour** chip, so a quiet minute next to a strong peak reads "idle", not "sick"), *Backlog* (pending count with a growing/draining trend chip over a 10-minute inflow-vs-outflow window — accumulation is the alarm, not size), *Failures* (non-recovered only: workflow-rescued cases excluded everywhere).
  - **Calculation / Trading tabs**: per-fleet pivot of step class × all ten states × totals, parent block classes badged, table scrolls inside its own lane on the phone.
  - **Failures tab**: last 2 weeks across live + archive tables of both fleets, one row per step class with an occurrence count, newest first. *Troubleshoot with AI* analyses the newest occurrence and persists the verdict on it (reopen any time via the Verdict popup); *Resolve* clears every occurrence of the class in one action — from the row or the popup. Backed by step-dispatcher v1.16.1's triage columns.
- **AI connection `step-triage`** (model swappable via `STEP_TRIAGE_MODEL`; defaults to the dated Haiku id — the undated Sonnet alias bounces off the provider chain).

## [0.14.4] — 2026-07-09

### Fixes
- **The phone drawer actually animates now.** v0.14.3's transition directives sat on an element without its own show-toggle, so the drawer popped in and out instantly. The panel now slides in on the app's signature easing curve (300ms in, a snappier 210ms out), the backdrop fades with a subtle blur, and the menu rows cascade in with a 22ms stagger. Detail fixes along the way: the overlay no longer swallows or leaks taps (backdrop-tap close works, taps pass through when closed), the panel casts a proper edge shadow, background content can't rubber-band while the drawer is open, and the drawer bottom respects the iPhone home-indicator safe area.

## [0.14.3] — 2026-07-09

### Improvements
- **Phone navigation is a slide-in drawer.** The fixed bottom bar couldn't hold ten labeled items between 421–640px — labels collided into an unusable strip. On phone widths the vertical rail now hides entirely and a hamburger in the top-bar opens a left drawer: logo header, one 48px labeled row per destination, active-page highlight, dimmed backdrop. Closes on backdrop tap, Escape, or any navigation. Desktop rail unchanged. Verified by touch at 540px and 390px (open, navigate, close — zero errors).

## [0.14.2] — 2026-07-09

### Improvements
- **Phone-first backtesting.** Verified end-to-end on a real 390px phone viewport with a live ETH run: token picker, filters, timeframe buttons, config editing, run progress, results (grade, KPI tiles, stop-loss coverage tiers, verdict breakdown, rung distribution) and the Approve/Reject buttons all render and operate cleanly by touch, with zero horizontal overflow and zero JS errors.
- **Form fields no longer trigger iOS auto-zoom.** On phone widths every real form field (inputs, selects, textareas) renders at 16px with a 44px minimum height — Safari zooms the whole page when a focused field is under 16px, and 44px is the Apple tap-target floor. Icon buttons and hidden checkbox proxies are untouched.
- **Footer shows the real deployed version.** The badge now reads the release version from the changelog instead of a design-mock build string; the placeholder footer links that pointed nowhere are gone until those pages exist.

## [0.14.1] — 2026-07-09

### Fixes
- **Backtesting: deciding really scrolls back to the top now.** The v0.13.2 scroll was a smooth animation fired as the next token's auto-run collapsed the results grid — Safari aborts smooth scrolling when the page reshapes mid-flight, stranding the viewport mid-page — and it only fired when auto-advance actually moved, so deciding the last token never scrolled at all. Now it's an instant jump on every approve/reject.

### Improvements
- **Fleet-overview KPI tiles align side by side.** All five tiles share one skeleton: header on top, the big value centered in a fixed band matching the dispatcher gauge's height, sub-label pinned to the tile's bottom edge. The throughput sparkline moved beside its number so it no longer pushes that tile's label out of line.
- **Backtesting config panel no longer shows the sim's 20× leverage.** Live leverage is the account's per-direction setting (capped by exchange brackets and the regime ramp) — the fixed envelope figure only ever applied to the simulation's sizing and read as if it would be pushed live on approval.

## [0.14.0] — 2026-07-07

### Features
- **Fleet-overview dashboard is fully live — every tile now shows real platform data.** The mock numbers from the design phase are gone; the whole page re-polls one payload every 15 seconds and both Sync buttons force a refresh. Tile by tile: **Active traders** (real active-user count + 24h signups, delta badge only when someone actually signed up), **Step dispatcher** (success rate of terminal step outcomes across both fleets + real steps/min), **Capital under mgmt** (latest exchange-reported balance per actively managed account with a true 24h delta), **Engine throughput** (orders/min over the last hour with a real trend line), **Open positions** (live platform-wide count).
- **Market regime panel reflects the real BSCS engine.** Score 0–100 across the four real bands (Calm / Elevated / Fragile / Critical — the mock's five-band scale never existed in the engine), with a posture line stating what the band does to trading. The **Override regime button now works**: reason + hours engage the real trading-engine override; while active the card shows the audit reason with a one-click Clear.
- **Deploy panel shows real rollout drift.** Every server's heartbeat now reports the core build it runs (core v1.62.0); the card shows the newest version, how many nodes run it, which lag behind, and an in-sync / drift chip. Boxes that haven't reported a version yet are stated honestly instead of invented.
- **Exchange connectivity is the real venue table.** The four actual exchanges (Binance / Bybit / KuCoin / BitGet — fake OKX/Deribit/Kraken/Coinbase removed) with true average latency, error rate, connected-account count and a last-12-calls latency trend from the API request log. Status derives from the trailing-hour error rate; venues with no traffic read "Idle" instead of pretending health.
- **Incidents & events feed is real.** Latest occurrence of each platform notification (health alerts, rate limits, blacklistings, recoveries…), severity-coloured with true ages.
- **Revenue today panel is real.** MRR from paying unpaused subscribers, today's confirmed top-ups from the wallet ledger, and the float held across all user wallets.

### Improvements
- **The overview degrades gracefully.** Any section whose data source is missing (dev database without core tables, mid-deploy outage) renders its placeholder instead of taking the page down, and the failure is logged rather than swallowed.

### Dependencies
- Composer lock refresh (guzzle transitive bumps) carried from the previous session.

## [0.13.2] — 2026-07-07

### Improvements
- **Deciding scrolls back to the top.** The Approve/Reject buttons sit at the bottom of the page; when the decision auto-advances to the next token and fires its backtest, the page now smooth-scrolls back up so the new token's header, coverage and run progress are immediately in view.

## [0.13.1] — 2026-07-06

### Fixes
- **Grade and proposal can no longer contradict each other.** A large sample diluted absolute failures in the score — 16 stop-loss hits over ~1400 sims still graded "B — mostly fine to run" while the proposal correctly said "Recommend reject". The grade is now capped by the same decision rule the proposal uses (core v1.61.0): more than 10 stops → at best D, 5–10 → at best C. The help explainer states the cap.

### Changed
- **Per-simulation rows grid replaced by the Stop-loss coverage tiers.** The raw failure rows (long unrounded decimals, one row per stopped sim) read poorly and duplicated what the counts already said. The card keeps its filter strip — Status chips carry the Stopped/Inconclusive counts, Side chips now narrow the coverage panels to Long/Short/Both — and the body shows the per-direction SL-coverage tiers that used to live in their own card further down. Failure rows still feed the AI adviser unchanged.
- **Regime stability panel removed.** The per-time-bucket pass-rate bars read as a wall of green and didn't inform the decision. The regime buckets still feed the AI adviser and the grade's worst-bucket deduction — only the visual panel is gone.

### Improvements
- **Deciding a token auto-advances to the next one and runs its backtest.** After Approve/Reject the selector moves to the next token in the dropdown's visual order — wrapping around to the items before the current one when the decided token was last in the list — skips the decided token's other quote variants (they were concluded together), resets the config to the new token's stored values, and immediately fires its backtest. The review loop becomes decide → results appear → decide, fully hands-free; when nothing is left to review, the decided token stays selected.
- **A decision concludes the token everywhere, and the UI now shows it.** Approving/rejecting was already fanned out server-side to every listing of the same token — other quotes (USDT → USDC/USD1) and other exchanges, linked by the canonical symbol identity (so exchange-specific token names are covered). The dropdown status dots and filter counts of those sibling rows now update instantly on decision instead of waiting for a page reload.
- **Token switching locks while a run is in flight.** The token selector and the universe filter checkboxes disable during fetch / verify / run / adjustment-search — a mid-run swap would show one token's results under another token's header, and the adjustment search would push the wrong token's config on apply.
- **Filter flips keep the selection valid.** Unchecking/checking the universe filters (Top 100 / Only approved / Not concluded) while the chosen token falls outside the new filter now auto-selects the first token of the filtered list (or clears the selection when nothing matches) instead of leaving a hidden token silently selected. Typing in the search box deliberately never re-selects.
- **Every adjustment candidate is one click from a re-test.** The smart-adjustment result list used to mark each tried bump with a passive ✓/✗ icon and only the system's winning pick got an apply button. Each row now leads with a small play button that applies that candidate's config and immediately re-runs the backtest — so when no bump clears the bar (or the operator disagrees with the tie-break), any of the six candidates can be tested live without retyping the config.

## [0.13.0] — 2026-07-06

### Features
- **Backtesting decision proposal now requires evidence before recommending approve.** A run that resolved zero simulations (no candle history, every sim rejected at sizing, or all inconclusive) also has zero stop-loss hits — and previously earned the same green "Recommend approve" banner as a genuinely clean run. Reachable in practice since v0.12.0 opened the token dropdown to all 568 Binance symbols, most with thin or no fetched history (e.g. the coverage fetch timing out on a busy fleet). Now: zero resolved sims → "Cannot recommend — nothing simulated" and the **Approve button locks** (nothing was tested, so there is no tested config to push live); below the simulator's own statistical threshold (180 start candles — the same line its grade penalty ramps on) → "Thin sample — review manually". The stop-count rules (<5 approve · 5–10 adjust · >10 reject) apply only above the threshold.
- **Sizing-skipped simulations are now visible.** Sims whose order sizing was rejected (min-notional / lot-step on awkwardly-priced tokens) used to vanish from every counter. They now appear as a striped "Skipped (sizing)" segment in the Verdict breakdown (only when present), and the proposal reason calls them out on zero-resolved runs. Backed by `totals.skipped` from core v1.60.0.
- **Stop-loss coverage panel.** The simulator always computed, per direction, the SL width that would have absorbed 25/50/75/100% of the run's stopped trades — the UI never showed it. New "Stop-loss coverage" card (with help explainer) renders the tiers per direction when stops exist: small deltas = near-misses a slightly wider SL rescues cheaply; a huge "All" tier = trend events no SL saves, attack severity instead.

### Fixes
- **Dead rows-table filter chips removed.** The per-simulation table lists failures only by design, so the "TP market · 0" and "Rebound · 0" chips could never match a row — replaced with an "Inconclusive" chip matching what the table actually holds. Also fixed the latent status-key mismatch (`tp_hit_from_market_only`) so winning rows would render green, not as grey "Skipped", if they ever appear.
- **AI insights prompt no longer misstates two facts.** The per-failure "candles to deepest rung" field — the collapse-speed signal the structural-vs-noise classification keys on — was wired to always send nothing; it is now computed from the row timestamps. And the prompt claimed the run ignored the last 15 days when it actually ignores 0 (now read from the simulator's own metadata).
- **Sample-size stat is honest about units.** The thinness warning now keys on start candles (the unit the simulator's 180 threshold actually uses) while the headline stays in sims, with the start count shown alongside; the help explainer spells out sims ≈ 2× starts.

### Improvements
- **Commands console degrades instead of erroring.** If the bridge to the trading server is missing or broken, the page renders an empty console and command details return a readable error — previously the whole page failed. (The production bridge itself was provisioned on 2026-07-05 — deploy-notes Entry 94.)
- **Cookie auto-login lands by role.** Arriving already-authenticated (session/remember cookie) now follows the same rule as a fresh login: sysadmins land on the admin console, everyone else on the trader dashboard. Previously the generic framework rule sent everyone to the trader dashboard.

## [0.12.0] — 2026-06-27

### Changed
- **Backtesting token dropdown now lists every Binance symbol, not just manually-enabled ones.** Previously the selector filtered on `is_manually_enabled = true`, so in production only the handful of already-enabled tokens (5) appeared — a chicken-and-egg gate, since approving a backtest is what sets that flag. The operator could only backtest tokens that were already live. The dropdown now offers all Binance `exchange_symbols` (568 in prod), so any token can be vetted before being promoted. Approval/enable state still drives the per-row badges; it no longer gates visibility.

## [0.11.1] — 2026-06-26

### Changed
- Dependency maintenance: bumped `guzzlehttp/guzzle` 7.12.1 → 7.12.3 and `guzzlehttp/psr7` to the matching 2.12.3 (transitive patch updates). No application code changes — release cut to ship the backtesting UI (v0.10.0–v0.11.0) to production on a clean, reproducible lockfile.

## [0.11.0] — 2026-06-23

### Changed
- **Backtesting coverage gate softened from hard block to advisory warning.** The v0.9.0 risk gate refused to grade or approve on stale / gappy candle data (the server returned **422 `data_not_ready`** and the UI blocked the run outright). It now grades on whatever candles are present and surfaces a warning instead. The run still fires the dispatcher-orchestrated ensure-coverage block (detect period → Vision → REST / fill-gaps → TAAPI → verify) to top the data up as far as the sources allow — but it no longer blocks when the result is still imperfect. `run` attaches `coverage` + `coverage_warning` to its response; the UI shows an amber "graded on imperfect data" toast and a warning line rather than refusing to produce a grade. **Approve / reject is now the operator's final call** — the server no longer blocks the live-config push on stale data. The operator reads the grade with eyes open instead of seeing nothing, and weighs the warning before pushing a config live.

## [0.10.0] — 2026-06-23

### Features
- **Backtesting decision is now stop-loss-driven.** The Decision proposal no longer keys off the abstract grade — it counts **stop-loss hits** across the full backtest: under 5 → *Recommend approve*, 5–10 → *Adjust configuration*, over 10 → *Recommend reject*. The reason line reads the count directly ("N stop-loss hits · approve under 5"). Pass rate stays visible but demoted — for a martingale ladder the failure rate is the signal, not the win rate.
- **Smart configuration adjustment.** When a token lands in the 5–10 "adjust" band, a one-click **Find a safe adjustment** search tries small single-lever bumps — wider limit gap or wider stop-loss at +0.5% / +1.0% / +1.5% — re-runs the full backtest for each, and reports which (if any) gets the token under 5 stops, preferring a wider gap over a wider SL on a tie. The winning config shows a green **Apply config and backtest again** button that applies it and immediately re-runs in one click; if no small bump works it says so and leans reject. New `suggest-adjustment` endpoint.
- **Per-label help affordance.** Every results metric (coverage cells, grade / overall / risk, the six scorecards, the analytic panels, config echo) carries a subtle `[?]` icon — hover shows a one-line tip, click opens a detailed explainer modal. Built as a single reusable `x-ui.help-dot` component plus an optional `tip` prop on `x-ui.card-head` (other card heads unaffected).

### Improvements
- **Results-panel legibility** — the dim micro-labels on the coverage strip, scorecards, regime band, rows-table headers, and config echo moved off the near-invisible `--fg-faint` onto the legible `--fg-3`, matching the earlier Config-card pass.
- **Decision flow is one click** — Approve and Reject now act immediately (no confirmation modal); the Reject button is solid red to match Approve's solid green.
- **Per-simulation rows moved up** — the rows table now sits directly under the scorecards (above config echo / regime stability / verdict breakdown), so the actual stopped trades are the first thing read after the headline numbers.

### Changed
- **Config card trimmed** — the Window (Since / Candles back), Limit-hit, and Max-rows inputs were removed; the server defaults already cover them (all-history window, all sims counted, a 500-row cap on the detail table). "Max rows" never capped the simulation sample anyway, so the field was misleading.

## [0.9.0] — 2026-06-22

### Features
- **Backtesting risk gate — fresh + complete data, guaranteed.** A token can no longer be graded or approved on stale or gappy candle data (which could push a config outside its risk boundaries). **Run** now fires a dispatcher-orchestrated coverage block on the worker fleet — detect period → Binance Vision → REST + gap-fill → TAAPI → verify — polls it to completion ("Fetching history… N/4"), and grades **only** when the data holds the last closed candle with no gaps. Two hard server gates back it up independent of the UI: `run` returns **422 `data_not_ready`** (no grade) and **Approve is refused (422)** on stale/incomplete data. The coverage card now tells the truth — green **"Complete & live"** vs amber **"Stale — Nh behind"** — instead of a flat "complete coverage". New `ensure-coverage` + `coverage-status` endpoints; the heavy fetch lives in `kraitebot/core` (v1.56.0) step jobs run by the fleet, not in the web request.

## [0.8.8] — 2026-06-22

### Improvements
- **AI insights are compact and scannable** — the backtest-insights prompt now enforces a telegraphic, capped format (Diagnosis as four one-line labelled bullets; three Suggestions with terse Why / Impact / Trade-off), roughly halving output length while keeping the analysis. The client markdown renderer was rewritten to match: numbered suggestions render as bold title rows, the labelled lines render as an aligned label column, `---` becomes a divider instead of literal text, leading indent is normalised, and the body is width-capped for a readable line length.
- **Backtesting label legibility** — the Config card section labels, field labels, and hint copy moved off the near-invisible `--fg-faint` onto `--fg-3`; the coverage strip and actions/decision hints were lightened the same way.
- **Structural micro-typography** — introduced shared `.ui-eyebrow` / `.ui-field-label` / `.ui-hint` component classes (raw CSS reading the design tokens) and refactored the backtesting readable labels/hints onto them, so a legibility or scale change is a single edit instead of a per-span sweep.

## [0.8.7] — 2026-06-21

### Improvements
- **Backtesting Config card is now collapsible** — it starts collapsed; clicking the header slides it open/closed (animated `grid-template-rows` 0fr↔1fr, 0.28s) with a 180° chevron. Collapsed, the header shows a "ladder parameters" / "select a token" hint. The shared `x-ui.card-head` gained an optional `collapsible` flag (renders as a button + forwards attributes); all other card heads are unchanged.
- **Backtesting Config labels are readable again** — the section labels (Window / Strategy / Fixed envelope), field labels, and hint copy moved off the near-invisible `--fg-faint` to `--fg-3` for clear legibility on the dark surface. Scoped to the Config card.

## [0.8.6] — 2026-06-21

### Features
- **Backtesting decision proposal** — after a run, the Decision card now shows the system's own recommendation derived from the simulator grade: A/B → *Recommend approve*, D/F → *Recommend reject*, C → *Borderline — review manually*. The banner cites the reasoning (grade · score/100 · pass %) and the recommended Approve/Reject button gets a coloured ring. Advisory only — the operator still clicks.

### Improvements
- **Backtesting default timeframe is now 1d** — the timeframe selector defaults to the daily candle instead of the first option, matching how most token configs are reviewed. Button order is unchanged.

## [0.8.5] — 2026-06-21

### Features
- **Backtesting token-universe filters** — three checkboxes below the token selector narrow the dropdown live: **Top 100** (CMC rank ≤ 100), **Only approved** (approved configs), and **Not concluded** (neither approved nor rejected). Each shows a live total count. The two status filters combine as a union, AND'd with Top 100. Filtering is purely client-side over the already-loaded symbol set.

## [0.8.4] — 2026-06-21

### Features
- **Backtesting console wired live** — the Fetch / Verify / Run / Approve / AI buttons now actually drive the five `BacktrackingController` endpoints. Implemented the missing `window.hubUiFetch` AJAX bridge (CSRF via the `XSRF-TOKEN` cookie so it survives `wire:navigate`, JSON encode/accept, non-throwing `{ ok, data, status }`); previously every button threw `ReferenceError: hubUiFetch is not defined` and silently did nothing.
- **Run auto-fetches missing candles** — Run now audits coverage first and, when the stored window doesn't reach the requested Since / Candles-back, fetches history before simulating (Auditing → Fetching → Simulating). No more silent empty backtest on a fresh token.
- **Real token logos in the selector** — the trigger and every dropdown row render the token's CoinMarketCap logo (`symbols.image_url`), with the monogram avatar kept as the fallback for tokens with no image or a broken URL.

### Improvements
- **Thin-history alert** — when auto-fetch still can't cover the requested window (the token genuinely lacks that much history), a persistent amber banner + toast tell the operator exactly what was short (no candles / fewer than requested / history doesn't reach Since); the run proceeds on what exists.

### Fixes
- **Sysadmin/trader rail active item no longer goes blank on hover** — the global `a:hover { color: var(--accent) }` content-link rule outranked the active rail link's `text-fg-on-accent`, turning its icon + label accent-on-accent (invisible) the moment the pointer rested on it after a click. Added `hover:text-fg-on-accent` so the active item keeps its on-accent colour through hover.

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
