<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use BrunoCFalcao\AiBridge\Chat\ChatManager;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Support\Backtest\BacktestSimulator;
use Kraite\Core\Support\Backtest\BinanceRestCandleFetcher;
use Kraite\Core\Support\Backtest\BinanceVisionCandleFetcher;
use Kraite\Core\Support\Backtest\CandleCoverageVerifier;
use Kraite\Core\Support\Backtest\TaapiCandlesFetcher;
use Throwable;

/**
 * BacktrackingController
 *
 * Admin-only console for per-token ladder backtesting. Three actions
 * backing three buttons in the UI:
 *
 *   POST /system/backtracking/fetch-candles   — pulls historical OHLCV from
 *       Binance Vision (bulk) + TAAPI (recency top-up) into the `candles`
 *       table for the selected symbol + timeframe. Safe to re-run — both
 *       fetchers are idempotent.
 *
 *   POST /system/backtracking/verify-coverage — audits what's present in
 *       the candles table for a symbol + timeframe: earliest / latest /
 *       hole count / contiguity %. Feeds the UI's coverage pill.
 *
 *   POST /system/backtracking/run             — runs BacktestSimulator
 *       with the caller's overrides. Returns per-direction outcome
 *       aggregates + a paginated rows list.
 *
 * Results are ephemeral by design (no persistence this release). If we
 * want the per-customer selection UI later, we'll add a
 * token_backtest_reports table and persist the totals on each run.
 */
final class BacktrackingController extends Controller
{
    public function index(): View
    {
        $symbols = $this->enabledSymbolGroups();
        $defaults = $this->pullDefaultsFromMainAccount();

        return view('system.backtracking', [
            'symbols' => $symbols,
            'defaults' => $defaults,
            'timeframes' => $this->availableTimeframes(),
        ]);
    }

    public function fetchCandles(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'exchange_symbol_id' => ['required', 'integer', 'exists:exchange_symbols,id'],
            'timeframe' => ['required', 'string', 'in:'.implode(',', array_keys(CandleCoverageVerifier::INTERVAL_SECONDS))],
            'max_months' => ['sometimes', 'integer', 'min:1', 'max:48'],
            'candles_back' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'since' => ['nullable', 'date'],
            'taapi_topup' => ['sometimes', 'boolean'],
        ]);

        try {
            $symbol = ExchangeSymbol::findOrFail((int) $validated['exchange_symbol_id']);
            $timeframe = (string) $validated['timeframe'];
            $useTopup = (bool) ($validated['taapi_topup'] ?? true);
            $maxMonths = $this->resolveMaxMonths(
                $timeframe,
                $validated['since'] ?? null,
                isset($validated['candles_back']) ? (int) $validated['candles_back'] : null,
                $validated['max_months'] ?? null,
            );

            $isBinance = mb_strtolower($symbol->apiSystem->canonical ?? '') === 'binance';

            // Tier 1: Vision — bulk completed months. Only Binance.
            $visionReport = null;
            $visionError = null;
            if ($isBinance) {
                try {
                    $visionReport = (new BinanceVisionCandleFetcher)->fetch($symbol, $timeframe, $maxMonths);
                } catch (Throwable $e) {
                    $visionError = $e->getMessage();
                }
            }

            // Tier 2: Binance REST — fills the current-month tail Vision
            // doesn't have yet, and closes any internal gaps in the window.
            // Forward-resume covers "since latest DB row to now"; gap-scan
            // covers "holes older than the latest row" (common when a
            // timeframe was enabled after partial history was already
            // present, e.g. 1d added to kraite.timeframes retroactively).
            $restReport = null;
            $restError = null;
            if ($isBinance) {
                try {
                    $rest = new BinanceRestCandleFetcher;
                    $forward = $rest->fetch($symbol, $timeframe);

                    // Gap-scan lookback: prefer the user's `since` / `candles_back`
                    // window; otherwise default to 6 months (the fetcher's own
                    // default when null is passed).
                    $gapLookbackTs = $this->resolveWindowSince(
                        $timeframe,
                        $validated['since'] ?? null,
                        isset($validated['candles_back']) ? (int) $validated['candles_back'] : null,
                    )?->getTimestamp();

                    $gaps = $rest->fillGaps($symbol, $timeframe, $gapLookbackTs);
                    $restReport = array_merge($forward, ['gaps' => $gaps]);
                } catch (Throwable $e) {
                    $restError = $e->getMessage();
                }
            }

            // Tier 3: TAAPI — last resort. Non-Binance symbols have no Vision
            // or REST path, so TAAPI is their only source. For Binance this
            // mostly no-ops (REST already filled the tail) but covers the
            // rare case where Binance REST fails.
            $taapiReport = null;
            $taapiError = null;
            if ($useTopup) {
                try {
                    $taapiReport = (new TaapiCandlesFetcher)->fetch($symbol, $timeframe, 200, 0);
                } catch (Throwable $e) {
                    $taapiError = $e->getMessage();
                }
            }

            // Post-fetch coverage audit — remaining holes go back to the UI
            // so the operator sees what actually landed vs what was asked.
            $coverage = null;
            try {
                $coverage = (new CandleCoverageVerifier)->verify($symbol, $timeframe);
            } catch (Throwable $e) {
                // Non-fatal — coverage is diagnostic, not load-bearing.
            }

            $ok = $visionReport !== null || $restReport !== null || $taapiReport !== null;

            return response()->json([
                'ok' => $ok,
                'window_months' => $maxMonths,
                'vision' => $visionReport,
                'rest' => $restReport,
                'taapi' => $taapiReport,
                'vision_error' => $visionError,
                'rest_error' => $restError,
                'taapi_error' => $taapiError,
                'coverage' => $coverage,
                'has_holes' => $coverage !== null && (($coverage['holes_count'] ?? 0) > 0),
                'message' => $this->formatFetchSummary($maxMonths, $visionReport, $restReport, $taapiReport, $visionError, $restError, $taapiError, $coverage),
            ], $ok ? 200 : 422);
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function verifyCoverage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'exchange_symbol_id' => ['required', 'integer', 'exists:exchange_symbols,id'],
            'timeframe' => ['required', 'string', 'in:'.implode(',', array_keys(CandleCoverageVerifier::INTERVAL_SECONDS))],
        ]);

        try {
            $symbol = ExchangeSymbol::findOrFail((int) $validated['exchange_symbol_id']);
            $report = (new CandleCoverageVerifier)->verify($symbol, (string) $validated['timeframe']);

            return response()->json([
                'ok' => true,
                'coverage' => $report,
                'pair' => $symbol->parsed_trading_pair,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function run(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'exchange_symbol_id' => ['required', 'integer', 'exists:exchange_symbols,id'],
            'timeframe' => ['required', 'string', 'in:'.implode(',', array_keys(CandleCoverageVerifier::INTERVAL_SECONDS))],
            'margin' => ['required', 'numeric', 'gt:0'],
            'leverage' => ['required', 'integer', 'min:1', 'max:125'],
            'total_limit_orders' => ['required', 'integer', 'min:1', 'max:12'],
            'multipliers' => ['nullable', 'string'],
            'tp_percent' => ['required', 'numeric', 'gt:0'],
            'gap_long_percent' => ['nullable', 'numeric', 'gt:0'],
            'gap_short_percent' => ['nullable', 'numeric', 'gt:0'],
            'sl_percent' => ['required', 'numeric', 'gt:0'],
            'skip_stop_loss' => ['sometimes', 'boolean'],
            'days_to_ignore' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'limit_hit' => ['nullable', 'integer', 'min:1'],
            'candle' => ['nullable', 'string'],
            'candles_back' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'since' => ['nullable', 'date'],
            'max_rows' => ['sometimes', 'integer', 'min:10', 'max:1000'],
        ]);

        try {
            $symbol = ExchangeSymbol::findOrFail((int) $validated['exchange_symbol_id']);
            $timeframe = (string) $validated['timeframe'];

            $windowSince = $this->resolveWindowSince(
                $timeframe,
                $validated['since'] ?? null,
                isset($validated['candles_back']) ? (int) $validated['candles_back'] : null,
            );

            $simulator = new BacktestSimulator;
            $result = $simulator->simulate(
                symbol: $symbol,
                timeframe: $timeframe,
                margin: (string) $validated['margin'],
                leverage: (int) $validated['leverage'],
                totalLimitOrders: (int) $validated['total_limit_orders'],
                multipliers: $this->parseMultipliers($validated['multipliers'] ?? null),
                tpPercent: (string) $validated['tp_percent'],
                gapLongPercent: isset($validated['gap_long_percent']) ? (string) $validated['gap_long_percent'] : null,
                gapShortPercent: isset($validated['gap_short_percent']) ? (string) $validated['gap_short_percent'] : null,
                slPercent: (string) $validated['sl_percent'],
                skipStopLoss: (bool) ($validated['skip_stop_loss'] ?? false),
                daysToIgnore: (int) ($validated['days_to_ignore'] ?? 0),
                limitHit: isset($validated['limit_hit']) ? (int) $validated['limit_hit'] : null,
                specificCandle: isset($validated['candle']) && $validated['candle'] !== ''
                    ? Carbon::parse($validated['candle'], config('app.timezone', 'UTC'))
                    : null,
                since: $windowSince,
            );

            // Row cap — shipping all 10k rows in one JSON response is a
            // UI hazard. Truncate on the API, the UI can always ask for
            // unfiltered via the CLI for serious analysis.
            $maxRows = (int) ($validated['max_rows'] ?? 500);
            $truncated = false;
            if (count($result['rows']) > $maxRows) {
                $result['rows'] = array_slice($result['rows'], 0, $maxRows);
                $truncated = true;
            }

            return response()->json([
                'ok' => true,
                'pair' => $symbol->parsed_trading_pair,
                'result' => $result,
                'rows_truncated' => $truncated,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Interpret a backtest result via LLM. Accepts the result + config
     * payload the UI just received from POST /run, builds a prompt, calls
     * the `backtest-insights` AI connection, returns the raw text answer.
     * No config changes are applied — advisory only.
     */
    public function aiInsights(Request $request, ChatManager $chat): JsonResponse
    {
        // Prism + streaming response parsing can push past FPM's 128M default
        // when the prompt includes full regime buckets + failure rows. Lift
        // for this single action only — the request is operator-triggered and
        // naturally rate-limited by the throttle middleware.
        ini_set('memory_limit', '512M');

        $validated = $request->validate([
            'exchange_symbol_id' => ['required', 'integer', 'exists:exchange_symbols,id'],
            'timeframe' => ['required', 'string', 'in:'.implode(',', array_keys(CandleCoverageVerifier::INTERVAL_SECONDS))],
            'totals' => ['required', 'array'],
            'regimes' => ['nullable', 'array'],
            'meta' => ['required', 'array'],
            'config' => ['required', 'array'],
            'rows' => ['nullable', 'array'],
        ]);

        try {
            $symbol = ExchangeSymbol::with('apiSystem', 'symbol')->findOrFail((int) $validated['exchange_symbol_id']);

            $response = $chat->send(
                messages: $this->buildInsightMessages($symbol, $validated),
                connection: 'backtest-insights',
            );

            return response()->json([
                'ok' => true,
                'insights' => $response,
                'model' => config('ai-bridge.resolver.connections.backtest-insights'),
            ]);
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[AI Insights] '.$e->getMessage(), [
                'exception' => $e::class,
                'trace' => mb_substr($e->getTraceAsString(), 0, 2000),
            ]);

            return response()->json([
                'ok' => false,
                'error' => $e->getMessage().' ('.$e::class.')',
            ], 500);
        }
    }

    /**
     * Build the prompt messages for the AI Insights call.
     *
     * System message explains: Kraite's martingale ladder strategy, the
     * portfolio shape (12 concurrent positions sharing margin), the
     * liquidation risk model at deep rungs, and the priority order the
     * operator actually cares about (NOT "maximise pass rate"). Anti-
     * patterns are called out explicitly so the model doesn't propose
     * risk-inflating "improvements".
     *
     * User message packs: token + timeframe, portfolio context from the
     * live Account, current persisted symbol config, the config that was
     * just tested, all backtest totals including the rung distribution +
     * cumulative-qty-at-rung-N, and the regime buckets.
     *
     * @param  array<string, mixed>  $validated
     * @return array<int, array{role: string, content: string}>
     */
    private function buildInsightMessages(ExchangeSymbol $symbol, array $validated): array
    {
        $totals = $validated['totals'];
        $meta = $validated['meta'];
        $config = $validated['config'];
        $regimes = $validated['regimes'] ?? [];
        $rows = $validated['rows'] ?? [];

        $account = Account::find(1);
        $n = (int) ($config['total_limit_orders'] ?? $symbol->total_limit_orders ?? 4);
        $multipliersTested = $this->resolveEffectiveMultipliers(
            $config['multipliers'] ?? null,
            $symbol->limit_quantity_multipliers ?? null,
            $n,
        );
        $cumulativeQtyAtRungN = $this->cumulativeQtyMultipleAtRung($multipliersTested, $n);
        $rungDist = $totals['rung_distribution'] ?? [];
        $rungNCount = $rungDist[(string) $n] ?? $rungDist[$n] ?? 0;
        $totalSims = array_sum(is_array($rungDist) ? $rungDist : []);
        $rungNPct = $totalSims > 0 ? round(($rungNCount / $totalSims) * 100, 2) : 0;

        $system = <<<'SYS'
You are a senior quantitative analyst tuning the Kraite martingale ladder strategy for a professional trader running a live crypto futures portfolio. You MUST reason at the portfolio level, not just the single-token level, and you MUST respect the priority order below.

## STRATEGY — how Kraite actually trades

- Every position opens with a SMALL market leg (1/2^(N+1) of allocated margin) plus N pending LIMIT orders below (LONG) or above (SHORT) the entry, spaced by `gap_long_percent` / `gap_short_percent`.
- **Default multipliers are `[2, 2, ..., 2]` of length N** — aggressive doubling is the core martingale design. This default is load-bearing: the doubling is what shifts WAP enough toward the deepest fill that a small retrace can close the trade. At N=4 cumulative = 1+2+4+8+16 = 31×; at N=3 = 1+2+4+8 = 15×; at N=2 = 1+2+4 = 7×.
- TP is re-calculated after each rung fill (WAP-based), so a full rung-N fill only needs a small retrace to close in profit. But that's also where liquidation risk lives: leverage × cumulative qty near the rung-N anchor.
- SL only activates AFTER rung N is touched — before that, the operator is riding out normal noise. "stopped_out" verdicts = trades that went further against after going max-deep.
- "non-reboundable" verdicts = trades that never recovered by end-of-data (the 15-day trailing buffer already removes walker-exhaustion false positives).

## PORTFOLIO — the real risk envelope

- The operator runs up to **(total_positions_long + total_positions_short) simultaneous positions** on the SAME margin pool. Shown below in USER MESSAGE.
- Every position claims `margin_percentage_long/short` of free margin. When all slots are open, total committed margin approaches (L+S) × margin_percentage.
- Liquidation isn't per-position — it's at the account level via margin ratio. A single token pushing to rung 4 with high MAE can drag the whole account close to liquidation if other positions are also underwater.
- The 20× / 15× leverage shown is the multiplier on notional, not on risk. Real risk at rung N is `leverage × cumulative_qty_multiple × adverse_move%`.

## CORRECT MATH — cite these exactly, do not improvise

- **Liquidation threshold (isolated margin)**: price move required to liquidate ≈ `1 / leverage` as a fraction of entry. So 20× liquidates at ~5% adverse move, 15× at ~6.7%. **Do NOT divide MAE% by leverage** — that number is meaningless.
- **Max MAE is price-trajectory-only**: it measures how far price moved vs the entry price. It is INDEPENDENT of the ladder shape. Changing `total_limit_orders` does NOT reduce Max MAE. It reduces cumulative position size, which reduces liquidation **severity** (how much margin the adverse move consumes), not the adverse move itself.
- **Realised loss when SL fires at rung N**: approximately `sl_percent × cumulative_qty_multiple × leverage × margin_per_position`. Tightening SL with a deep ladder (high cumulative qty) is NOT a free lunch — a 1% SL at 31× cumulative = a 3× bigger realised loss than a 1% SL at 10× cumulative.
- **Tighter gap → MORE rung reaches, not fewer**, because rungs sit closer to entry. Tighter gap only reduces rung-N reach when the TP is also being hit earlier (shorter hold time closes trades before they bleed). Never claim tighter gap alone reduces rung-N reach without citing the coupled TP move.

## CLASSIFY FIRST — frequency vs severity

Before proposing any suggestion, classify what's actually going wrong by inspecting the FAILURE ROWS provided in the user message:

- **Structural (pure-trend) stops** — rows cluster in time (2-3 stops within a few days), all in the same direction, each reaching rung N within 1-5 candles of its start. These are trend events (20-40% directional moves without reversal). **No ladder config eliminates these.** Don't try. Attack SEVERITY via the tunable levers: reduce `total_limit_orders` (drops cumulative qty by halving at each step: N=4 → 31×, N=3 → 15×, N=2 → 7×), reduce leverage, or reduce `margin_percentage_long/short`.
- **Avoidable (noise) stops** — rows are spread out, mixed directions, reach rung N slowly (many candles between touches). These are tight-config false positives. Attack FREQUENCY — widen TP, widen gap, reduce N.
- **Mixed** — if both patterns present, pick the dominant one first.

If Max MAE is high (>50% on 20× leverage means real liquidation exposure) AND stops cluster in time with same direction → structural. Say so in the diagnosis and focus on severity, not frequency.

## PRIORITIES — the operator's actual utility function, in order

1. **Minimise realised-loss-per-stop** (severity) — this is primary for structural stops. Cumulative_qty_multiple × leverage × sl_percent × margin = actual $ lost per trigger.
2. **Minimise Max MAE** — liquidation-risk proxy. A config with 99% pass rate but Max MAE 150% is worse than 97% pass rate with Max MAE 30%.
3. **Minimise rung-N reach rate** — only actionable for avoidable stops. For structural stops, accept the rate and focus on #1.
4. **Minimise stopped_out count** — matters after severity is controlled.
5. **Maximise throughput** — more trades × small TP% = compound edge. A tight TP+narrow gap finishing in 1-2 candles beats a wide TP locking capital for 10 for the same pass rate.
6. **Minimise average rung depth** — capital efficiency; tie-breaker.

## SINGLE-VARIABLE RULE — MUST respect

**At least ONE of your three suggestions must change exactly ONE parameter**, with everything else held equal to the CONFIG JUST TESTED. Label it `(single-variable test)`. This gives the operator a clean attributable signal. The other two can move multiple knobs.

## ANTI-PATTERNS — do NOT propose any of these

- **Multiplier changes for severity reasons only** — do NOT propose `[1,1,1,1]`, `[1.5,1.5,1.5,1.5]`, or any flatter shape just to cap realised-loss-per-stop. That reasoning killed the rebound mechanics in real tests (`[1,1,1,1]` tanked the grade because former rebounds converted to stops). Multipliers are ONLY a valid lever when the proposal ties the new shape to a WAP-based argument: quote the weighted-average-price shift this creates at each rung, compute the new TP target relative to entry, and show it IMPROVES reboundability — not just severity. If you can't make the WAP math work, leave multipliers alone at `[2] × N`.
- "Widen TP and widen gap together" — pushes trades to last longer AND concentrates the ladder near the anchor, guaranteeing deeper adverse fills before resolution. Cardinal sin.
- "Reduce SL" without looking at rung-N reach distribution — SL only fires after rung N; if rung N reach is already <1%, SL width barely matters, and shrinking it invites premature exits in the rare case.
- "Widen gap" on tokens where rung-N reach rate is already >2% — wider gap with ladder still being reached means each rung is further away from entry = Max MAE explodes.
- Suggesting changes that improve total pass rate but push rung-N reach upward.
- Three variants of the same tweak. Each suggestion must target a distinct lever.

## OUTPUT — exact format

```
## Diagnosis
3-5 sentences. MUST include:
 - **Stop classification**: "Structural (pure-trend)", "Avoidable (noise)", or "Mixed". Cite the failure-row pattern that drove your classification (direction clustering, time clustering, candles-to-rung-N).
 - Max MAE and what it implies at the live leverage (use the correct `1/leverage` liquidation math).
 - Rung-N reach rate and what portion stopped_out.
 - Throughput (avg_candles_to_profit) relative to the timeframe.

## Suggestions
Exactly 3 numbered suggestions. **At least one must change exactly ONE parameter** — label it `(single-variable test)`. The other two may move multiple knobs. Each:

1. **Short name** `(single-variable test)` — `param=value[, param2=value2]`
   - Why: one sentence tying it to specific metrics above. Quote the numbers.
   - Expected impact: quantified (e.g. "rung-4 reach drops ~1.9% → ~0.8%", "realised-loss-per-stop drops 31× → 13.2× cumulative → ~57% smaller").
   - Trade-off: one sentence on what this costs (throughput, avg rung depth, scoring blind spots, etc).

## Tunable levers + bounds (MUST respect)
- `tp_percent`: 0.10 – 2.00
- `sl_percent`: 0.50 – 15.00
- `gap_long_percent` / `gap_short_percent`: 0.50 – 25.00
- `total_limit_orders` (N): 1 – 8
- `leverage` (account-level, overridable per-backtest): 1 – 50
- `margin_percentage_long` / `margin_percentage_short` (account-level): 0.50 – 20.00
- `limit_quantity_multipliers`: default `[2] × N`. Only propose changes if accompanied by explicit WAP math showing the new shape IMPROVES the per-rung TP-to-entry distance (i.e. rebounds get easier, not harder).

If the config is already on the Pareto frontier (grade A, Max MAE low, rung-N reach ~0), say so plainly and suggest ONE small exploratory tweak per suggestion slot, explicitly labelled as exploratory. Do not invent problems that aren't in the numbers.

Be direct. No hedging, no "you might consider", no unsourced claims. Quote the number from the payload for every claim.
```
SYS;

        $user = sprintf(
            "TOKEN: %s on %s\nTIMEFRAME: %s\nWINDOW: start=%s, end_cutoff_days=%d\n\n".
            "ACCOUNT-LEVEL PORTFOLIO (live settings):\n".
            "  total_positions_long=%s  total_positions_short=%s  (all share one margin pool)\n".
            "  position_leverage_long=%sx  position_leverage_short=%sx\n".
            "  margin_percentage_long=%s%%  margin_percentage_short=%s%%\n".
            "  stop_market_wait_minutes=%s  (mitigation: SL cooldown before re-entry)\n".
            "  profit_percentage=%s%%  (default TP)  stop_market_initial_percentage=%s%%  (default SL)\n\n".
            "SYMBOL-PERSISTED CONFIG (%s on %s in exchange_symbols):\n".
            "  percentage_gap_long=%s  percentage_gap_short=%s  total_limit_orders=%s  limit_quantity_multipliers=%s\n\n".
            "CONFIG JUST TESTED:\n%s\n\n".
            "DERIVED:\n".
            "  cumulative_qty_multiple_at_rung_%d = %s (position size vs market leg when ladder fully fills)\n".
            "  rung_%d_reach_rate = %s%% (%d of %d resolved sims)\n\n".
            "BACKTEST TOTALS:\n%s\n\n".
            "REGIME BUCKETS (pass_rate per time chunk):\n%s\n\n".
            "FAILURE ROWS (only stopped_out + non-reboundable sims are emitted — scan for time-clustering + direction patterns to classify structural vs avoidable):\n%s\n",
            $symbol->parsed_trading_pair,
            $symbol->apiSystem->canonical ?? 'unknown',
            $meta['timeframe'] ?? 'unknown',
            $meta['window_since'] ?? 'full history',
            (int) ($meta['end_cutoff_days'] ?? 15),
            $account?->total_positions_long ?? 'n/a',
            $account?->total_positions_short ?? 'n/a',
            $account?->position_leverage_long ?? 'n/a',
            $account?->position_leverage_short ?? 'n/a',
            $account?->margin_percentage_long ?? 'n/a',
            $account?->margin_percentage_short ?? 'n/a',
            $account?->stop_market_wait_minutes ?? 'n/a',
            $account?->profit_percentage ?? 'n/a',
            $account?->stop_market_initial_percentage ?? 'n/a',
            $symbol->parsed_trading_pair,
            $symbol->apiSystem->canonical ?? 'unknown',
            $symbol->percentage_gap_long ?? 'n/a',
            $symbol->percentage_gap_short ?? 'n/a',
            $symbol->total_limit_orders ?? 'n/a',
            is_array($symbol->limit_quantity_multipliers)
                ? implode(',', $symbol->limit_quantity_multipliers)
                : ($symbol->limit_quantity_multipliers ?? 'n/a'),
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            $n,
            $cumulativeQtyAtRungN,
            $n,
            $rungNPct,
            $rungNCount,
            max(1, $totalSims),
            json_encode($totals, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            json_encode($regimes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            empty($rows)
                ? '(no failure rows — every sim resolved as TP or rebound)'
                : json_encode($this->compactFailureRows($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    /**
     * Resolve the multipliers actually in play for the sim, falling back
     * in a bug-safe order: form-typed CSV → symbol's stored multipliers
     * → the Kraite default `[2] × N`. Empty arrays are treated as "not
     * provided" (PHP's `??` only fires on null, so `json_decode('[]')`
     * would otherwise short-circuit the chain and feed the LLM a bogus
     * "flat ladder").
     *
     * @param  string|null  $formCsv  Raw `multipliers` field from the request.
     * @param  mixed  $symbolStored  Raw column value from `exchange_symbols.limit_quantity_multipliers` (array, JSON string, or null).
     * @return array<int, string|float|int>
     */
    private function resolveEffectiveMultipliers(?string $formCsv, mixed $symbolStored, int $n): array
    {
        $fromForm = $this->parseMultipliers($formCsv);
        if (is_array($fromForm) && $fromForm !== []) {
            return $fromForm;
        }

        $fromSymbol = $symbolStored;
        if (is_string($fromSymbol) && $fromSymbol !== '') {
            $decoded = json_decode($fromSymbol, true);
            $fromSymbol = is_array($decoded) ? $decoded : null;
        }
        if (is_array($fromSymbol) && $fromSymbol !== []) {
            return array_values($fromSymbol);
        }

        return array_fill(0, max(1, $n), '2');
    }

    /**
     * Trim failure rows to the fields the model actually needs for
     * pattern analysis, and cap at 50 to keep the prompt lean. Drops
     * the verbose human-readable `message` field since the structured
     * fields carry the same information in a cheaper form.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function compactFailureRows(array $rows): array
    {
        return array_slice(array_map(static fn ($r) => [
            'direction' => $r['direction'] ?? null,
            'start' => $r['start_candle'] ?? null,
            'entry' => $r['entry_ref_price'] ?? null,
            'last_rung' => $r['last_rung'] ?? null,
            'last_touch' => $r['last_touch_candle'] ?? null,
            'tp' => $r['tp_price'] ?? null,
            'candles_to_rung_n' => isset($r['start_candle'], $r['last_touch_candle'])
                ? null
                : null,
            'status' => $r['status'] ?? null,
            'mae_pct' => $r['mae_pct'] ?? null,
        ], $rows), 0, 50);
    }

    /**
     * Position-size multiple at rung K given the market leg as 1 and the
     * per-rung quantity multipliers. Mirrors the live ladder compounding:
     * a multiplier of 2 at every rung yields 1 + 2 + 4 + 8 + 16 = 31 at N=4.
     *
     * @param  array<int, string|float|int>  $multipliers
     */
    private function cumulativeQtyMultipleAtRung(array $multipliers, int $k): string
    {
        if ($k < 1) {
            return '1';
        }

        $sum = 1.0;
        $currentQty = 1.0;
        foreach (array_values($multipliers) as $i => $mult) {
            if ($i >= $k) {
                break;
            }
            $currentQty *= (float) $mult;
            $sum += $currentQty;
        }

        return number_format($sum, 2, '.', '');
    }

    /**
     * Lookup enabled ExchangeSymbols grouped by exchange canonical, with
     * token + quote and the stored defaults the UI will pre-fill into
     * the form when the user changes selection.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function enabledSymbolGroups(): array
    {
        $rows = DB::table('exchange_symbols as es')
            ->join('symbols as s', 's.id', '=', 'es.symbol_id')
            ->join('api_systems as ap', 'ap.id', '=', 'es.api_system_id')
            ->where('ap.canonical', 'binance')
            ->orderByRaw('UPPER(s.token) ASC')
            ->get([
                'es.id',
                's.token',
                'es.quote',
                'ap.canonical as exchange',
                'es.percentage_gap_long',
                'es.percentage_gap_short',
                'es.total_limit_orders',
                'es.limit_quantity_multipliers',
            ]);

        // Bucket by quote currency. USDT and USDC come first (operator's primary
        // pairs), then any remaining quotes alphabetically. Binance-only — other
        // exchanges' historical candle coverage isn't supported by the
        // BinanceVision/Rest fetchers.
        $buckets = [];
        foreach ($rows as $row) {
            $q = mb_strtoupper((string) $row->quote);
            $buckets[$q][] = [
                'id' => (int) $row->id,
                'label' => $row->token,
                'token' => $row->token,
                'quote' => $row->quote,
                'exchange' => $row->exchange,
                'percentage_gap_long' => $row->percentage_gap_long,
                'percentage_gap_short' => $row->percentage_gap_short,
                'total_limit_orders' => $row->total_limit_orders,
                'limit_quantity_multipliers' => $row->limit_quantity_multipliers,
            ];
        }

        $ordered = [];
        foreach (['USDT', 'USDC'] as $primary) {
            if (! empty($buckets[$primary])) {
                $ordered[$primary] = $buckets[$primary];
                unset($buckets[$primary]);
            }
        }
        ksort($buckets);

        return $ordered + $buckets;
    }

    /**
     * Pull form defaults from the main admin account (id=1). Gives the
     * UI sensible pre-fills so the user doesn't need to remember TP /
     * SL / leverage values.
     *
     * @return array<string, mixed>
     */
    private function pullDefaultsFromMainAccount(): array
    {
        $account = Account::find(1);

        return [
            'margin' => '100',
            'leverage' => (int) ($account?->position_leverage_long ?? 20),
            'tp_percent' => (string) ($account?->profit_percentage ?? '0.36'),
            'sl_percent' => (string) ($account?->stop_market_initial_percentage ?? '2.50'),
            'days_to_ignore' => 0,
            'total_limit_orders' => 4,
        ];
    }

    /**
     * Resolve the effective months-back window for Vision.
     *
     * Precedence: since > candles_back > explicit max_months > default 24.
     * Clamped to [1, 48] to match BinanceVisionCandleFetcher's hard cap.
     */
    private function resolveMaxMonths(string $timeframe, ?string $since, ?int $candlesBack, int|string|null $explicit): int
    {
        if ($since !== null && $since !== '') {
            $days = max(1, (int) ceil(Carbon::parse($since)->diffInDays(Carbon::now(), absolute: true)));

            return max(1, min(48, (int) ceil($days / 30)));
        }

        if ($candlesBack !== null) {
            $hours = $candlesBack * (CandleCoverageVerifier::INTERVAL_SECONDS[$timeframe] / 3600);

            return max(1, min(48, (int) ceil($hours / (30 * 24))));
        }

        if ($explicit !== null) {
            return max(1, min(48, (int) $explicit));
        }

        return 24;
    }

    /**
     * Resolve the effective window start as a Carbon. Same inputs drive
     * both fetch depth (months-back) and simulator window (candles-since),
     * keeping UI semantics consistent: `since` wins over `candles_back`;
     * empty returns null = walk everything.
     */
    private function resolveWindowSince(string $timeframe, ?string $since, ?int $candlesBack): ?Carbon
    {
        if ($since !== null && $since !== '') {
            return Carbon::parse($since);
        }

        if ($candlesBack !== null) {
            $seconds = $candlesBack * CandleCoverageVerifier::INTERVAL_SECONDS[$timeframe];

            return Carbon::now()->subSeconds($seconds);
        }

        return null;
    }

    /**
     * Union of every timeframe any enabled exchange supports. Single source of
     * truth lives in `kraite.timeframes` (global singleton — used to live
     * per-exchange on `api_systems.timeframes` until the 2026-04-24 move).
     * Sorted short-to-long for the UI select; falls back to every known
     * interval key when the singleton hasn't been populated yet.
     *
     * @return array<int, string>
     */
    private function availableTimeframes(): array
    {
        $raw = collect(\Kraite\Core\Models\Kraite::timeframes())
            ->filter(fn ($tf) => isset(CandleCoverageVerifier::INTERVAL_SECONDS[$tf]))
            ->unique()
            ->sortBy(fn ($tf) => CandleCoverageVerifier::INTERVAL_SECONDS[$tf])
            ->values()
            ->all();

        return empty($raw) ? array_keys(CandleCoverageVerifier::INTERVAL_SECONDS) : $raw;
    }

    private function parseMultipliers(?string $input): ?array
    {
        if ($input === null || mb_trim($input) === '') {
            return null;
        }

        $parts = array_filter(array_map('trim', explode(',', $input)), fn ($v) => $v !== '');

        foreach ($parts as $value) {
            if (! is_numeric($value) || (float) $value <= 0) {
                throw new \InvalidArgumentException("Invalid multipliers entry '{$value}'. Must be positive numerics comma-separated.");
            }
        }

        return array_values(array_map('strval', $parts));
    }

    /**
     * @param  array<string, mixed>|null  $vision
     * @param  array<string, mixed>|null  $rest
     * @param  array<string, mixed>|null  $taapi
     * @param  array<string, mixed>|null  $coverage
     */
    private function formatFetchSummary(
        int $windowMonths,
        ?array $vision,
        ?array $rest,
        ?array $taapi,
        ?string $visionError,
        ?string $restError,
        ?string $taapiError,
        ?array $coverage,
    ): string {
        $parts = [sprintf('Window: %d months', $windowMonths)];

        if ($vision !== null) {
            $parts[] = sprintf(
                'Vision: %d new + %d already-covered months → %d candles%s',
                $vision['months_downloaded'] ?? 0,
                $vision['months_already_covered'] ?? 0,
                $vision['candles_upserted'] ?? 0,
                ! empty($vision['errors']) ? ' ('.count($vision['errors']).' errors)' : ''
            );
        } elseif ($visionError !== null) {
            $parts[] = 'Vision skipped: '.$visionError;
        }

        if ($rest !== null) {
            $forwardInserted = $rest['inserted'] ?? 0;
            $gaps = $rest['gaps'] ?? null;
            $gapsFilled = $gaps['gaps_filled'] ?? 0;
            $gapsInserted = $gaps['inserted'] ?? 0;
            $totalInserted = $forwardInserted + $gapsInserted;

            if (($rest['skipped'] ?? false) === true && $gapsFilled === 0 && ($gaps['gaps_found'] ?? 0) === 0) {
                $parts[] = 'Binance REST: already current';
            } else {
                $parts[] = sprintf(
                    'Binance REST: %d candles (%d forward, %d gap-fill across %d gap(s))',
                    $totalInserted,
                    $forwardInserted,
                    $gapsInserted,
                    $gaps['gaps_found'] ?? 0,
                );
            }

            if (! empty($gaps['skipped'] ?? [])) {
                $parts[] = sprintf('⚠ %d gap(s) still unfilled after Binance REST', count($gaps['skipped']));
            }
        } elseif ($restError !== null) {
            $parts[] = 'Binance REST skipped: '.$restError;
        }

        if ($taapi !== null) {
            if (($taapi['skipped'] ?? false) === true) {
                $parts[] = sprintf('TAAPI: already current (latest %s)', $taapi['latest'] ?? 'n/a');
            } else {
                $parts[] = sprintf(
                    'TAAPI: %d candles (latest %s)',
                    $taapi['inserted'] ?? 0,
                    $taapi['latest'] ?? 'n/a'
                );
            }
        } elseif ($taapiError !== null) {
            $parts[] = 'TAAPI skipped: '.$taapiError;
        }

        if ($coverage !== null) {
            $holes = (int) ($coverage['holes_count'] ?? 0);
            if ($holes > 0) {
                $parts[] = sprintf(
                    '⚠ %d hole(s) remain — %s%% contiguity, earliest %s, latest %s',
                    $holes,
                    $coverage['contiguity_percent'] ?? 'n/a',
                    $coverage['earliest'] ?? 'n/a',
                    $coverage['latest'] ?? 'n/a'
                );
            } else {
                $parts[] = sprintf(
                    'Coverage: complete (%d candles, earliest %s, latest %s)',
                    $coverage['total_present'] ?? 0,
                    $coverage['earliest'] ?? 'n/a',
                    $coverage['latest'] ?? 'n/a'
                );
            }
        }

        return empty($parts) ? 'No fetch performed.' : implode(' · ', $parts);
    }
}
