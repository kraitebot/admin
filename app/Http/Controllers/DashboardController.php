<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;

class DashboardController extends Controller
{
    /**
     * Open positions are anything mid-lifecycle — exclude only terminal
     * states. Mirrors the comparator's gate on /accounts/positions.
     */
    private const OPEN_POSITION_STATUSES = ['new', 'opening', 'active', 'syncing', 'waping', 'closing', 'cancelling'];

    /**
     * Approx-duration table used to sort the engine's active timeframes
     * longest-first, regardless of the order Kraite::timeframes() returns
     * them in.
     *
     * @var array<string, int>
     */
    private const TIMEFRAME_SECONDS = [
        '1m' => 60, '3m' => 180, '5m' => 300, '15m' => 900, '30m' => 1800,
        '1h' => 3600, '2h' => 7200, '4h' => 14400, '6h' => 21600, '8h' => 28800, '12h' => 43200,
        '1d' => 86400, '3d' => 259200, '1w' => 604800, '1M' => 2592000,
    ];

    public function index(): View
    {
        $isAdmin = (bool) Auth::user()->is_admin;

        // Sysadmin sees every account (cross-user); regular users see
        // only their own. Ordered to match the dropdown's expected
        // grouping: by user (admin view), then exchange + account name.
        $query = Account::with(['apiSystem', 'user']);

        if (! $isAdmin) {
            $query->where('user_id', Auth::id());
        }

        $accounts = $query
            ->orderBy('user_id')
            ->orderBy('name')
            ->get()
            ->map(fn (Account $account) => [
                'id' => $account->id,
                'name' => $account->name,
                'exchange' => $account->apiSystem?->name ?? 'Unknown',
                'owner' => $account->user?->name ?? 'Unknown',
            ]);

        return view('dashboard', [
            'accounts' => $accounts,
            'isAdmin' => $isAdmin,
        ]);
    }

    /**
     * Live tile feed for the dashboard. Scoped by owner — same 404 surface
     * whether the account doesn't exist or belongs to someone else, so
     * existence isn't leaked.
     */
    public function data(Request $request): JsonResponse
    {
        $request->validate([
            'account_id' => ['required', 'integer'],
        ]);

        // Sysadmin can read any account; everyone else is scoped to owner.
        $query = Account::where('id', $request->input('account_id'));

        if (! Auth::user()->is_admin) {
            $query->where('user_id', Auth::id());
        }

        $account = $query->firstOrFail();

        $rawPositions = $account->positions()
            ->whereIn('status', self::OPEN_POSITION_STATUSES)
            ->with(['exchangeSymbol.symbol', 'orders'])
            ->orderBy('opened_at')
            ->get();

        $candleOpensByExchangeSymbol = $this->loadCandleOpens(
            $rawPositions->pluck('exchange_symbol_id')->filter()->unique()->all()
        );

        // Sort by worst AlphaLimit first — high alpha_limit_pct = price
        // is closest to filling the next ladder rung, which is what the
        // operator most wants to see at the top of the grid.
        $positions = $rawPositions
            ->map(fn ($position) => $this->serializePosition($position, $candleOpensByExchangeSymbol))
            ->sortByDesc(fn (array $p) => (float) ($p['alpha_limit_pct'] ?? 0))
            ->values()
            ->all();

        return response()->json([
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
            ],
            'metrics' => $this->accountMetrics($account),
            'btc' => $this->btcStrip(),
            'positions' => $positions,
        ]);
    }

    /**
     * Account-level KPIs for the top strip — sourced from the latest
     * `account_balance_history` snapshot (ingestion writes it on each
     * exchange balance poll). Drawdown is computed against the all-time
     * peak `total_margin_balance` for the account, so it tracks the
     * worst-case dent operators care about.
     *
     * @return array<string, mixed>
     */
    private function accountMetrics(Account $account): array
    {
        $latest = DB::table('account_balance_history')
            ->where('account_id', $account->id)
            ->orderByDesc('id')
            ->first(['total_wallet_balance', 'total_unrealized_profit', 'total_maintenance_margin', 'total_margin_balance']);

        if (! $latest) {
            return [
                'balance' => null, 'pnl' => null, 'drawdown_pct' => null, 'margin_ratio' => null,
                'is_stub' => true,
            ];
        }

        $marginBalance = (string) ($latest->total_margin_balance ?? '0');
        $maintMargin   = (string) ($latest->total_maintenance_margin ?? '0');

        $marginRatio = (is_numeric($marginBalance) && bccomp($marginBalance, '0', 16) > 0)
            ? number_format((float) bcmul(bcdiv($maintMargin, $marginBalance, 6), '100', 4), 2, '.', '')
            : null;

        $peak = DB::table('account_balance_history')
            ->where('account_id', $account->id)
            ->max('total_margin_balance');

        $drawdownPct = null;
        if ($peak !== null && is_numeric($peak) && bccomp((string) $peak, '0', 16) > 0) {
            $delta = bcsub((string) $peak, $marginBalance, 16);
            $drawdownPct = number_format((float) bcmul(bcdiv($delta, (string) $peak, 6), '100', 4), 2, '.', '');
        }

        return [
            // USDT amounts → 2 decimals to match exchange convention.
            'balance'      => number_format((float) $latest->total_wallet_balance, 2, '.', ''),
            'pnl'          => number_format((float) $latest->total_unrealized_profit, 2, '.', ''),
            'drawdown_pct' => $drawdownPct,
            'margin_ratio' => $marginRatio,
            'is_stub'      => false,
        ];
    }

    /**
     * BTC reference strip — real mark + per-timeframe direction dots
     * computed against the engine-active timeframes, same logic as the
     * position tiles. Anchored on Binance USDT pair.
     *
     * @return array<string, mixed>|null
     */
    private function btcStrip(): ?array
    {
        $btc = DB::table('exchange_symbols')
            ->join('symbols', 'symbols.id', '=', 'exchange_symbols.symbol_id')
            ->join('api_systems', 'api_systems.id', '=', 'exchange_symbols.api_system_id')
            ->where('symbols.token', 'BTC')
            ->where('exchange_symbols.quote', 'USDT')
            ->where('api_systems.canonical', 'binance')
            ->select('exchange_symbols.id as es_id', 'exchange_symbols.mark_price', 'symbols.image_url')
            ->first();

        if (! $btc) {
            return null;
        }

        $opens = $this->loadCandleOpens([(int) $btc->es_id])[$btc->es_id] ?? [];
        $mark = $btc->mark_price !== null ? (string) $btc->mark_price : null;

        // Format with the actual BTC exchange-symbol precision so the
        // dashboard mirrors what Binance shows ($69,123.45 not $69,123.4567).
        $btcEs = ExchangeSymbol::find($btc->es_id);
        $markFormatted = $mark !== null && $btcEs ? api_format_price($mark, $btcEs) : $mark;

        return [
            'token' => 'BTC',
            'image' => $btc->image_url,
            'mark' => $markFormatted,
            'dots' => $this->buildTimeframeDots($mark, $opens),
        ];
    }

    /**
     * Shape a single Position for the tile renderer. Stubs the 24h
     * variation + per-timeframe direction signals — both deferred to v2
     * (candle-table lookups) but kept in the payload so the visual layer
     * can render against real shapes today.
     *
     * @param  \Kraite\Core\Models\Position  $position
     * @param  array<int, array<string, string>>  $candleOpensByExchangeSymbol  Keyed by exchange_symbol_id → [tf => current_candle_open]
     */
    private function serializePosition($position, array $candleOpensByExchangeSymbol = []): array
    {
        $exchangeSymbol = $position->exchangeSymbol;
        $symbol = $exchangeSymbol?->symbol;

        // Raw values used for math (need full precision); formatted values
        // for display use kraite-core's api_format_* helpers so the tile
        // shows the same precision the exchange does (price_precision +
        // tick_size for prices, quantity_precision for sizes).
        $currentPrice = $exchangeSymbol?->mark_price !== null ? (string) $exchangeSymbol->mark_price : null;

        $firstProfit = $position->first_profit_price !== null ? (string) $position->first_profit_price : null;
        $lastLimit = $position->lastLimitOrder()?->price;
        $lastLimit = $lastLimit !== null ? (string) $lastLimit : null;

        $profitOrder = $position->profitOrder();
        $currentTp = $profitOrder?->price !== null ? (string) $profitOrder->price : null;
        $nextLimit = $position->nextPendingLimitOrderPrice();

        $fmtPrice = fn (?string $v) => $exchangeSymbol && $v !== null && is_numeric($v)
            ? api_format_price($v, $exchangeSymbol)
            : $v;

        // Limit ticks — every LIMIT order with its price + filled state so
        // the bar can hide already-consumed rungs while keeping unfilled ones.
        $limits = $position->orders
            ->where('type', 'LIMIT')
            ->sortBy('quantity')
            ->values()
            ->map(fn ($order, $idx) => [
                'index' => $idx + 1,
                'price' => $fmtPrice((string) $order->price),
                'quantity' => $exchangeSymbol ? api_format_quantity((string) $order->quantity, $exchangeSymbol) : (string) $order->quantity,
                'status' => strtoupper((string) $order->status),
                'filled' => strtoupper((string) $order->status) === 'FILLED',
            ])
            ->all();

        $totalLimits = count($limits);
        $filledCount = collect($limits)->filter(fn ($l) => $l['filled'])->count();

        // Alpha values — reimplement against mark_price (kraite-core's
        // accessor reads exchange_symbols.current_price, which is itself
        // a candle-derived accessor with a 15-min freshness gate; on the
        // dashboard we want the live mark from the exchange feed).
        $alphaPathPct  = $this->computeAlphaPathPercent($firstProfit, $lastLimit, $currentPrice);
        $alphaLimitPct = $this->computeAlphaLimitPercent($currentTp, $nextLimit, $currentPrice);

        // Position size + unrealised PnL — both computed against live
        // mark_price. Same reason as alpha math: kraite-core's
        // Position::pnl() relies on the candle-derived current_price
        // accessor, which collapses to 0 when the 5m candle is stale.
        $direction = strtoupper((string) $position->direction);
        $quantity = (string) ($position->quantity ?? '0');
        $opening = $position->opening_price !== null ? (string) $position->opening_price : null;

        $size = $this->computeSize($quantity, $currentPrice);
        $pnl  = $this->computePnl($direction, $quantity, $opening, $currentPrice);

        return [
            'id' => $position->id,
            'status' => strtolower((string) $position->status),
            'symbol' => $position->parsed_trading_pair ?? $exchangeSymbol?->symbol ?? 'Unknown',
            'token' => $exchangeSymbol?->token ?? $symbol?->token ?? null,
            'token_name' => $symbol?->name ?? null,
            'token_image' => $symbol?->image_url ?? null,
            'direction' => strtoupper((string) $position->direction),
            'leverage' => (int) $position->leverage,
            'opened_at' => optional($position->opened_at ?? $position->created_at)?->toIso8601String(),
            'age_human' => optional($position->opened_at ?? $position->created_at)?->diffForHumans(['short' => true]),

            // 24h % stays stubbed — needs a separate candles aggregate.
            'var_24h_pct' => null,
            'timeframe_dots' => $this->buildTimeframeDots(
                $currentPrice,
                $candleOpensByExchangeSymbol[$position->exchange_symbol_id] ?? [],
            ),

            'current_price' => $fmtPrice($currentPrice),
            'first_profit_price' => $fmtPrice($firstProfit),
            'profit_price' => $fmtPrice($currentTp),
            'next_limit_price' => $fmtPrice($nextLimit),
            'last_limit_price' => $fmtPrice($lastLimit),

            'alpha_path_pct' => $alphaPathPct,
            'alpha_limit_pct' => $alphaLimitPct,

            'size' => $size,
            'pnl' => $pnl,

            'filled_count' => $filledCount,
            'total_limits' => $totalLimits,
            'limits' => $limits,
        ];
    }

    /**
     * Engine-active timeframes for the direction-dot strip, sorted longest-
     * first so dots read macro→micro on the tile (1d, 4h, 1h…). Source of
     * truth is the `Kraite::timeframes()` singleton — same list ingestion
     * uses to schedule indicator + candle work, so the dashboard never
     * shows a timeframe the engine isn't tracking.
     *
     * @return array<int, string>
     */
    private function dotTimeframes(): array
    {
        $tfs = collect(Kraite::timeframes())
            ->filter(fn ($tf) => isset(self::TIMEFRAME_SECONDS[$tf]))
            ->unique()
            ->sortByDesc(fn ($tf) => self::TIMEFRAME_SECONDS[$tf])
            ->values()
            ->all();

        return $tfs;
    }

    /**
     * Pull the OPEN of the most recent candle per (exchange_symbol_id,
     * timeframe). The open of the running bucket equals the close of the
     * previous closed bucket — same boundary price, so comparing mark
     * against this value answers "where does the live price sit relative
     * to the start of the current candle?" without needing to know
     * whether ingestion has flipped the row to a new bucket yet.
     *
     * Avoids the prior pitfall where the top row's `close` was being
     * live-updated by ingestion (close ≈ mark), which made every dot
     * collapse toward flat/up.
     *
     * @param  array<int, int>  $exchangeSymbolIds
     * @return array<int, array<string, string>>
     */
    private function loadCandleOpens(array $exchangeSymbolIds): array
    {
        if (empty($exchangeSymbolIds)) {
            return [];
        }

        $rows = DB::table(DB::raw('(
            SELECT
                exchange_symbol_id,
                timeframe,
                open,
                timestamp,
                ROW_NUMBER() OVER (PARTITION BY exchange_symbol_id, timeframe ORDER BY timestamp DESC) AS rn
            FROM candles
            WHERE exchange_symbol_id IN ('.implode(',', array_map('intval', $exchangeSymbolIds)).')
              AND timeframe IN ("'.implode('","', $this->dotTimeframes()).'")
        ) ranked'))
            ->select('exchange_symbol_id', 'timeframe', 'open')
            ->whereRaw('rn = 1')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[$row->exchange_symbol_id][$row->timeframe] = (string) $row->open;
        }

        return $result;
    }

    /**
     * Build a position's per-timeframe dot list by comparing the live mark
     * price against the last closed candle's close on each timeframe. Up =
     * mark > last_close, down = mark < last_close, flat = exactly equal,
     * none = either side missing.
     *
     * @param  array<string, string>  $candleOpensByTimeframe  [tf => current candle's open price]
     * @return array<int, array<string, string>>
     */
    private function buildTimeframeDots(?string $currentPrice, array $candleOpensByTimeframe): array
    {
        $dots = [];
        foreach ($this->dotTimeframes() as $tf) {
            $candleOpen = $candleOpensByTimeframe[$tf] ?? null;
            $direction = $this->directionFromLive($currentPrice, $candleOpen);

            // Drop timeframes with no candle data — no value in showing a
            // permanently-grey placeholder. Only render dots we can colour.
            if ($direction === 'none') {
                continue;
            }

            $dots[] = [
                'timeframe' => $tf,
                'direction' => $direction,
            ];
        }

        return $dots;
    }

    private function directionFromLive(?string $current, ?string $lastClose): string
    {
        if ($current === null || $lastClose === null
            || ! is_numeric($current) || ! is_numeric($lastClose)) {
            return 'none';
        }

        $cmp = bccomp($current, $lastClose, 16);

        return match (true) {
            $cmp > 0 => 'up',
            $cmp < 0 => 'down',
            default => 'flat',
        };
    }

    /**
     * Notional position value (USDT) at the live mark — quantity × current.
     * Returns null when either input is missing or non-numeric.
     */
    private function computeSize(?string $quantity, ?string $currentPrice): ?string
    {
        if ($quantity === null || $currentPrice === null
            || ! is_numeric($quantity) || ! is_numeric($currentPrice)) {
            return null;
        }

        return number_format((float) bcmul($quantity, $currentPrice, 8), 2, '.', '');
    }

    /**
     * Unrealised PnL in USDT against the entry (opening_price). Direction-
     * aware: LONG = (mark - entry) × qty, SHORT = (entry - mark) × qty.
     * Matches the standard "is this position underwater right now" reading.
     * Returns null on missing data so the tile renders an "—" placeholder
     * instead of a misleading zero.
     */
    private function computePnl(string $direction, ?string $quantity, ?string $entry, ?string $currentPrice): ?string
    {
        if ($quantity === null || $entry === null || $currentPrice === null
            || ! is_numeric($quantity) || ! is_numeric($entry) || ! is_numeric($currentPrice)) {
            return null;
        }

        $diff = $direction === 'LONG'
            ? bcsub($currentPrice, $entry, 16)
            : bcsub($entry, $currentPrice, 16);

        return number_format((float) bcmul($diff, $quantity, 16), 2, '.', '');
    }

    /**
     * Where the live price sits between the original profit price (start)
     * and the deepest limit (end), as a 0–100 percentage. Direction-aware:
     * LONG has TP above and limits below (start > end), SHORT inverts.
     * Returns "0.0" when any input is missing or non-numeric so the bar
     * never explodes on degenerate data.
     */
    private function computeAlphaPathPercent(?string $start, ?string $end, ?string $current): string
    {
        if ($start === null || $end === null || $current === null
            || ! is_numeric($start) || ! is_numeric($end) || ! is_numeric($current)) {
            return '0.0';
        }

        if (bccomp($start, $end, 16) === 0) {
            return '0.0';
        }

        // SHORT branch (end >= start: TP below, limits above).
        if (bccomp($end, $start, 16) >= 0) {
            if (bccomp($current, $start, 16) <= 0) {
                $fraction = '0';
            } elseif (bccomp($current, $end, 16) >= 0) {
                $fraction = '1';
            } else {
                $num = bcsub($current, $start, 16);
                $den = bcsub($end, $start, 16);
                $fraction = bcdiv($num, $den, 6);
            }
        } else {
            // LONG branch (end < start: TP above, limits below).
            if (bccomp($current, $start, 16) >= 0) {
                $fraction = '0';
            } elseif (bccomp($current, $end, 16) <= 0) {
                $fraction = '1';
            } else {
                $num = bcsub($start, $current, 16);
                $den = bcsub($start, $end, 16);
                $fraction = bcdiv($num, $den, 6);
            }
        }

        return number_format((float) bcmul($fraction, '100', 2), 1, '.', '');
    }

    /**
     * Distance from the current TP (entry-side anchor) to the next pending
     * limit, expressed as how far the live price has travelled along that
     * leg in 0–100 %. 100 = price has reached the next rung; 0 = price is
     * still at TP. Same null-safe contract as computeAlphaPathPercent.
     */
    private function computeAlphaLimitPercent(?string $tp, ?string $nextLimit, ?string $current): string
    {
        if ($tp === null || $nextLimit === null || $current === null
            || ! is_numeric($tp) || ! is_numeric($nextLimit) || ! is_numeric($current)) {
            return '0.0';
        }

        if (bccomp($tp, $nextLimit, 16) === 0) {
            return '0.0';
        }

        $num = bccomp($nextLimit, $tp, 16) >= 0
            ? bcsub($current, $tp, 16)
            : bcsub($tp, $current, 16);

        $den = bccomp($nextLimit, $tp, 16) >= 0
            ? bcsub($nextLimit, $tp, 16)
            : bcsub($tp, $nextLimit, 16);

        if (bccomp($den, '0', 16) === 0) {
            return '0.0';
        }

        $fraction = bcdiv($num, $den, 6);

        // Clamp to [0, 1] — price can sit beyond the rung in either direction.
        if (bccomp($fraction, '0', 16) < 0) {
            $fraction = '0';
        } elseif (bccomp($fraction, '1', 16) > 0) {
            $fraction = '1';
        }

        return number_format((float) bcmul($fraction, '100', 2), 1, '.', '');
    }
}
