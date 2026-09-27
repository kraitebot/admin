<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Services\BacktestStopLossRecency;
use BrunoCFalcao\AiBridge\Chat\ChatManager;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kraite\Core\Enums\BacktestTimeframe;
use Kraite\Core\Jobs\Backtest\EnsureBacktestCandleCoverageStep;
use Kraite\Core\Jobs\Backtest\VerifyCoverageResultStep;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Support\Backtest\BacktestSimulator;
use Kraite\Core\Support\Backtest\BinanceRestCandleFetcher;
use Kraite\Core\Support\Backtest\BinanceVisionCandleFetcher;
use Kraite\Core\Support\Backtest\CandleCoverageVerifier;
use Kraite\Core\Support\Backtest\CoverageGate;
use Kraite\Core\Support\Backtest\TaapiCandlesFetcher;
use StepDispatcher\Models\Step;
use Throwable;

/**
 * BacktrackingController
 *
 * Admin-only console for per-token ladder backtesting. Endpoint map:
 *
 *   POST fetch-candles       — manual three-tier pull (Vision → REST →
 *       TAAPI) into the `candles` table. Idempotent.
 *   POST verify-coverage     — coverage audit (earliest / latest / holes /
 *       contiguity) feeding the UI's coverage pill.
 *   POST ensure-coverage     — dispatcher-orchestrated coverage block on
 *       the worker fleet; GET coverage-status polls it to completion.
 *   POST run                 — BacktestSimulator with operator overrides;
 *       grades on whatever candles are present, attaches a coverage
 *       warning when the data is imperfect (advisory, not blocking).
 *   POST suggest-adjustment  — smart single-lever search for the 5–10
 *       stop-loss "adjust" band.
 *   POST toggle-approval     — the real-money gate: approve persists the
 *       tested config onto exchange_symbols and enables the token.
 *   POST ai-insights         — LLM read of a finished run. Advisory.
 *
 * Results are ephemeral by design (no persistence this release). If we
 * want the per-customer selection UI later, we'll add a
 * token_backtest_reports table and persist the totals on each run.
 *
 * @phpstan-type VisionReport array{
 *   months_downloaded?: int,
 *   months_already_covered?: int,
 *   candles_upserted?: int,
 *   earliest_candle?: string|null,
 *   latest_candle?: string|null,
 *   errors?: array<int, string>
 * }
 * @phpstan-type RestGaps array{
 *   gaps_found?: int,
 *   gaps_filled?: int,
 *   inserted?: int,
 *   skipped?: array<int, array{from: string, to: string, missing: int, reason: string}>
 * }
 * @phpstan-type RestReport array{
 *   inserted?: int,
 *   earliest?: string|null,
 *   latest?: string|null,
 *   pages?: int,
 *   skipped?: bool,
 *   reason?: string,
 *   gaps?: RestGaps
 * }
 * @phpstan-type TaapiReport array{
 *   inserted?: int,
 *   earliest?: string|null,
 *   latest?: string|null,
 *   source_url?: string,
 *   skipped?: bool,
 *   reason?: string,
 *   requested?: int
 * }
 * @phpstan-type CandleReports array{
 *   vision: VisionReport|null,
 *   rest: RestReport|null,
 *   taapi: TaapiReport|null,
 *   vision_error: string|null,
 *   rest_error: string|null,
 *   taapi_error: string|null
 * }
 * @phpstan-type FetchCandlePayload array{
 *   ok: bool,
 *   window_months: int,
 *   vision: VisionReport|null,
 *   rest: RestReport|null,
 *   taapi: TaapiReport|null,
 *   vision_error: string|null,
 *   rest_error: string|null,
 *   taapi_error: string|null,
 *   coverage: CoverageReport|null,
 *   has_holes: bool,
 *   message: string
 * }
 * @phpstan-type CoverageReport array{
 *   timeframe?: string,
 *   total_present?: int,
 *   earliest?: string|null,
 *   latest?: string|null,
 *   span_hours?: float|null,
 *   expected_if_contiguous?: int,
 *   holes_count?: int,
 *   holes_sample?: array<int, array{from: string, to: string, missing: int}>,
 *   contiguity_percent?: float,
 *   is_fresh?: bool,
 *   staleness_hours?: float|null
 * }
 * @phpstan-type ValidatedInput array<string, scalar|null>
 * @phpstan-type StructuredValue scalar|array<array-key, scalar|array<array-key, scalar|array<array-key, scalar|array<array-key, scalar|null>|null>|null>|null>
 * @phpstan-type BacktestResult array{
 *   rows: array<int, array<string, StructuredValue>>,
 *   totals: array<string, StructuredValue>,
 *   ...
 * }
 * @phpstan-type SimulationArguments array{
 *   symbol: ExchangeSymbol,
 *   timeframe: string,
 *   margin: string,
 *   leverage: int,
 *   totalLimitOrders: int,
 *   multipliers: array<int, string>|null,
 *   tpPercent: string,
 *   gapLongPercent: string|null,
 *   gapShortPercent: string|null,
 *   slPercent: string,
 *   skipStopLoss: bool,
 *   daysToIgnore: int,
 *   limitHit: int|null,
 *   specificCandle: Carbon|null,
 *   since: Carbon|null
 * }
 * @phpstan-type AdjustmentCandidate array{
 *   lever: string,
 *   delta: float,
 *   gap_long?: float,
 *   gap_short?: float,
 *   sl?: float,
 *   stops: int,
 *   acceptable: bool
 * }
 * @phpstan-type SymbolRow array<string, scalar|null>
 * @phpstan-type InsightInput array{
 *   exchange_symbol_id: int,
 *   timeframe: string,
 *   totals: array<string, StructuredValue>,
 *   meta: array<string, scalar|null>,
 *   config: array<string, StructuredValue>,
 *   regimes: array<int|string, StructuredValue>|null,
 *   rows: array<int, array<string, StructuredValue>>|null
 * }
 * @phpstan-type InsightMetrics array{
 *   cumulative: string,
 *   rung_count: int|float,
 *   total_sims: int|float,
 *   rung_pct: int|float
 * }
 * @phpstan-type InsightContext array{
 *   symbol: ExchangeSymbol,
 *   account: Account|null,
 *   meta: array<string, scalar|null>,
 *   config: array<string, StructuredValue>,
 *   regimes: array<int|string, StructuredValue>,
 *   totals: array<string, StructuredValue>,
 *   n: int,
 *   cumulative: string,
 *   rung_count: int|float,
 *   total_sims: int|float,
 *   rung_pct: int|float,
 *   failure_rows: string|false
 * }
 */
final class BacktrackingController extends Controller
{
    /**
     * A token is "approvable" when it hits its stop-loss fewer than this many
     * times across the full backtest. Mirrors the UI's proposal rule
     * (< 5 approve · 5–10 adjust · > 10 reject); used here as the target the
     * smart-adjustment search tries to get a token back under.
     */
    private const APPROVE_STOPS_MAX = 5;

    private const INSIGHT_SYSTEM_PROMPT = <<<'SYS'
You are a senior quantitative analyst tuning the Kraite martingale ladder strategy for a professional trader running a live crypto futures portfolio. You MUST reason at the portfolio level, not just the single-token level, and you MUST respect the priority order below.

## STRATEGY — how Kraite actually trades

- Every position opens with a SMALL market leg (1/2^(N+1) of allocated margin) plus N pending LIMIT orders below (LONG) or above (SHORT) the entry, spaced by `gap_long_percent` / `gap_short_percent`.
- **Default multipliers are `[2, 2, ..., 2]` of length N** — aggressive doubling is the core martingale design. This default is load-bearing: the doubling is what shifts WAP enough toward the deepest fill that a small retrace can close the trade. At N=4 cumulative = 1+2+4+8+16 = 31×; at N=3 = 1+2+4+8 = 15×; at N=2 = 1+2+4 = 7×.
- TP is re-calculated after each rung fill (WAP-based), so a full rung-N fill only needs a small retrace to close in profit. But that's also where liquidation risk lives: leverage × cumulative qty near the rung-N anchor.
- SL only activates AFTER rung N is touched — before that, the operator is riding out normal noise. "stopped_out" verdicts = trades that went further against after going max-deep.
- "inconclusive" verdicts = sims whose walker ran out of forward data before TP or SL fired (typical for very recent start candles, not actionable).

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

- **Structural (pure-trend) stops** — rows cluster in time (2-3 stops within a few days), all in the same direction, each reaching rung N within 0-5 candles of its start (0 = the entry candle's own wick swept the whole ladder — the most structural signature of all). These are trend events (20-40% directional moves without reversal). **No ladder config eliminates these.** Don't try. Attack SEVERITY via the tunable levers: reduce `total_limit_orders` (drops cumulative qty by halving at each step: N=4 → 31×, N=3 → 15×, N=2 → 7×), reduce leverage, or reduce `margin_percentage_long/short`.
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

## OUTPUT — exact format, COMPACT (this is a scan-in-5-seconds panel, not an essay)

Telegraphic. Numbers over prose. No preamble, no recap, no hedging, no parentheticals-inside-parentheticals. Markdown only. The word caps below are hard limits — under them is better. Reason as deeply as the sections above demand, but EMIT only the distilled conclusion.

```
## Diagnosis
Exactly four bullets, each ONE line, ≤ 16 words, leading with the bold label:
- **Class:** Structural / Avoidable / Mixed — the failure-row pattern that proves it.
- **Max MAE:** N% — liquidation implication at the live leverage (use 1/leverage math).
- **Rung-N reach:** N% (M stopped) — one clause.
- **Throughput:** N candles to profit — one clause vs the timeframe.

## Suggestions
Exactly 3, numbered. ≥1 changes exactly ONE parameter — tag it `(single-variable test)`. Each is exactly the title line + three labelled lines, NO blank lines or sub-paragraphs between them:
1. **Short name** `(single-variable test)` — `param=value[, param2=value2]`
   - **Why** — one clause tied to a quoted metric. ≤ 18 words.
   - **Impact** — quantified before→after, e.g. `rung-4 reach 1.9% → 0.8%`. ≤ 14 words.
   - **Trade-off** — what it costs. ≤ 14 words.
```

## Tunable levers + bounds (MUST respect)
- `tp_percent`: 0.10 – 2.00
- `sl_percent`: 0.50 – 15.00
- `gap_long_percent` / `gap_short_percent`: 0.50 – 25.00
- `total_limit_orders` (N): 1 – 8
- `leverage` (account-level, overridable per-backtest): 1 – 50
- `margin_percentage_long` / `margin_percentage_short` (account-level): 0.50 – 20.00
- `limit_quantity_multipliers`: default `[2] × N`. Only propose changes if accompanied by explicit WAP math showing the new shape IMPROVES the per-rung TP-to-entry distance (i.e. rebounds get easier, not harder).

If the config is already on the Pareto frontier (grade A, Max MAE low, rung-N reach ~0), say so in ONE line and give one small exploratory tweak per slot, tagged `(exploratory)`. Do not invent problems that aren't in the numbers. Quote a payload number for every claim.
SYS;

    private const INSIGHT_USER_PROMPT = <<<'PROMPT'
TOKEN: %s on %s
TIMEFRAME: %s
WINDOW: start=%s, trailing_days_ignored=%d

ACCOUNT-LEVEL PORTFOLIO (live settings):
  total_positions_long=%s  total_positions_short=%s  (all share one margin pool)
  position_leverage_long=%sx  position_leverage_short=%sx
  margin_percentage_long=%s%%  margin_percentage_short=%s%%
  stop_market_wait_minutes=%s  (mitigation: SL cooldown before re-entry)
  profit_percentage=%s%%  (default TP)  stop_market_initial_percentage=%s%%  (default SL)

SYMBOL-PERSISTED CONFIG (%s on %s in exchange_symbols):
  percentage_gap_long=%s  percentage_gap_short=%s  total_limit_orders=%s  limit_quantity_multipliers=%s

CONFIG JUST TESTED:
%s

DERIVED:
  cumulative_qty_multiple_at_rung_%d = %s (position size vs market leg when ladder fully fills)
  rung_%d_reach_rate = %s%% (%d of %d resolved sims)

BACKTEST TOTALS:
%s

REGIME BUCKETS (pass_rate per time chunk):
%s

FAILURE ROWS (only stopped_out sims are actionable — scan for time-clustering + direction patterns to classify structural vs avoidable):
%s
PROMPT;

    public function index(): View
    {
        $symbols = $this->enabledSymbolGroups();
        $defaults = $this->pullDefaultsFromMainAccount();

        return view('system.backtesting', [
            'symbols' => $symbols,
            'defaults' => $defaults,
            'timeframes' => $this->availableTimeframes(),
        ]);
    }

    public function fetchCandles(Request $request): JsonResponse
    {
        $validated = $this->validateFetchCandles($request);

        try {
            return $this->fetchCandlesResponse($validated);
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
            'timeframe' => ['required', 'string', 'in:'.implode(',', BacktestTimeframe::values())],
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

    /**
     * Kick off the dispatcher-orchestrated candle-coverage ensure for a token +
     * timeframe. Creates the EnsureBacktestCandleCoverageStep orchestrator and
     * returns its block_uuid so the UI can poll coverageStatus to completion.
     * Reuses an in-flight block for the same symbol+timeframe (dedupe).
     */
    public function ensureCoverage(Request $request): JsonResponse
    {
        $validated = $this->validateEnsureCoverage($request);
        $symbolId = (int) $validated['exchange_symbol_id'];
        $timeframe = (string) $validated['timeframe'];
        $existing = $this->findActiveCoverageStep($symbolId, $timeframe);

        if ($existing !== null) {
            return response()->json(['ok' => true, 'block_uuid' => $existing->block_uuid, 'reused' => true]);
        }

        $blockUuid = $this->createCoverageStep($symbolId, $timeframe, $validated);

        return response()->json(['ok' => true, 'block_uuid' => $blockUuid, 'reused' => false]);
    }

    /**
     * Poll the coverage block created by ensureCoverage. Returns block progress
     * plus the readiness verdict — from the final VerifyCoverageResultStep, or
     * the orchestrator's own response when it completed inline (already covered).
     */
    public function coverageStatus(Request $request): JsonResponse
    {
        $validated = $request->validate(['block_uuid' => ['required', 'string']]);
        $orchestrator = $this->findCoverageOrchestrator((string) $validated['block_uuid']);

        if ($orchestrator === null) {
            return response()->json(['ok' => false, 'error' => 'unknown coverage block'], 404);
        }

        $orchState = class_basename($orchestrator->state);
        $progress = $this->coverageProgress($orchestrator);
        $state = $this->coverageState($orchestrator, $orchState);

        return response()->json($this->coverageStatusPayload($state, $progress));
    }

    public function run(Request $request, BacktestStopLossRecency $stopLossRecency): JsonResponse
    {
        $validated = $this->validateRun($request);

        try {
            return $this->runBacktest($validated, $stopLossRecency);
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Smart configuration adjustment for the "adjust" band (5–10 bad endings).
     *
     * Tries small single-lever bumps — a wider limit gap, or a wider stop-loss —
     * in +0.5% / +1.0% / +1.5% steps (no larger; small nudges only), re-runs the
     * full backtest for each, and reports which (if any) brings the stop-loss-hit
     * count back under the approve threshold. The smallest successful bump is
     * surfaced as the recommended fix; a wider gap is preferred over a wider SL
     * on a tie (it reduces deep fills at the source rather than just tolerating
     * more bleed). Advisory only — nothing is persisted.
     */
    public function suggestAdjustment(Request $request): JsonResponse
    {
        $validated = $this->validateSuggestAdjustment($request);

        // Six full re-simulations; give the request room without hanging forever.
        set_time_limit(120);

        try {
            return $this->suggestAdjustmentResponse($validated);
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Approve or revoke approval for a backtested symbol. On approve, also
     * persist the gap_long / gap_short used in the form so the live trader
     * picks them up. ExchangeSymbolObserver fans both `was_backtesting_approved`
     * and the gap percentages out to every other ExchangeSymbol sharing the
     * same `symbol_id` (cross-exchange propagation).
     */
    public function toggleApproval(Request $request): JsonResponse
    {
        $validated = $this->validateToggleApproval($request);

        try {
            $symbol = ExchangeSymbol::findOrFail((int) $validated['exchange_symbol_id']);
            $approve = (bool) $validated['approve'];
            $this->applyApprovalDecision($symbol, $validated, $approve);
            $symbol->save();
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ], 500);
        }

        return response()->json($this->approvalPayload($symbol));
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
        $validated = $this->validateAiInsights($request);

        try {
            $symbol = ExchangeSymbol::with('apiSystem', 'symbol')->findOrFail((int) $validated['exchange_symbol_id']);

            return $this->sendAiInsights($symbol, $validated, $chat);
        } catch (Throwable $e) {
            return $this->aiInsightsFailure($e);
        }
    }

    /**
     * @return ValidatedInput
     */
    private function validateFetchCandles(Request $request): array
    {
        return $request->validate([
            'exchange_symbol_id' => ['required', 'integer', 'exists:exchange_symbols,id'],
            'timeframe' => ['required', 'string', 'in:'.implode(',', BacktestTimeframe::values())],
            'max_months' => ['sometimes', 'integer', 'min:1', 'max:48'],
            'candles_back' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'since' => ['nullable', 'date'],
            'taapi_topup' => ['sometimes', 'boolean'],
        ]);
    }

    /**
     * @param  ValidatedInput  $validated
     */
    private function fetchCandlesResponse(array $validated): JsonResponse
    {
        $symbol = ExchangeSymbol::findOrFail((int) $validated['exchange_symbol_id']);
        $timeframe = (string) $validated['timeframe'];
        $maxMonths = $this->resolveMaxMonths(
            $timeframe,
            $this->validatedSince($validated),
            isset($validated['candles_back']) ? (int) $validated['candles_back'] : null,
            $this->validatedExplicitMonths($validated),
        );
        $reports = $this->fetchCandleReports($symbol, $timeframe, $maxMonths, $validated);
        $coverage = $this->verifyFetchCoverage($symbol, $timeframe);
        $ok = $reports['vision'] !== null || $reports['rest'] !== null || $reports['taapi'] !== null;

        return response()->json($this->fetchCandlePayload($maxMonths, $reports, $coverage), $ok ? 200 : 422);
    }

    /**
     * @param  ValidatedInput  $validated
     * @return CandleReports
     */
    private function fetchCandleReports(
        ExchangeSymbol $symbol,
        string $timeframe,
        int $maxMonths,
        array $validated,
    ): array {
        $isBinance = mb_strtolower($symbol->apiSystem->canonical ?? '') === 'binance';
        [$vision, $visionError] = $this->fetchVisionReport($symbol, $timeframe, $maxMonths, $isBinance);
        [$rest, $restError] = $this->fetchRestReport($symbol, $timeframe, $validated, $isBinance);
        [$taapi, $taapiError] = $this->fetchTaapiReport($symbol, $timeframe, (bool) ($validated['taapi_topup'] ?? true));

        return [
            'vision' => $vision,
            'rest' => $rest,
            'taapi' => $taapi,
            'vision_error' => $visionError,
            'rest_error' => $restError,
            'taapi_error' => $taapiError,
        ];
    }

    /**
     * @return array{0: VisionReport|null, 1: string|null}
     */
    private function fetchVisionReport(ExchangeSymbol $symbol, string $timeframe, int $maxMonths, bool $isBinance): array
    {
        if (! $isBinance) {
            return [null, null];
        }

        try {
            return [(new BinanceVisionCandleFetcher)->fetch($symbol, $timeframe, $maxMonths), null];
        } catch (Throwable $e) {
            return [null, $e->getMessage()];
        }
    }

    /**
     * @param  ValidatedInput  $validated
     * @return array{0: RestReport|null, 1: string|null}
     */
    private function fetchRestReport(ExchangeSymbol $symbol, string $timeframe, array $validated, bool $isBinance): array
    {
        if (! $isBinance) {
            return [null, null];
        }

        try {
            $rest = new BinanceRestCandleFetcher;
            $forward = $rest->fetch($symbol, $timeframe);
            $gapLookbackTs = $this->resolveWindowSince(
                $timeframe,
                $this->validatedSince($validated),
                isset($validated['candles_back']) ? (int) $validated['candles_back'] : null,
            )?->getTimestamp();

            return [array_merge($forward, ['gaps' => $rest->fillGaps($symbol, $timeframe, $gapLookbackTs)]), null];
        } catch (Throwable $e) {
            return [null, $e->getMessage()];
        }
    }

    /**
     * @return array{0: TaapiReport|null, 1: string|null}
     */
    private function fetchTaapiReport(ExchangeSymbol $symbol, string $timeframe, bool $useTopup): array
    {
        if (! $useTopup) {
            return [null, null];
        }

        try {
            return [(new TaapiCandlesFetcher)->fetch($symbol, $timeframe, 200, 0), null];
        } catch (Throwable $e) {
            return [null, $e->getMessage()];
        }
    }

    /**
     * @return CoverageReport|null
     */
    private function verifyFetchCoverage(ExchangeSymbol $symbol, string $timeframe): ?array
    {
        try {
            return (new CandleCoverageVerifier)->verify($symbol, $timeframe);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  CandleReports  $reports
     * @param  CoverageReport|null  $coverage
     * @return FetchCandlePayload
     */
    private function fetchCandlePayload(int $maxMonths, array $reports, ?array $coverage): array
    {
        $ok = $reports['vision'] !== null || $reports['rest'] !== null || $reports['taapi'] !== null;
        $payload = [
            'ok' => $ok,
            'window_months' => $maxMonths,
            'vision' => $reports['vision'],
            'rest' => $reports['rest'],
            'taapi' => $reports['taapi'],
            'vision_error' => $reports['vision_error'],
            'rest_error' => $reports['rest_error'],
            'taapi_error' => $reports['taapi_error'],
            'coverage' => $coverage,
            'has_holes' => $coverage !== null && (($coverage['holes_count'] ?? 0) > 0),
        ];
        $payload['message'] = $this->fetchCandleMessage($maxMonths, $reports, $coverage);

        return $payload;
    }

    /**
     * @param  CandleReports  $reports
     * @param  CoverageReport|null  $coverage
     */
    private function fetchCandleMessage(int $maxMonths, array $reports, ?array $coverage): string
    {
        return $this->formatFetchSummary(
            $maxMonths,
            $reports['vision'],
            $reports['rest'],
            $reports['taapi'],
            $reports['vision_error'],
            $reports['rest_error'],
            $reports['taapi_error'],
            $coverage,
        );
    }

    /**
     * @return ValidatedInput
     */
    private function validateEnsureCoverage(Request $request): array
    {
        return $request->validate([
            'exchange_symbol_id' => ['required', 'integer', 'exists:exchange_symbols,id'],
            'timeframe' => ['required', 'string', 'in:'.implode(',', BacktestTimeframe::values())],
            'since' => ['nullable', 'date'],
            'candles_back' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'max_months' => ['sometimes', 'integer', 'min:1', 'max:48'],
        ]);
    }

    private function findActiveCoverageStep(int $symbolId, string $timeframe): ?Step
    {
        return Step::query()
            ->where('class', EnsureBacktestCandleCoverageStep::class)
            ->whereJsonContains('arguments->exchangeSymbolId', $symbolId)
            ->whereJsonContains('arguments->timeframe', $timeframe)
            ->whereNotIn('state', Step::terminalStepStates())
            ->latest('id')
            ->first();
    }

    /**
     * @param  ValidatedInput  $validated
     */
    private function createCoverageStep(int $symbolId, string $timeframe, array $validated): string
    {
        $maxMonths = $this->resolveMaxMonths(
            $timeframe,
            $this->validatedSince($validated),
            isset($validated['candles_back']) ? (int) $validated['candles_back'] : null,
            $this->validatedExplicitMonths($validated),
        );
        $gapLookbackTs = $this->resolveWindowSince(
            $timeframe,
            $this->validatedSince($validated),
            isset($validated['candles_back']) ? (int) $validated['candles_back'] : null,
        )?->getTimestamp();
        $blockUuid = (string) Str::uuid();

        Step::create($this->coverageStepAttributes($symbolId, $timeframe, $maxMonths, $gapLookbackTs, $blockUuid));

        return $blockUuid;
    }

    /**
     * @return array<string, StructuredValue>
     */
    private function coverageStepAttributes(
        int $symbolId,
        string $timeframe,
        int $maxMonths,
        ?int $gapLookbackTs,
        string $blockUuid,
    ): array {
        return [
            'block_uuid' => $blockUuid,
            'index' => 1,
            'class' => EnsureBacktestCandleCoverageStep::class,
            'queue' => 'indicators',
            'arguments' => [
                'exchangeSymbolId' => $symbolId,
                'timeframe' => $timeframe,
                'maxMonths' => $maxMonths,
                'gapLookbackTs' => $gapLookbackTs,
            ],
        ];
    }

    private function findCoverageOrchestrator(string $blockUuid): ?Step
    {
        return Step::query()
            ->where('block_uuid', $blockUuid)
            ->where('class', EnsureBacktestCandleCoverageStep::class)
            ->first();
    }

    /**
     * @return array{verdict: array<string, StructuredValue>|null, steps_done: int, steps_total: int}
     */
    private function coverageProgress(Step $orchestrator): array
    {
        $verdict = $orchestrator->response;
        $stepsDone = 0;
        $stepsTotal = 0;

        if ($orchestrator->child_block_uuid !== null) {
            $children = Step::where('block_uuid', $orchestrator->child_block_uuid)->get();
            $stepsTotal = $children->count();
            $stepsDone = $children->filter(fn ($s) => in_array(get_class($s->state), Step::terminalStepStates(), true))->count();
            $verdict = $children->firstWhere('class', VerifyCoverageResultStep::class)?->response ?? $verdict;
        }

        return [
            'verdict' => $verdict,
            'steps_done' => $stepsDone,
            'steps_total' => $stepsTotal,
        ];
    }

    /**
     * @return array{state: string, error: string|null}
     */
    private function coverageState(Step $orchestrator, string $orchState): array
    {
        if (in_array($orchState, ['Failed', 'Stopped', 'Cancelled'], true)) {
            return ['state' => 'failed', 'error' => $orchestrator->error_message];
        }

        if ($orchState === 'Completed') {
            return ['state' => 'done', 'error' => null];
        }

        return $this->pendingCoverageState($orchestrator, $orchState);
    }

    /**
     * @return array{state: string, error: string|null}
     */
    private function pendingCoverageState(Step $orchestrator, string $orchState): array
    {
        if ($orchestrator->child_block_uuid !== null) {
            return ['state' => 'fetching', 'error' => null];
        }

        if ($orchState === 'Running') {
            return ['state' => 'checking', 'error' => null];
        }

        if (in_array($orchState, ['Pending', 'Dispatched'], true)
            && $orchestrator->created_at?->lt(now()->subMinutes(3))) {
            return ['state' => 'daemon_slow', 'error' => null];
        }

        return ['state' => 'queued', 'error' => null];
    }

    /**
     * @param  array{state: string, error: string|null}  $state
     * @param  array{verdict: array<string, mixed>|null, steps_done: int, steps_total: int}  $progress
     * @return array<string, mixed>
     */
    private function coverageStatusPayload(array $state, array $progress): array
    {
        $verdict = $progress['verdict'];

        return [
            'ok' => true,
            'state' => $state['state'],
            'steps_done' => $progress['steps_done'],
            'steps_total' => $progress['steps_total'],
            'ready' => is_array($verdict) ? ($verdict['ready'] ?? null) : null,
            'coverage' => is_array($verdict) ? ($verdict['coverage'] ?? null) : null,
            'reason' => is_array($verdict) ? ($verdict['reason'] ?? null) : null,
            'error' => $state['error'],
        ];
    }

    /**
     * @return ValidatedInput
     */
    private function validateRun(Request $request): array
    {
        return $request->validate([
            'exchange_symbol_id' => ['required', 'integer', 'exists:exchange_symbols,id'],
            'timeframe' => ['required', 'string', 'in:'.implode(',', BacktestTimeframe::values())],
            'tp_percent' => ['required', 'numeric', 'gt:0'],
            'gap_long_percent' => ['nullable', 'numeric', 'gt:0'],
            'gap_short_percent' => ['nullable', 'numeric', 'gt:0'],
            'sl_percent' => ['required', 'numeric', 'gt:0'],
            'limit_hit' => ['nullable', 'integer', 'min:1'],
            'candles_back' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'max_rows' => ['sometimes', 'integer', 'min:10', 'max:1000'],
        ]);
    }

    /**
     * @param  ValidatedInput  $validated
     */
    private function runBacktest(array $validated, BacktestStopLossRecency $stopLossRecency): JsonResponse
    {
        $symbol = ExchangeSymbol::findOrFail((int) $validated['exchange_symbol_id']);
        $timeframe = (string) $validated['timeframe'];
        $coverage = (new CandleCoverageVerifier)->verify($symbol, $timeframe);
        $coverageWarning = $this->coverageWarning($coverage, $timeframe);
        $result = $this->simulateBacktest($symbol, $timeframe, $validated);
        $this->addStopLossRecency($result, $stopLossRecency);
        [$result, $truncated] = $this->truncateBacktestRows($result, (int) ($validated['max_rows'] ?? 500));

        return response()->json([
            'ok' => true,
            'pair' => $symbol->parsed_trading_pair,
            'pair_name' => $symbol->symbol?->name,
            'result' => $result,
            'rows_truncated' => $truncated,
            'coverage' => $coverage,
            'coverage_warning' => $coverageWarning,
        ]);
    }

    /**
     * @param  CoverageReport  $coverage
     */
    private function coverageWarning(array $coverage, string $timeframe): ?string
    {
        $gate = CoverageGate::evaluate($coverage, $timeframe);

        return $gate['ready'] ? null : 'Graded on imperfect data: '.$gate['reason'];
    }

    /**
     * @param  ValidatedInput  $validated
     * @return BacktestResult
     */
    private function simulateBacktest(ExchangeSymbol $symbol, string $timeframe, array $validated): array
    {
        $windowSince = $this->resolveWindowSince(
            $timeframe,
            null,
            isset($validated['candles_back']) ? (int) $validated['candles_back'] : null,
        );

        return (new BacktestSimulator)->simulate(...$this->backtestSimulationArguments($symbol, $timeframe, $validated, $windowSince));
    }

    /**
     * @param  ValidatedInput  $validated
     * @return SimulationArguments
     */
    private function backtestSimulationArguments(
        ExchangeSymbol $symbol,
        string $timeframe,
        array $validated,
        ?Carbon $windowSince,
    ): array {
        return [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'margin' => '5000',
            'leverage' => 20,
            'totalLimitOrders' => 4,
            'multipliers' => ['2', '2', '2', '2'],
            'tpPercent' => (string) $validated['tp_percent'],
            'gapLongPercent' => isset($validated['gap_long_percent']) ? (string) $validated['gap_long_percent'] : null,
            'gapShortPercent' => isset($validated['gap_short_percent']) ? (string) $validated['gap_short_percent'] : null,
            'slPercent' => (string) $validated['sl_percent'],
            'skipStopLoss' => false,
            'daysToIgnore' => 0,
            'limitHit' => isset($validated['limit_hit']) ? (int) $validated['limit_hit'] : null,
            'specificCandle' => null,
            'since' => $windowSince,
        ];
    }

    /**
     * @param  BacktestResult  $result
     */
    private function addStopLossRecency(array &$result, BacktestStopLossRecency $stopLossRecency): void
    {
        $coverage = $result['totals']['sl_coverage'] ?? [];
        if (! is_array($coverage)) {
            return;
        }

        foreach ($stopLossRecency->latestByDirection($result['rows']) as $direction => $stoppedAt) {
            if (! isset($coverage[$direction]) || ! is_array($coverage[$direction])) {
                $coverage[$direction] = [];
            }

            $coverage[$direction]['latest_stop_at'] = $stoppedAt;
        }

        $result['totals']['sl_coverage'] = $coverage;
    }

    /**
     * @param  BacktestResult  $result
     * @return array{0: BacktestResult, 1: bool}
     */
    private function truncateBacktestRows(array $result, int $maxRows): array
    {
        if (count($result['rows']) <= $maxRows) {
            return [$result, false];
        }

        $result['rows'] = array_slice($result['rows'], 0, $maxRows);

        return [$result, true];
    }

    /**
     * @return ValidatedInput
     */
    private function validateSuggestAdjustment(Request $request): array
    {
        return $request->validate([
            'exchange_symbol_id' => ['required', 'integer', 'exists:exchange_symbols,id'],
            'timeframe' => ['required', 'string', 'in:'.implode(',', BacktestTimeframe::values())],
            'tp_percent' => ['required', 'numeric', 'gt:0'],
            'sl_percent' => ['required', 'numeric', 'gt:0'],
            'gap_long_percent' => ['required', 'numeric', 'gt:0'],
            'gap_short_percent' => ['required', 'numeric', 'gt:0'],
        ]);
    }

    /**
     * @param  ValidatedInput  $validated
     */
    private function suggestAdjustmentResponse(array $validated): JsonResponse
    {
        $symbol = ExchangeSymbol::findOrFail((int) $validated['exchange_symbol_id']);
        $candidates = $this->adjustmentCandidates(
            $symbol,
            (string) $validated['timeframe'],
            (string) $validated['tp_percent'],
            (float) $validated['sl_percent'],
            (float) $validated['gap_long_percent'],
            (float) $validated['gap_short_percent'],
        );

        return response()->json([
            'ok' => true,
            'acceptable_max' => self::APPROVE_STOPS_MAX,
            'candidates' => $candidates,
            'best' => $this->bestAdjustment($candidates),
        ]);
    }

    /**
     * @return array<int, AdjustmentCandidate>
     */
    private function adjustmentCandidates(
        ExchangeSymbol $symbol,
        string $timeframe,
        string $tp,
        float $sl,
        float $gapLong,
        float $gapShort,
    ): array {
        $candidates = [];
        foreach ([0.5, 1.0, 1.5] as $delta) {
            $candidates[] = $this->gapCandidate($symbol, $timeframe, $tp, $sl, $gapLong, $gapShort, $delta);
            $candidates[] = $this->slCandidate($symbol, $timeframe, $tp, $sl, $gapLong, $gapShort, $delta);
        }

        return $candidates;
    }

    /**
     * @return AdjustmentCandidate
     */
    private function gapCandidate(
        ExchangeSymbol $symbol,
        string $timeframe,
        string $tp,
        float $sl,
        float $gapLong,
        float $gapShort,
        float $delta,
    ): array {
        $stops = $this->simulateAdjustmentStops($symbol, $timeframe, $tp, $gapLong + $delta, $gapShort + $delta, $sl);

        return [
            'lever' => 'gap',
            'delta' => $delta,
            'gap_long' => round($gapLong + $delta, 2),
            'gap_short' => round($gapShort + $delta, 2),
            'stops' => $stops,
            'acceptable' => $stops < self::APPROVE_STOPS_MAX,
        ];
    }

    /**
     * @return AdjustmentCandidate
     */
    private function slCandidate(
        ExchangeSymbol $symbol,
        string $timeframe,
        string $tp,
        float $sl,
        float $gapLong,
        float $gapShort,
        float $delta,
    ): array {
        $stops = $this->simulateAdjustmentStops($symbol, $timeframe, $tp, $gapLong, $gapShort, $sl + $delta);

        return [
            'lever' => 'sl',
            'delta' => $delta,
            'sl' => round($sl + $delta, 2),
            'stops' => $stops,
            'acceptable' => $stops < self::APPROVE_STOPS_MAX,
        ];
    }

    private function simulateAdjustmentStops(
        ExchangeSymbol $symbol,
        string $timeframe,
        string $tp,
        float $gapLong,
        float $gapShort,
        float $sl,
    ): int {
        $result = (new BacktestSimulator)->simulate(
            symbol: $symbol,
            timeframe: $timeframe,
            margin: '5000',
            leverage: 20,
            totalLimitOrders: 4,
            multipliers: ['2', '2', '2', '2'],
            tpPercent: $tp,
            gapLongPercent: (string) $gapLong,
            gapShortPercent: (string) $gapShort,
            slPercent: (string) $sl,
            skipStopLoss: false,
            daysToIgnore: 0,
            limitHit: null,
            specificCandle: null,
            since: null,
        );

        return (int) ($result['totals']['stops'] ?? 0);
    }

    /**
     * @param  array<int, AdjustmentCandidate>  $candidates
     * @return AdjustmentCandidate|null
     */
    private function bestAdjustment(array $candidates): ?array
    {
        $best = null;
        foreach ($candidates as $candidate) {
            if ($this->isBetterAdjustment($candidate, $best)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * @param  AdjustmentCandidate  $candidate
     * @param  AdjustmentCandidate|null  $best
     */
    private function isBetterAdjustment(array $candidate, ?array $best): bool
    {
        if (! $candidate['acceptable']) {
            return false;
        }

        if ($best === null || $candidate['delta'] < $best['delta']) {
            return true;
        }

        return $this->isPreferredAdjustmentTie($candidate, $best);
    }

    /**
     * @param  AdjustmentCandidate  $candidate
     * @param  AdjustmentCandidate  $best
     */
    private function isPreferredAdjustmentTie(array $candidate, array $best): bool
    {
        return $candidate['delta'] === $best['delta']
            && $candidate['lever'] === 'gap'
            && $best['lever'] === 'sl';
    }

    /**
     * @return ValidatedInput
     */
    private function validateToggleApproval(Request $request): array
    {
        return $request->validate([
            'exchange_symbol_id' => ['required', 'integer', 'exists:exchange_symbols,id'],
            'approve' => ['required', 'boolean'],
            'timeframe' => ['nullable', 'string', 'in:'.implode(',', BacktestTimeframe::values())],
            'gap_long_percent' => ['nullable', 'numeric', 'gt:0'],
            'gap_short_percent' => ['nullable', 'numeric', 'gt:0'],
            'tp_percent' => ['nullable', 'numeric', 'gt:0'],
            'sl_percent' => ['nullable', 'numeric', 'gt:0'],
        ]);
    }

    /**
     * @param  ValidatedInput  $validated
     */
    private function applyApprovalDecision(ExchangeSymbol $symbol, array $validated, bool $approve): void
    {
        $symbol->setAttribute('was_backtesting_approved', $approve);
        $symbol->setAttribute('backtesting_review_status', $approve ? 'approved' : 'rejected');
        $symbol->setAttribute('backtesting_reviewed_at', now());

        if ($approve) {
            $this->applyApprovedConfiguration($symbol, $validated);
        }
    }

    /**
     * @param  ValidatedInput  $validated
     */
    private function applyApprovedConfiguration(ExchangeSymbol $symbol, array $validated): void
    {
        $symbol->is_manually_enabled = true;

        if (isset($validated['gap_long_percent'])) {
            $symbol->percentage_gap_long = (float) $validated['gap_long_percent'];
        }
        if (isset($validated['gap_short_percent'])) {
            $symbol->percentage_gap_short = (float) $validated['gap_short_percent'];
        }
        if (isset($validated['tp_percent'])) {
            $symbol->profit_percentage = (float) $validated['tp_percent'];
        }
        if (isset($validated['sl_percent'])) {
            $symbol->stop_market_percentage = (float) $validated['sl_percent'];
        }
    }

    /**
     * @return array<string, scalar|null>
     */
    private function approvalPayload(ExchangeSymbol $symbol): array
    {
        return [
            'ok' => true,
            'was_backtesting_approved' => $symbol->was_backtesting_approved,
            'backtesting_review_status' => $symbol->backtesting_review_status,
            'backtesting_reviewed_at' => optional($symbol->backtesting_reviewed_at)->toIso8601String(),
            'percentage_gap_long' => $symbol->percentage_gap_long,
            'percentage_gap_short' => $symbol->percentage_gap_short,
            'profit_percentage' => $symbol->profit_percentage,
            'stop_market_percentage' => $symbol->stop_market_percentage,
        ];
    }

    /**
     * @return InsightInput
     */
    private function validateAiInsights(Request $request): array
    {
        return $request->validate([
            'exchange_symbol_id' => ['required', 'integer', 'exists:exchange_symbols,id'],
            'timeframe' => ['required', 'string', 'in:'.implode(',', BacktestTimeframe::values())],
            'totals' => ['required', 'array'],
            'regimes' => ['nullable', 'array'],
            'meta' => ['required', 'array'],
            'config' => ['required', 'array'],
            'rows' => ['nullable', 'array'],
        ]);
    }

    /**
     * @param  InsightInput  $validated
     */
    private function sendAiInsights(ExchangeSymbol $symbol, array $validated, ChatManager $chat): JsonResponse
    {
        $response = $chat->send(
            messages: $this->buildInsightMessages($symbol, $validated),
            connection: 'backtest-insights',
        );

        return response()->json([
            'ok' => true,
            'insights' => $response,
            'model' => config('ai-bridge.resolver.connections.backtest-insights'),
        ]);
    }

    private function aiInsightsFailure(Throwable $e): JsonResponse
    {
        Log::error('[AI Insights] '.$e->getMessage(), [
            'exception' => $e::class,
            'trace' => mb_substr($e->getTraceAsString(), 0, 2000),
        ]);

        return response()->json([
            'ok' => false,
            'error' => $e->getMessage().' ('.$e::class.')',
        ], 500);
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
     * @param  InsightInput  $validated
     * @return array<int, array{role: string, content: string}>
     */
    private function buildInsightMessages(ExchangeSymbol $symbol, array $validated): array
    {
        $context = $this->insightContext($symbol, $validated);

        return [
            ['role' => 'system', 'content' => self::INSIGHT_SYSTEM_PROMPT],
            ['role' => 'user', 'content' => $this->formatInsightUserPrompt($context)],
        ];
    }

    /**
     * @param  InsightInput  $validated
     * @return InsightContext
     */
    private function insightContext(ExchangeSymbol $symbol, array $validated): array
    {
        $totals = $validated['totals'];
        $meta = $validated['meta'];
        $config = $validated['config'];
        $regimes = $validated['regimes'] ?? [];
        $rows = $validated['rows'] ?? [];
        $account = Account::find(1);
        [$n, $metrics] = $this->insightLimitMetrics($symbol, $config, $totals);

        return [
            'symbol' => $symbol,
            'account' => $account,
            'meta' => $meta,
            'config' => $config,
            'regimes' => $regimes,
            'totals' => $totals,
            'n' => $n,
            ...$metrics,
            'failure_rows' => $this->insightFailureRows($rows, $meta),
        ];
    }

    /**
     * @param  array<string, StructuredValue>  $config
     * @param  array<string, StructuredValue>  $totals
     * @return array{0: int, 1: InsightMetrics}
     */
    private function insightLimitMetrics(ExchangeSymbol $symbol, array $config, array $totals): array
    {
        $n = (int) ($config['total_limit_orders'] ?? $symbol->total_limit_orders ?? 4);
        $multipliers = $this->resolveEffectiveMultipliers(
            is_string($config['multipliers'] ?? null) ? $config['multipliers'] : null,
            $symbol->limit_quantity_multipliers ?? null,
            $n,
        );

        return [$n, $this->insightMetrics($totals, $multipliers, $n)];
    }

    /**
     * @param  array<string, StructuredValue>  $totals
     * @param  array<int, string|float|int>  $multipliers
     * @return InsightMetrics
     */
    private function insightMetrics(array $totals, array $multipliers, int $n): array
    {
        $rungDist = $this->insightRungDistribution($totals);
        $rungNCount = $this->insightRungCount($rungDist, $n);
        $totalSims = array_sum($rungDist);

        return [
            'cumulative' => $this->cumulativeQtyMultipleAtRung($multipliers, $n),
            'rung_count' => $rungNCount,
            'total_sims' => $totalSims,
            'rung_pct' => $totalSims > 0 ? round(($rungNCount / $totalSims) * 100, 2) : 0,
        ];
    }

    /**
     * @param  array<string, StructuredValue>  $totals
     * @return array<int|string, int|float|numeric-string>
     */
    private function insightRungDistribution(array $totals): array
    {
        $distribution = $totals['rung_distribution'] ?? [];
        if (! is_array($distribution)) {
            return [];
        }

        return array_filter(
            $distribution,
            static fn (mixed $value): bool => is_numeric($value),
        );
    }

    /**
     * @param  array<int|string, int|float|numeric-string>  $distribution
     */
    private function insightRungCount(array $distribution, int $n): int|float
    {
        $count = $distribution[(string) $n] ?? $distribution[$n] ?? 0;

        return is_string($count) ? (float) $count : $count;
    }

    /**
     * @param  array<int, array<string, StructuredValue>>  $rows
     * @param  array<string, scalar|null>  $meta
     */
    private function insightFailureRows(array $rows, array $meta): string|false
    {
        if ($rows === []) {
            return '(no failure rows — every sim resolved as TP or rebound)';
        }

        return json_encode(
            $this->compactFailureRows((array) $rows, (string) ($meta['timeframe'] ?? '1d')),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * @param  InsightContext  $context
     */
    private function formatInsightUserPrompt(array $context): string
    {
        return sprintf(self::INSIGHT_USER_PROMPT, ...$this->insightPromptValues($context));
    }

    /**
     * @param  InsightContext  $context
     * @return array<int, string|float|int|bool|null>
     */
    private function insightPromptValues(array $context): array
    {
        return array_merge(
            $this->insightIdentityValues($context),
            $this->insightAccountValues($context),
            $this->insightSymbolValues($context),
            $this->insightResultValues($context),
        );
    }

    /**
     * @param  InsightContext  $context
     * @return array<int, string|float|int|bool|null>
     */
    private function insightIdentityValues(array $context): array
    {
        $symbol = $context['symbol'];
        $meta = $context['meta'];

        return [
            $symbol->parsed_trading_pair,
            $symbol->apiSystem->canonical ?? 'unknown',
            $meta['timeframe'] ?? 'unknown',
            $meta['window_since'] ?? 'full history',
            (int) ($meta['days_to_ignore'] ?? 0),
        ];
    }

    /**
     * @param  InsightContext  $context
     * @return array<int, string|float|int|bool|null>
     */
    private function insightAccountValues(array $context): array
    {
        $account = $context['account'];
        if (! $account instanceof Account) {
            $account = null;
        }

        return [
            $account?->total_positions_long ?? 'n/a',
            $account?->total_positions_short ?? 'n/a',
            $account?->position_leverage_long ?? 'n/a',
            $account?->position_leverage_short ?? 'n/a',
            $account?->margin_percentage_long ?? 'n/a',
            $account?->margin_percentage_short ?? 'n/a',
            $account?->stop_market_wait_minutes ?? 'n/a',
            $account?->profit_percentage ?? 'n/a',
            $account?->stop_market_initial_percentage ?? 'n/a',
        ];
    }

    /**
     * @param  InsightContext  $context
     * @return array<int, string|float|int|bool|null>
     */
    private function insightSymbolValues(array $context): array
    {
        $symbol = $context['symbol'];

        return [
            $symbol->parsed_trading_pair,
            $symbol->apiSystem->canonical ?? 'unknown',
            $symbol->percentage_gap_long ?? 'n/a',
            $symbol->percentage_gap_short ?? 'n/a',
            $symbol->total_limit_orders ?? 'n/a',
            is_array($symbol->limit_quantity_multipliers)
                ? implode(',', $symbol->limit_quantity_multipliers)
                : ($symbol->limit_quantity_multipliers ?? 'n/a'),
        ];
    }

    /**
     * @param  InsightContext  $context
     * @return array<int, string|float|int|bool|null>
     */
    private function insightResultValues(array $context): array
    {
        return [
            json_encode($context['config'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            $context['n'],
            $context['cumulative'],
            $context['n'],
            $context['rung_pct'],
            $context['rung_count'],
            max(1, $context['total_sims']),
            json_encode($context['totals'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            json_encode($context['regimes'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            $context['failure_rows'],
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

        $fromSymbol = $this->decodeStoredMultipliers(match (true) {
            is_array($symbolStored) => $symbolStored,
            is_string($symbolStored) => $symbolStored,
            default => null,
        });
        if (is_array($fromSymbol) && $fromSymbol !== []) {
            return array_values($fromSymbol);
        }

        return array_fill(0, max(1, $n), '2');
    }

    /**
     * @param  array<int|string, scalar|null>|string|null  $symbolStored
     * @return array<int|string, string|float|int>|null
     */
    private function decodeStoredMultipliers(array|string|null $symbolStored): ?array
    {
        if (is_array($symbolStored)) {
            return array_filter(
                $symbolStored,
                static fn (mixed $value): bool => is_scalar($value) && ! is_bool($value),
            );
        }

        if (! is_string($symbolStored)) {
            return null;
        }

        if ($symbolStored === '') {
            return null;
        }

        return $this->decodeStoredMultiplierJson($symbolStored);
    }

    /**
     * @return array<int|string, string|float|int>|null
     */
    private function decodeStoredMultiplierJson(string $symbolStored): ?array
    {
        $decoded = json_decode($symbolStored, true);

        if (! is_array($decoded)) {
            return null;
        }

        return array_filter(
            $decoded,
            static fn (mixed $value): bool => is_scalar($value) && ! is_bool($value),
        );
    }

    /**
     * Trim failure rows to the fields the model actually needs for
     * pattern analysis, and cap at 50 to keep the prompt lean. Drops
     * the verbose human-readable `message` field since the structured
     * fields carry the same information in a cheaper form.
     *
     * `candles_to_rung_n` — candles between the sim's entry and its
     * deepest-rung touch. This is the collapse-speed signal the prompt's
     * structural-vs-avoidable classification keys on (0–5 candles = trend
     * event, 0 = same-candle collapse, slow reaches = noise stops).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function compactFailureRows(array $rows, string $timeframe): array
    {
        $intervalSeconds = BacktestTimeframe::tryFrom($timeframe)?->seconds()
            ?? BacktestTimeframe::OneDay->seconds();

        return array_slice(array_map(static function ($r) use ($intervalSeconds) {
            $start = isset($r['start_candle']) ? strtotime((string) $r['start_candle']) : false;
            $touch = isset($r['last_touch_candle']) ? strtotime((string) $r['last_touch_candle']) : false;

            return [
                'direction' => $r['direction'] ?? null,
                'start' => $r['start_candle'] ?? null,
                'entry' => $r['entry_ref_price'] ?? null,
                'last_rung' => $r['last_rung'] ?? null,
                'last_touch' => $r['last_touch_candle'] ?? null,
                'tp' => $r['tp_price'] ?? null,
                'candles_to_rung_n' => ($start !== false && $touch !== false && $touch >= $start)
                    ? intdiv($touch - $start, $intervalSeconds)
                    : null,
                'status' => $r['status'] ?? null,
                'mae_pct' => $r['mae_pct'] ?? null,
            ];
        }, $rows), 0, 50);
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
     * Lookup all Binance ExchangeSymbols grouped by quote currency, with
     * token + quote and the stored defaults the UI will pre-fill into
     * the form when the user changes selection. Every Binance symbol is
     * backtestable regardless of enable/approval state so the operator can
     * vet a token before promoting it.
     *
     * @return array<string, array<int, SymbolRow>>
     */
    private function enabledSymbolGroups(): array
    {
        $immediatelyTradeableIds = $this->immediatelyTradeableIds();
        $rows = $this->backtestableSymbolRows();
        $buckets = $this->groupSymbolRows($rows, $immediatelyTradeableIds);

        return $this->orderSymbolGroups($buckets);
    }

    /**
     * @return Collection<int, int>
     */
    private function immediatelyTradeableIds(): Collection
    {
        return ExchangeSymbol::query()
            ->awaitingBacktestingApproval()
            ->pluck('exchange_symbols.id')
            ->flip();
    }

    /**
     * @return Collection<int, \stdClass>
     */
    private function backtestableSymbolRows(): Collection
    {
        return DB::table('exchange_symbols as es')
            ->join('symbols as s', 's.id', '=', 'es.symbol_id')
            ->join('api_systems as ap', 'ap.id', '=', 'es.api_system_id')
            ->where('ap.canonical', 'binance')
            ->orderByRaw('UPPER(s.token) ASC')
            ->get($this->backtestableSymbolColumns());
    }

    /**
     * @return array<int, string>
     */
    private function backtestableSymbolColumns(): array
    {
        return [
            'es.id',
            's.token',
            's.cmc_ranking',
            's.cmc_category',
            's.image_url',
            'es.quote',
            'ap.canonical as exchange',
            'es.percentage_gap_long',
            'es.percentage_gap_short',
            'es.total_limit_orders',
            'es.limit_quantity_multipliers',
            'es.was_backtesting_approved',
            'es.backtesting_review_status',
            'es.backtesting_reviewed_at',
            'es.profit_percentage',
            'es.stop_market_percentage',
            'es.direction',
        ];
    }

    /**
     * @param  Collection<int, \stdClass>  $rows
     * @param  Collection<int, int>  $immediatelyTradeableIds
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function groupSymbolRows(Collection $rows, Collection $immediatelyTradeableIds): array
    {
        $buckets = [];
        foreach ($rows as $row) {
            $q = mb_strtoupper((string) $row->quote);
            $buckets[$q][] = $this->formatSymbolRow($row, $immediatelyTradeableIds);
        }

        return $buckets;
    }

    /**
     * @param  Collection<int, int>  $immediatelyTradeableIds
     * @return SymbolRow
     */
    private function formatSymbolRow(\stdClass $row, Collection $immediatelyTradeableIds): array
    {
        return [
            'id' => (int) $row->id,
            'label' => $row->token, 'token' => $row->token,
            'cmc_ranking' => $row->cmc_ranking !== null ? (int) $row->cmc_ranking : null,
            'cmc_category' => $row->cmc_category,
            'image_url' => $row->image_url,
            'quote' => $row->quote,
            'exchange' => $row->exchange,
            'percentage_gap_long' => $row->percentage_gap_long,
            'percentage_gap_short' => $row->percentage_gap_short,
            'total_limit_orders' => $row->total_limit_orders,
            'limit_quantity_multipliers' => $row->limit_quantity_multipliers,
            'was_backtesting_approved' => (bool) $row->was_backtesting_approved,
            'backtesting_review_status' => $row->backtesting_review_status,
            'backtesting_reviewed_at' => $row->backtesting_reviewed_at,
            'profit_percentage' => $row->profit_percentage,
            'stop_market_percentage' => $row->stop_market_percentage,
            'direction' => $row->direction !== null ? mb_strtoupper((string) $row->direction) : null,
            'is_immediately_tradeable' => $immediatelyTradeableIds->has((int) $row->id),
        ];
    }

    /**
     * @param  array<string, array<int, SymbolRow>>  $buckets
     * @return array<string, array<int, SymbolRow>>
     */
    private function orderSymbolGroups(array $buckets): array
    {
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
     * @param  ValidatedInput  $validated
     */
    private function validatedSince(array $validated): ?string
    {
        return is_string($validated['since'] ?? null) ? $validated['since'] : null;
    }

    /**
     * @param  ValidatedInput  $validated
     */
    private function validatedExplicitMonths(array $validated): int|string|null
    {
        $value = $validated['max_months'] ?? null;

        return is_int($value) || is_string($value) ? $value : null;
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
            'tp_percent' => (string) ($account?->profit_percentage ?? '0.36'),
            'sl_percent' => (string) ($account?->stop_market_initial_percentage ?? '2.50'),
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
            $hours = $candlesBack * (BacktestTimeframe::from($timeframe)->seconds() / 3600);

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
            $seconds = $candlesBack * BacktestTimeframe::from($timeframe)->seconds();

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
        $raw = collect(Kraite::timeframes())
            ->filter(fn ($tf) => BacktestTimeframe::tryFrom((string) $tf) !== null)
            ->unique()
            ->sortBy(fn ($tf) => BacktestTimeframe::from((string) $tf)->seconds())
            ->values()
            ->all();

        return empty($raw) ? BacktestTimeframe::values() : $raw;
    }

    /**
     * @return array<int, string>|null
     */
    private function parseMultipliers(?string $input): ?array
    {
        if ($input === null || mb_trim($input) === '') {
            return null;
        }

        $parts = array_filter(array_map('trim', explode(',', $input)), fn ($v) => $v !== '');
        $this->assertValidMultipliers($parts);

        return array_values(array_map('strval', $parts));
    }

    /**
     * @param  array<int, string>  $parts
     */
    private function assertValidMultipliers(array $parts): void
    {
        foreach ($parts as $value) {
            if (! is_numeric($value) || (float) $value <= 0) {
                throw new \InvalidArgumentException("Invalid multipliers entry '{$value}'. Must be positive numerics comma-separated.");
            }
        }
    }

    /**
     * @param  VisionReport|null  $vision
     * @param  RestReport|null  $rest
     * @param  TaapiReport|null  $taapi
     * @param  CoverageReport|null  $coverage
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
        $parts = array_filter([
            sprintf('Window: %d months', $windowMonths),
            $this->formatVisionSummary($vision, $visionError),
            $this->formatRestSummary($rest, $restError),
            $this->formatTaapiSummary($taapi, $taapiError),
            $this->formatCoverageSummary($coverage),
        ], static fn (?string $part): bool => $part !== null);

        return empty($parts) ? 'No fetch performed.' : implode(' · ', $parts);
    }

    /**
     * @param  VisionReport|null  $vision
     */
    private function formatVisionSummary(?array $vision, ?string $error): ?string
    {
        if ($vision !== null) {
            $errors = $vision['errors'] ?? [];

            return sprintf(
                'Vision: %d new + %d already-covered months → %d candles%s',
                $this->defaultCount($vision['months_downloaded'] ?? null),
                $this->defaultCount($vision['months_already_covered'] ?? null),
                $this->defaultCount($vision['candles_upserted'] ?? null),
                $errors === [] ? '' : ' ('.count($errors).' errors)',
            );
        }

        return $error === null ? null : 'Vision skipped: '.$error;
    }

    /**
     * @param  RestReport|null  $rest
     */
    private function formatRestSummary(?array $rest, ?string $error): ?string
    {
        if ($rest === null) {
            return $error === null ? null : 'Binance REST skipped: '.$error;
        }

        $gaps = $rest['gaps'] ?? null;
        $summary = $this->formatRestCounts($rest, $gaps);
        $skipped = $this->formatSkippedGaps($gaps);

        return implode(' · ', array_filter([$summary, $skipped], static fn (?string $part): bool => $part !== null));
    }

    /**
     * @param  RestReport  $rest
     * @param  RestGaps|null  $gaps
     */
    private function formatRestCounts(array $rest, ?array $gaps): string
    {
        $forwardInserted = $this->defaultCount($rest['inserted'] ?? null);
        $gapsInserted = $this->defaultCount($gaps['inserted'] ?? null);
        $totalInserted = $forwardInserted + $gapsInserted;

        if ($this->restIsCurrent($rest, $gaps)) {
            return 'Binance REST: already current';
        }

        return sprintf(
            'Binance REST: %d candles (%d forward, %d gap-fill across %d gap(s))',
            $totalInserted,
            $forwardInserted,
            $gapsInserted,
            $this->defaultCount($gaps['gaps_found'] ?? null),
        );
    }

    private function defaultCount(?int $value): int
    {
        return $value ?? 0;
    }

    /**
     * @param  RestReport  $rest
     * @param  RestGaps|null  $gaps
     */
    private function restIsCurrent(array $rest, ?array $gaps): bool
    {
        return ($rest['skipped'] ?? false) === true
            && $this->defaultCount($gaps['gaps_filled'] ?? null) === 0
            && $this->defaultCount($gaps['gaps_found'] ?? null) === 0;
    }

    /**
     * @param  RestGaps|null  $gaps
     */
    private function formatSkippedGaps(?array $gaps): ?string
    {
        if (($gaps['skipped'] ?? []) === []) {
            return null;
        }

        return sprintf('⚠ %d gap(s) still unfilled after Binance REST', count($gaps['skipped']));
    }

    /**
     * @param  TaapiReport|null  $taapi
     */
    private function formatTaapiSummary(?array $taapi, ?string $error): ?string
    {
        if ($taapi === null) {
            return $error === null ? null : 'TAAPI skipped: '.$error;
        }

        if (($taapi['skipped'] ?? false) === true) {
            return sprintf('TAAPI: already current (latest %s)', $taapi['latest'] ?? 'n/a');
        }

        return sprintf(
            'TAAPI: %d candles (latest %s)',
            $taapi['inserted'] ?? 0,
            $taapi['latest'] ?? 'n/a',
        );
    }

    /**
     * @param  CoverageReport|null  $coverage
     */
    private function formatCoverageSummary(?array $coverage): ?string
    {
        if ($coverage === null) {
            return null;
        }

        $holes = $this->defaultCount($coverage['holes_count'] ?? null);
        if ($holes > 0) {
            return sprintf(
                '⚠ %d hole(s) remain — %s%% contiguity, earliest %s, latest %s',
                $holes,
                $this->coverageText($coverage['contiguity_percent'] ?? null),
                $this->coverageText($coverage['earliest'] ?? null),
                $this->coverageText($coverage['latest'] ?? null),
            );
        }

        return sprintf(
            'Coverage: contiguous (%d candles, earliest %s, latest %s)',
            $this->defaultCount($coverage['total_present'] ?? null),
            $this->coverageText($coverage['earliest'] ?? null),
            $this->coverageText($coverage['latest'] ?? null),
        );
    }

    private function coverageText(string|float|int|null $value): string
    {
        return $value === null ? 'n/a' : (string) $value;
    }
}
