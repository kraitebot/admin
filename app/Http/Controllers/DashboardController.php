<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Financial\AccountFinancials;
use Kraite\Core\Support\Financial\Window;
use Kraite\Core\Support\MarketRegime\BlackSwanIndex;

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

        $accountModels = $query
            ->orderBy('user_id')
            ->orderBy('name')
            ->get();

        $accounts = $accountModels
            ->map(fn (Account $account) => [
                'id' => $account->id,
                'name' => $account->name,
                'exchange' => $account->apiSystem?->name ?? 'Unknown',
                'owner' => $account->user?->name ?? 'Unknown',
                'can_trade' => (bool) $account->can_trade,
                'disabled_reason' => $account->disabled_reason,
            ]);

        // First paint renders the same payload the polling endpoint serves,
        // so the page never flashes empty and both paths share one shape.
        $initialAccount = $accountModels->first();

        return view('dashboard', [
            'accounts' => $accounts,
            'isAdmin' => $isAdmin,
            'initialAccountId' => $initialAccount?->id,
            'initialPayload' => $initialAccount ? $this->payload($initialAccount) : null,
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

        return response()->json($this->payload($account));
    }

    /**
     * Full dashboard payload for one account — shared by the first paint
     * (index) and the polling endpoint (data) so both render one shape.
     *
     * @return array<string, mixed>
     */
    private function payload(Account $account): array
    {
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

        return [
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
                'exchange' => $account->apiSystem?->name ?? 'Unknown',
            ],
            'metrics' => $this->accountMetrics($account),
            'kpis' => $this->kpis($account, $positions),
            'btc' => $this->btcStrip(),
            'bscs' => $this->bscsBadge(),
            'positions' => $positions,
            'activity' => $this->activityFeed($account),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Recent bot activity — position lifecycle events only (opens, closes,
     * WAPs). Sync/heartbeat noise is deliberately excluded. Built from the
     * positions + orders tables directly; newest first, capped at 30.
     *
     * @return array<int, array<string, mixed>>
     */
    private function activityFeed(Account $account): array
    {
        $ago = fn ($ts): string => $this->humanAgo($ts) ?? 'just now';

        $events = collect();

        $pushOpen = function ($p, bool $active) use ($events, $ago): void {
            $events->push([
                'kind' => 'OPEN',
                'sort' => (string) $p->opened_at,
                'time' => $ago($p->opened_at),
                'position_id' => (int) $p->id,
                'active' => $active,
                'side' => strtoupper((string) $p->direction),
                'symbol' => $p->parsed_trading_pair,
                'quantity' => $p->quantity !== null ? rtrim(rtrim((string) $p->quantity, '0'), '.') : null,
                'price' => $p->opening_price !== null ? rtrim(rtrim((string) $p->opening_price, '0'), '.') : null,
                'pnl' => null,
            ]);
        };

        $pushWap = function ($o, bool $active) use ($events, $ago): void {
            $events->push([
                'kind' => 'WAP',
                'sort' => (string) $o->filled_at,
                'time' => $ago($o->filled_at),
                'position_id' => (int) $o->position_id,
                'active' => $active,
                'side' => strtoupper((string) $o->direction),
                'symbol' => $o->parsed_trading_pair,
                'quantity' => $o->quantity !== null ? rtrim(rtrim((string) $o->quantity, '0'), '.') : null,
                'price' => $o->price !== null ? rtrim(rtrim((string) $o->price, '0'), '.') : null,
                'pnl' => null,
            ]);
        };

        $openColumns = ['id', 'parsed_trading_pair', 'direction', 'quantity', 'opening_price', 'opened_at'];
        $wapColumns = ['positions.id as position_id', 'positions.parsed_trading_pair', 'positions.direction', 'orders.price', 'orders.quantity', 'orders.filled_at'];

        // OPEN events for currently-open positions — fetched WITHOUT a recency
        // cap. The client's "active only" toggle filters to these, so every
        // open position must be present regardless of how many closed-position
        // opens are newer; capping here would silently drop an active position
        // whose open is older than the 30 most recent opens (heavy churn
        // pushes active opens well past that window).
        DB::table('positions')
            ->where('account_id', $account->id)
            ->whereIn('status', self::OPEN_POSITION_STATUSES)
            ->whereNotNull('opened_at')
            ->orderByDesc('opened_at')
            ->get($openColumns)
            ->each(fn ($p) => $pushOpen($p, true));

        // OPEN events for already-terminal positions — history only, so the
        // recent 30 are enough for the full (toggle-off) view.
        DB::table('positions')
            ->where('account_id', $account->id)
            ->whereNotIn('status', self::OPEN_POSITION_STATUSES)
            ->whereNotNull('opened_at')
            ->orderByDesc('opened_at')
            ->limit(30)
            ->get($openColumns)
            ->each(fn ($p) => $pushOpen($p, false));

        // CLOSE events — clean closes, with price-true realized PnL. Always
        // terminal, so never "active"; recent 30 for the history view.
        DB::table('positions')
            ->where('account_id', $account->id)
            ->where('status', 'closed')
            ->whereNotNull('closed_at')
            ->orderByDesc('closed_at')
            ->limit(30)
            ->get(['id', 'was_waped', 'parsed_trading_pair', 'direction', 'quantity', 'opening_price', 'closing_price', 'closed_at'])
            ->each(function ($p) use ($events, $ago): void {
                $pnl = null;
                if (is_numeric($p->opening_price) && is_numeric($p->closing_price) && is_numeric($p->quantity)) {
                    $delta = strtoupper((string) $p->direction) === 'LONG'
                        ? bcsub((string) $p->closing_price, (string) $p->opening_price, 8)
                        : bcsub((string) $p->opening_price, (string) $p->closing_price, 8);
                    $pnl = number_format((float) bcmul($delta, (string) $p->quantity, 8), 2, '.', '');
                }

                $events->push([
                    'kind' => 'CLOSE',
                    'sort' => (string) $p->closed_at,
                    'time' => $ago($p->closed_at),
                    'position_id' => (int) $p->id,
                    // A closed position is never "active" — but it carries the
                    // waped flag so the row can badge a WAP'd (averaged-down) close.
                    'active' => false,
                    'waped' => (bool) $p->was_waped,
                    'side' => strtoupper((string) $p->direction),
                    'symbol' => $p->parsed_trading_pair,
                    'quantity' => null,
                    'price' => $p->closing_price !== null ? rtrim(rtrim((string) $p->closing_price, '0'), '.') : null,
                    'pnl' => $pnl,
                ]);
            });

        // WAP events for currently-open positions — a filled ladder rung
        // re-anchors the position. Uncapped for the same reason as active
        // opens: the toggle-on view must show every active position's WAPs.
        DB::table('orders')
            ->join('positions', 'positions.id', '=', 'orders.position_id')
            ->where('positions.account_id', $account->id)
            ->whereIn('positions.status', self::OPEN_POSITION_STATUSES)
            ->where('orders.type', 'LIMIT')
            ->where('orders.status', 'FILLED')
            ->whereNotNull('orders.filled_at')
            ->orderByDesc('orders.filled_at')
            ->get($wapColumns)
            ->each(fn ($o) => $pushWap($o, true));

        // WAP events for terminal positions — history only, recent 30.
        DB::table('orders')
            ->join('positions', 'positions.id', '=', 'orders.position_id')
            ->where('positions.account_id', $account->id)
            ->whereNotIn('positions.status', self::OPEN_POSITION_STATUSES)
            ->where('orders.type', 'LIMIT')
            ->where('orders.status', 'FILLED')
            ->whereNotNull('orders.filled_at')
            ->orderByDesc('orders.filled_at')
            ->limit(30)
            ->get($wapColumns)
            ->each(fn ($o) => $pushWap($o, false));

        // Active-position events are ALWAYS kept — they're what the "active
        // only" toggle surfaces and must never be starved. Terminal-position
        // events fill the remaining history up to 30. Without this split the
        // shared 30-row cap let closed-position churn evict active events
        // before the client-side filter ever ran.
        $active = $events->filter(fn (array $e): bool => $e['active']);
        $history = $events->reject(fn (array $e): bool => $e['active'])
            ->sortByDesc('sort')
            ->take(30);

        return $active->merge($history)
            ->sortByDesc('sort')
            ->map(function (array $e): array {
                unset($e['sort']);

                return $e;
            })
            ->values()
            ->all();
    }

    /**
     * Seconds the DB wall clock runs ahead of UTC. Local ingestion stamps
     * rows in the machine's local time while the app runs UTC — measuring
     * the skew straight from the DB corrects every human-readable age
     * (and is zero on a UTC-clocked production DB). Cached per request.
     */
    private ?int $dbClockSkew = null;

    private function dbClockSkew(): int
    {
        // TIMESTAMPDIFF / UTC_TIMESTAMP are MySQL-only; on any other driver
        // (e.g. the SQLite test connection) the skew is both unmeasurable and
        // irrelevant, so treat the clock as aligned.
        if (DB::connection()->getDriverName() !== 'mysql') {
            return 0;
        }

        return $this->dbClockSkew ??= (int) DB::scalar('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())');
    }

    /**
     * Minutes until the next BSCS recompute, humanized. The ingestion app
     * schedules kraite:cron-compute-market-regime hourlyAt(50) — minute-of-
     * hour is timezone-proof, so the countdown needs no skew correction.
     */
    private function nextBscsComputeIn(): string
    {
        $minute = CarbonImmutable::now()->minute;
        $mins = $minute < 50 ? 50 - $minute : 110 - $minute;

        return $mins <= 1 ? 'about now' : "in {$mins}m";
    }

    /**
     * Skew-corrected "Xm ago" for a DB timestamp; residual future stamps
     * still clamp to "just now" rather than showing "X from now".
     */
    private function humanAgo(mixed $ts): ?string
    {
        if ($ts === null) {
            return null;
        }

        $at = CarbonImmutable::parse($ts)->subSeconds($this->dbClockSkew());

        return $at->isFuture() ? 'just now' : $at->diffForHumans(['short' => true]);
    }

    /**
     * Skew-corrected "Xh Ym" until a future DB timestamp (e.g. a cooldown
     * expiry). Returns null when the moment is already past. Mirror of
     * humanAgo for the forward direction; absolute syntax drops the
     * "from now" suffix so callers can phrase it ("resumes in …").
     */
    private function humanUntil(mixed $ts): ?string
    {
        if ($ts === null) {
            return null;
        }

        $at = CarbonImmutable::parse($ts)->subSeconds($this->dbClockSkew());

        if (! $at->isFuture()) {
            return null;
        }

        return $at->diffForHumans([
            'short' => true,
            'parts' => 2,
            'syntax' => CarbonInterface::DIFF_ABSOLUTE,
        ]);
    }

    /**
     * KPI strip — real numbers only:
     *
     *  - Portfolio value: latest wallet snapshot + 24h delta + the recent
     *    snapshot series for the sparkline.
     *  - P&L today / 30d: realized trade PnL via AccountFinancials (the
     *    same engine Projections and the marketing site read), so the
     *    dashboard can never disagree with them. 30d carries a sparkline
     *    of the cumulative daily realized series.
     *  - Open positions: count + long/short split from the live payload.
     *
     * @param  array<int, array<string, mixed>>  $positions
     * @return array<string, mixed>
     */
    private function kpis(Account $account, array $positions): array
    {
        $financials = new AccountFinancials($account);

        // ---- portfolio value + spark + 24h delta ----
        $series = DB::table('account_balance_history')
            ->where('account_id', $account->id)
            ->orderByDesc('id')
            ->limit(30)
            ->pluck('total_wallet_balance')
            ->reverse()
            ->map(fn ($v) => (float) $v)
            ->values()
            ->all();

        $balance = $series === [] ? null : end($series);

        $dayAgo = DB::table('account_balance_history')
            ->where('account_id', $account->id)
            ->where('created_at', '<=', now()->subDay())
            ->orderByDesc('id')
            ->value('total_wallet_balance');

        $balanceDelta24hPct = null;
        if ($balance !== null && $dayAgo !== null && (float) $dayAgo > 0) {
            $balanceDelta24hPct = round((($balance - (float) $dayAgo) / (float) $dayAgo) * 100, 2);
        }

        // ---- realized P&L: today + 30d (trade-PnL sourced) ----
        $todayWindow = Window::today();
        $monthWindow = Window::lastDays(30);

        $pnlToday = $financials->realizedDelta($todayWindow);
        $pnl30d = $financials->realizedDelta($monthWindow);
        $roi30dPct = $financials->realizedRoiPct($monthWindow);

        $todayStart = $financials->startWallet($todayWindow);
        $pnlTodayPct = null;
        if ($pnlToday !== null && $todayStart !== null && bccomp($todayStart, '0', 8) > 0) {
            $pnlTodayPct = round((float) bcmul(bcdiv($pnlToday, $todayStart, 8), '100', 4), 2);
        }

        // 30d sparkline = cumulative realized-per-day series.
        $cumulative = 0.0;
        $pnl30dSpark = [];
        foreach ($financials->dailyRevenues($monthWindow) as $delta) {
            $cumulative += (float) $delta;
            $pnl30dSpark[] = round($cumulative, 2);
        }

        // ---- open positions split ----
        $longCount = count(array_filter($positions, fn (array $p) => $p['direction'] === 'LONG'));

        return [
            'balance' => $balance !== null ? number_format($balance, 2, '.', '') : null,
            'balance_delta_24h_pct' => $balanceDelta24hPct,
            'balance_spark' => $series,
            'pnl_today' => $pnlToday !== null ? number_format((float) $pnlToday, 2, '.', '') : null,
            'pnl_today_pct' => $pnlTodayPct,
            'pnl_30d' => $pnl30d !== null ? number_format((float) $pnl30d, 2, '.', '') : null,
            'pnl_30d_pct' => $roi30dPct !== null ? round($roi30dPct, 2) : null,
            'pnl_30d_spark' => $pnl30dSpark,
            'open_count' => count($positions),
            'long_count' => $longCount,
            'short_count' => count($positions) - $longCount,
        ];
    }

    /**
     * Compact BSCS payload for the user dashboard — score + band + a single
     * status line. The full sub-signal grid lives on the system dashboard;
     * end-users just need the posture signal ("are new opens flowing or
     * paused"). Falls back to a calm-display when no compute has landed yet.
     *
     * @return array<string, mixed>
     */
    private function bscsBadge(): array
    {
        $index = BlackSwanIndex::current();
        $score = $index->score();
        $band = $index->band()?->value;
        $blocked = $index->shouldBlockOpens();
        $cooldownUntil = $index->cooldownUntil();

        // Inferred pause source. The fast 1-minute market-shock detector
        // arms the same cooldown the slow score gate uses, even when the
        // score is nowhere near the block threshold — so a cooldown active
        // with a sub-threshold score is a SHOCK cooldown; at/above the
        // threshold it's the regime gate. (No persisted reason column.)
        $pauseReason = null;
        if ($blocked) {
            // Regime gate is the cause ONLY when a computed score is at/above
            // the block threshold (the only way the slow gate arms a cooldown).
            // Everything else — sub-threshold score, or no score yet — can only
            // be the fast shock breaker, so it defaults to 'shock'.
            $pauseReason = ($score !== null && $score >= $index->blockThreshold()) ? 'regime' : 'shock';
        }

        $statusLine = match (true) {
            $score === null => 'Awaiting first compute…',
            $blocked => 'New trades paused.',
            $band === 'critical' => 'Market in critical regime.',
            $band === 'fragile' => 'New trades use smaller size.',
            $band === 'elevated' => 'Market moving more than usual.',
            default => 'Market normal.',
        };

        // Sub-signal grid from the latest snapshot. Raw values live on
        // heterogeneous scales (ratios, percentages, z-scores), so the card
        // renders the FIRED state as the visual signal and the raw value as
        // the mono figure — no fake normalisation into a 0–1 bar.
        $snapshot = $index->latestSnapshot();
        $components = [];

        if ($snapshot) {
            $signalMap = [
                'vol_expansion' => 'Vol expansion',
                'range_blowout' => 'Range blowout',
                'corr_regime' => 'Correlation regime',
                'rejection_pct' => 'Rejection %',
                'fut_vol' => 'Futures vol',
            ];

            foreach ($signalMap as $key => $label) {
                $value = $snapshot->{$key.'_value'};
                $components[] = [
                    'label' => $label,
                    'value' => $value !== null ? round((float) $value, 2) : null,
                    'fired' => (bool) $snapshot->{$key.'_fired'},
                ];
            }
        }

        return [
            'score' => $score,
            'band' => $band,
            'blocked' => $blocked,
            // Cooldown / market-shock surface — when opens are paused and
            // until when. pause_reason is 'shock' (fast 1-min breaker) or
            // 'regime' (slow score gate at ≥ block_threshold).
            'pause_reason' => $pauseReason,
            'cooldown_active' => $index->isCooldownActive(),
            'cooldown_remaining' => $this->humanUntil($cooldownUntil),
            'cooldown_until' => $cooldownUntil ? $cooldownUntil->subSeconds($this->dbClockSkew())->toIso8601String() : null,
            'status' => $statusLine,
            'is_stale' => $index->isStale(),
            'block_threshold' => $index->blockThreshold(),
            'computed_at' => $snapshot?->computed_at ? CarbonImmutable::parse($snapshot->computed_at)->toIso8601String() : null,
            'computed_ago' => $this->humanAgo($snapshot?->computed_at),
            'next_compute_in' => $this->nextBscsComputeIn(),
            'components' => $components,
        ];
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
        $maintMargin = (string) ($latest->total_maintenance_margin ?? '0');

        $marginRatio = (is_numeric($marginBalance) && bccomp($marginBalance, '0', 16) > 0)
            ? number_format((float) bcmul(bcdiv($maintMargin, $marginBalance, 6), '100', 4), 2, '.', '')
            : null;

        // Max historical drawdown = (peak - trough) / peak. Reads as "the
        // worst dip the account has ever taken". Peak-vs-current would
        // show 0% when the live margin happens to match the historic high
        // (common when the account has been monotonically growing or the
        // latest write coincides with the previous max), masking the
        // risk-history signal an operator wants on the dashboard.
        $extremes = DB::table('account_balance_history')
            ->where('account_id', $account->id)
            ->selectRaw('MAX(total_margin_balance) AS peak, MIN(total_margin_balance) AS trough')
            ->first();

        $drawdownPct = null;
        if ($extremes && $extremes->peak !== null && is_numeric($extremes->peak) && bccomp((string) $extremes->peak, '0', 16) > 0) {
            $delta = bcsub((string) $extremes->peak, (string) ($extremes->trough ?? $extremes->peak), 16);
            $drawdownPct = number_format((float) bcmul(bcdiv($delta, (string) $extremes->peak, 6), '100', 4), 2, '.', '');
        }

        return [
            // USDT amounts → 2 decimals to match exchange convention.
            'balance' => number_format((float) $latest->total_wallet_balance, 2, '.', ''),
            'pnl' => number_format((float) $latest->total_unrealized_profit, 2, '.', ''),
            'drawdown_pct' => $drawdownPct,
            'margin_ratio' => $marginRatio,
            'is_stub' => false,
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
        $mark = $this->markOrLastClose((int) $btc->es_id, $btc->mark_price !== null ? (string) $btc->mark_price : null);

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
     * @param  Position  $position
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
        $currentPrice = $this->markOrLastClose(
            (int) $position->exchange_symbol_id,
            $exchangeSymbol?->mark_price !== null ? (string) $exchangeSymbol->mark_price : null,
        );

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
                'price_raw' => (string) $order->price,
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
        $alphaPathPct = $this->computeAlphaPathPercent($firstProfit, $lastLimit, $currentPrice);
        $alphaLimitPct = $this->computeAlphaLimitPercent($currentTp, $nextLimit, $currentPrice);

        $quantity = (string) ($position->quantity ?? '0');
        $size = $this->computeSize($quantity, $currentPrice);
        $pnl = $position->unrealizedPnl();

        $openingPrice = $position->opening_price !== null ? (string) $position->opening_price : null;

        // After a WAP the original opening_price no longer reflects the
        // blended entry the TP is computed against — show the weighted-
        // average entry instead so "entry → TP" reads the right direction.
        $wasWaped = (bool) $position->was_waped;
        $wap = $this->computeWap($position);
        $entryPrice = $wasWaped && $wap !== null ? $wap : $openingPrice;

        // Lifecycle-track geometry — the design's stage grammar, not a
        // price-proportional scale (real ladders cluster the markers into
        // an unreadable left-edge pile). See trackGeometry().
        $track = $this->trackGeometry($filledCount, $totalLimits, (float) $alphaLimitPct);

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
            'age_human' => $this->humanAgo($position->opened_at ?? $position->created_at),

            // 24h % stays stubbed — needs a separate candles aggregate.
            'var_24h_pct' => null,
            'timeframe_dots' => $this->buildTimeframeDots(
                $currentPrice,
                $candleOpensByExchangeSymbol[$position->exchange_symbol_id] ?? [],
            ),

            'side' => strtolower((string) $position->direction),

            'current_price' => $fmtPrice($currentPrice),
            'opening_price' => $fmtPrice($openingPrice),
            'wap_price' => $fmtPrice($wap),
            'was_waped' => $wasWaped,
            // The entry the tile shows: blended WAP once the position has
            // averaged down, the original open otherwise.
            'entry_label' => $wasWaped ? 'WAP' : 'Open',
            'entry_price' => $fmtPrice($entryPrice),
            'first_profit_price' => $fmtPrice($firstProfit),
            'profit_price' => $fmtPrice($currentTp),
            'next_limit_price' => $fmtPrice($nextLimit),
            'last_limit_price' => $fmtPrice($lastLimit),

            'track' => $track,

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
     * Live mark price with a candle-close fallback. The mark feed only runs
     * where ingestion's WS streams are active (production); on environments
     * without it, the latest close on the shortest engine timeframe is the
     * freshest price on record — same semantic class, slightly staler.
     */
    private function markOrLastClose(int $exchangeSymbolId, ?string $mark): ?string
    {
        if ($mark !== null && is_numeric($mark)) {
            return $mark;
        }

        $close = DB::table('candles')
            ->where('exchange_symbol_id', $exchangeSymbolId)
            ->orderByDesc('timestamp')
            ->value('close');

        return $close !== null ? (string) $close : null;
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

    /**
     * Lifecycle-track geometry in the design's STAGE grammar (decoded from
     * the design mock data, where the markers advance by lifecycle stage
     * rather than by price distance — real ladder prices sit so close to
     * the mark that a price-proportional scale piles every marker onto the
     * left edge):
     *
     *  - Ladder rungs occupy fixed slots spread across 26%…80%; rungs that
     *    have FILLED disappear from the ladder.
     *  - TP starts hard-left (0% — a fresh position's first TP). Each WAP
     *    (filled rung) slides it right to that rung's slot; the tile draws
     *    a trace from 0% to the current TP so the slide stays visible.
     *  - PX marker travels from TP toward the NEXT pending rung's slot,
     *    proportional to alpha_limit (which measures exactly that leg).
     *
     * @return array{tp_pct: float, px_pct: float, gain_left: float, gain_width: float, rungs: array<int, array<string, mixed>>}|null
     */
    private function trackGeometry(int $filledCount, int $totalLimits, float $alphaLimitPct): ?array
    {
        if ($totalLimits < 1) {
            return null;
        }

        $slot = function (int $index) use ($totalLimits): float {
            if ($totalLimits === 1) {
                return 53.0;
            }

            return round(26.0 + ($index - 1) * (54.0 / ($totalLimits - 1)), 1);
        };

        $tp = $filledCount === 0 ? 0.0 : $slot(min($filledCount, $totalLimits));
        $nextAnchor = $filledCount < $totalLimits ? $slot($filledCount + 1) : 92.0;

        $fraction = max(0.0, min(100.0, $alphaLimitPct)) / 100.0;
        $px = round($tp + ($nextAnchor - $tp) * $fraction, 1);

        $rungs = [];
        for ($i = $filledCount + 1; $i <= $totalLimits; $i++) {
            $rungs[] = ['index' => $i, 'pct' => $slot($i)];
        }

        return [
            'tp_pct' => $tp,
            'px_pct' => $px,
            'gain_left' => round(min($tp, $px), 1),
            'gain_width' => round(abs($px - $tp), 1),
            'rungs' => $rungs,
        ];
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
     * Weighted-average entry price (WAP) across every FILLED entry fill —
     * the MARKET open plus any filled LIMIT ladder rungs. Exit orders
     * (PROFIT-LIMIT, STOP-MARKET) are excluded by type, so the entry side
     * is captured without needing to branch on direction. Returns null
     * when there are no filled entries to average.
     */
    private function computeWap(Position $position): ?string
    {
        $numerator = '0';
        $quantitySum = '0';

        foreach ($position->orders as $order) {
            if (! in_array(strtoupper((string) $order->type), ['MARKET', 'LIMIT'], true)) {
                continue;
            }

            if (strtoupper((string) $order->status) !== 'FILLED') {
                continue;
            }

            if (! is_numeric($order->price) || ! is_numeric($order->quantity)) {
                continue;
            }

            $numerator = bcadd($numerator, bcmul((string) $order->price, (string) $order->quantity, 16), 16);
            $quantitySum = bcadd($quantitySum, (string) $order->quantity, 16);
        }

        if (bccomp($quantitySum, '0', 16) === 0) {
            return null;
        }

        return bcdiv($numerator, $quantitySum, 8);
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
