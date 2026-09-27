<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\MarketRegimeSnapshot;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Support\Connectivity\AccountServerConnectivityService;
use Kraite\Core\Support\Financial\AccountFinancials;
use Kraite\Core\Support\Financial\DayBasisLocationHint;
use Kraite\Core\Support\Financial\ReportingDay;
use Kraite\Core\Support\Financial\Window;
use Kraite\Core\Support\MarketRegime\Bscs;
use Kraite\Core\Support\MarketRegime\BscsAccountContext;
use Kraite\Core\Support\MarketRegime\BscsState;

/**
 * @phpstan-type ActivityEvent array{
 *     kind: string,
 *     sort: string,
 *     time: string,
 *     position_id: int,
 *     active: bool,
 *     waped?: bool,
 *     side: string,
 *     symbol: ?string,
 *     quantity: ?string,
 *     price: ?string,
 *     pnl: ?string
 * }
 * @phpstan-type FinalActivityEvent array{
 *     kind: string,
 *     time: string,
 *     position_id: int,
 *     active: bool,
 *     waped?: bool,
 *     side: string,
 *     symbol: ?string,
 *     quantity: ?string,
 *     price: ?string,
 *     pnl: ?string
 * }
 * @phpstan-type PositionLimit array{
 *     index: int,
 *     price: ?string,
 *     price_raw: string,
 *     quantity: string,
 *     status: string,
 *     filled: bool
 * }
 * @phpstan-type TrackGeometry array{
 *     tp_pct: float,
 *     px_pct: float,
 *     sl_pct: float,
 *     gain_left: float,
 *     gain_width: float,
 *     rungs: array<int, array{index: int, pct: float}>
 * }
 * @phpstan-type PositionContext array{
 *     current_price: ?string,
 *     first_profit: ?string,
 *     last_limit: ?string,
 *     current_tp: ?string,
 *     next_limit: ?string,
 *     stop_loss: ?string,
 *     limits: array<int, PositionLimit>,
 *     total_limits: int,
 *     filled_count: int,
 *     alpha_path_pct: string,
 *     alpha_limit_pct: string,
 *     track: ?TrackGeometry,
 *     direction: string,
 *     take_profit_distance_pct: ?string,
 *     next_limit_distance_pct: ?string,
 *     quantity: string,
 *     size: ?string,
 *     pnl: ?string,
 *     max_pain: int|float|string|null,
 *     opening_price: ?string,
 *     was_waped: bool,
 *     wap: ?string,
 *     entry_price: ?string
 * }
 * @phpstan-type PositionHeaderPayload array{
 *     id: int,
 *     status: string,
 *     symbol: string,
 *     token: ?string,
 *     token_name: ?string,
 *     token_image: ?string,
 *     direction: string,
 *     leverage: int,
 *     opened_at: ?string,
 *     age_human: ?string,
 *     var_24h_pct: null,
 *     timeframe_dots: array<int, array{timeframe: string, direction: string}>,
 *     spark_4h: array<int, float>,
 *     side: string
 * }
 * @phpstan-type PositionPricePayload array{
 *     current_price: ?string,
 *     opening_price: ?string,
 *     wap_price: ?string,
 *     was_waped: bool,
 *     entry_label: string,
 *     entry_price: ?string,
 *     first_profit_price: ?string,
 *     profit_price: ?string,
 *     take_profit_distance_pct: ?string,
 *     next_limit_price: ?string,
 *     next_limit_distance_pct: ?string,
 *     last_limit_price: ?string,
 *     stop_loss_price: ?string
 * }
 * @phpstan-type PositionProgressPayload array{
 *     track: ?TrackGeometry,
 *     alpha_path_pct: string,
 *     alpha_limit_pct: string
 * }
 * @phpstan-type PositionRiskPayload array{
 *     size: ?string,
 *     pnl: ?string,
 *     max_pain: int|float|string|null,
 *     max_pain_formatted: ?string
 * }
 * @phpstan-type PositionLimitPayload array{
 *     filled_count: int,
 *     total_limits: int,
 *     limits: array<int, PositionLimit>
 * }
 * @phpstan-type BscsPayload array{
 *     score: ?int,
 *     band: ?string,
 *     blocked: bool,
 *     pause_reason: ?string,
 *     paused: bool,
 *     openings_switch_enabled: bool,
 *     cooldown_active: bool,
 *     cooldown_remaining: ?string,
 *     cooldown_until: ?string,
 *     status: string,
 *     is_stale: bool,
 *     block_threshold: int,
 *     computed_at: ?string,
 *     computed_ago: ?string,
 *     next_compute_in: string,
 *     components: list<array{key: string, label: string, value: ?float, fired: bool}>,
 *     position_cap: array{long: array{effective: int, maximum: int}, short: array{effective: int, maximum: int}, ratio_percent: int}
 * }
 * @phpstan-type PositionPayload array{
 *     id: int,
 *     status: string,
 *     symbol: string,
 *     token: ?string,
 *     token_name: ?string,
 *     token_image: ?string,
 *     direction: string,
 *     leverage: int,
 *     opened_at: ?string,
 *     age_human: ?string,
 *     var_24h_pct: null,
 *     timeframe_dots: array<int, array{timeframe: string, direction: string}>,
 *     spark_4h: array<int, float>,
 *     side: string,
 *     current_price: ?string,
 *     opening_price: ?string,
 *     wap_price: ?string,
 *     was_waped: bool,
 *     entry_label: string,
 *     entry_price: ?string,
 *     first_profit_price: ?string,
 *     profit_price: ?string,
 *     take_profit_distance_pct: ?string,
 *     next_limit_price: ?string,
 *     next_limit_distance_pct: ?string,
 *     last_limit_price: ?string,
 *     stop_loss_price: ?string,
 *     track: ?TrackGeometry,
 *     alpha_path_pct: string,
 *     alpha_limit_pct: string,
 *     size: ?string,
 *     pnl: ?string,
 *     max_pain: int|float|string|null,
 *     max_pain_formatted: ?string,
 *     filled_count: int,
 *     total_limits: int,
 *     limits: array<int, PositionLimit>
 * }
 */
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
        $accountModels = Account::with(['apiSystem', 'user.subscription'])
            ->where('user_id', Auth::id())
            ->orderBy('user_id')
            ->orderBy('name')
            ->get();

        $accounts = $this->accountOptions($accountModels);

        // First paint renders the same payload the polling endpoint serves,
        // so the page never flashes empty and both paths share one shape.
        $initialAccount = $accountModels->first();

        return view('dashboard', [
            'accounts' => $accounts,
            'initialAccountId' => $initialAccount?->id,
            'initialPayload' => $initialAccount ? $this->payload($initialAccount) : null,
            // The engine's configured spans, longest first — the same order
            // the direction dots render in, so the help text can name them
            // instead of describing them vaguely.
            'dotTimeframes' => $this->dotTimeframes(),
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

        $account = Account::query()
            ->where('id', $request->input('account_id'))
            ->where('user_id', Auth::id())
            ->firstOrFail();

        return response()->json($this->payload($account));
    }

    /**
     * Fire the engine's connectivity workflow for one of the caller's OWN
     * accounts (saved credentials, one test per apiable server). Owner-scoped
     * exactly like data() — the core API route's policy gate expects the
     * core User model and rejects this app's authenticated user outright,
     * so the dashboard drives the service through this thin endpoint
     * instead. Polling reuses accounts.connectivity.status (same ownership
     * rule, no policy involved).
     */
    public function startConnectivity(Request $request, AccountServerConnectivityService $connectivity): JsonResponse
    {
        $request->validate([
            'account_id' => ['required', 'integer'],
        ]);

        $account = Account::query()
            ->where('id', $request->input('account_id'))
            ->where('user_id', Auth::id())
            ->firstOrFail();

        return response()->json($connectivity->start($account));
    }

    /**
     * Full dashboard payload for one account — shared by the first paint
     * (index) and the polling endpoint (data) so both render one shape.
     *
     * @return array<string, mixed>
     */
    private function payload(Account $account): array
    {
        $positions = $this->openPositions($account);

        return [
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
                'exchange' => $account->apiSystem?->name ?? 'Unknown',
            ],
            'metrics' => $this->accountMetrics($account),
            'kpis' => $this->webKpis($account, $positions),
            'btc' => $this->btcStrip(),
            'bscs' => $this->bscsBadge($account),
            'last_position_closed_at' => $this->lastPositionClosedAt($account),
            'positions' => $positions,
            'activity' => $this->activityFeed($account),
            'connectivity_servers' => $this->connectivityServers(),
            'day_basis_hint' => $this->dayBasisHint(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * A one-time offer to move the trading day basis after the trader shows up
     * in a different country. Never changes anything on its own — the basis is
     * there to match their exchange, which does not travel with them.
     *
     * The country comes from the CDN edge, which sees the real client IP; the
     * origin only ever sees Cloudflare's.
     *
     * @return array<string, mixed>|null
     */
    private function dayBasisHint(): ?array
    {
        $user = Auth::user();

        if ($user === null) {
            return null;
        }

        $hint = DayBasisLocationHint::for($user, request()->header('CF-IPCountry'));

        return $hint?->toArray();
    }

    /**
     * Bounded read-only payload for the first-party mobile dashboard.
     * Deliberately omits activity and connectivity. BTC and BSCS are included
     * as bounded market-context summaries for the mobile dashboard.
     *
     * @return array<string, mixed>
     */
    public function mobilePayload(Account $account): array
    {
        $positions = $this->openPositions($account);

        return [
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
                'exchange' => $account->apiSystem?->name ?? 'Unknown',
            ],
            'kpis' => $this->kpis($account, $positions),
            'day_basis_hint' => $this->dayBasisHint(),
            'btc' => $this->btcStrip(),
            'bscs' => $this->mobileBscsBadge($account),
            'last_position_closed_at' => $this->lastPositionClosedAt($account),
            'positions' => $positions,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Account>  $accounts
     * @return Collection<int, array{id: int, name: string, exchange: string, owner: string, is_trading: bool, disabled_reason: ?string}>
     */
    private function accountOptions(Collection $accounts): Collection
    {
        return $accounts->map(fn (Account $account): array => [
            'id' => $account->id,
            'name' => $account->name,
            'exchange' => $account->apiSystem?->name ?? 'Unknown',
            'owner' => $account->user?->name ?? 'Unknown',
            'is_trading' => $account->isReadyToTrade(),
            'disabled_reason' => $account->disabled_reason === null ? null : (string) $account->disabled_reason,
        ]);
    }

    private function lastPositionClosedAt(Account $account): ?string
    {
        return $account->positions()
            ->where('status', 'closed')
            ->whereNotNull('closed_at')
            ->latest('closed_at')
            ->first(['closed_at'])
            ?->closed_at
            ?->toIso8601String();
    }

    /** @return array<int, PositionPayload> */
    private function openPositions(Account $account): array
    {
        $rawPositions = $account->positions()
            ->whereIn('status', self::OPEN_POSITION_STATUSES)
            ->with(['exchangeSymbol.symbol', 'orders'])
            ->orderBy('opened_at')
            ->get();

        $exchangeSymbolIds = $rawPositions->pluck('exchange_symbol_id')->filter()->unique()->all();
        $candleOpensByExchangeSymbol = $this->loadCandleOpens($exchangeSymbolIds);
        $fourHourSparksByExchangeSymbol = $this->loadFourHourSparks($exchangeSymbolIds);

        // Sort by worst AlphaLimit first — high alpha_limit_pct = price
        // is closest to filling the next ladder rung, which is what the
        // operator most wants to see at the top of the grid.
        return $rawPositions
            ->map(fn ($position) => $this->serializePosition(
                $position,
                $candleOpensByExchangeSymbol,
                $fourHourSparksByExchangeSymbol,
            ))
            ->sortByDesc(fn (array $p): float => (float) $p['alpha_limit_pct'])
            ->values()
            ->all();
    }

    /**
     * The apiable-server roster the connectivity card lists in its idle
     * state — the same fleet the on-demand check fans out to (the check
     * itself runs through the engine's existing connectivity workflow via
     * the core API endpoints; this is just the "what will be tested" list).
     *
     * @return array<int, array<string, mixed>>
     */
    private function connectivityServers(): array
    {
        // Fleet-global and near-static, but the payload is polled every 10s
        // per open tab — a short cache keeps the roster query off that path.
        return rescue(
            fn (): array => Cache::remember(
                'dashboard.connectivity-servers',
                60,
                fn (): array => app(AccountServerConnectivityService::class)
                    ->apiConnectivityServers()
                    ->map(fn ($server): array => [
                        'id' => (int) $server->id,
                        'hostname' => $server->hostname,
                        'ip_address' => $server->ip_address,
                    ])
                    ->values()
                    ->all(),
            ),
            [],
        );
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
        $events = collect();
        $this->appendActiveOpenActivityEvents($events, $account);
        $this->appendHistoryOpenActivityEvents($events, $account);
        $this->appendClosedActivityEvents($events, $account);
        $this->appendActiveWapActivityEvents($events, $account);
        $this->appendHistoryWapActivityEvents($events, $account);

        return $this->finalizeActivityEvents($events);
    }

    /** @param  Collection<int, ActivityEvent>  $events */
    private function appendActiveOpenActivityEvents(Collection $events, Account $account): void
    {
        DB::table('positions')
            ->where('account_id', $account->id)
            ->whereIn('status', self::OPEN_POSITION_STATUSES)
            ->whereNotNull('opened_at')
            ->orderByDesc('opened_at')
            ->get($this->activityOpenColumns())
            ->each(fn (\stdClass $position): Collection => $events->push($this->openActivityEvent($position, true)));
    }

    /** @param  Collection<int, ActivityEvent>  $events */
    private function appendHistoryOpenActivityEvents(Collection $events, Account $account): void
    {
        DB::table('positions')
            ->where('account_id', $account->id)
            ->whereNotIn('status', self::OPEN_POSITION_STATUSES)
            ->whereNotNull('opened_at')
            ->orderByDesc('opened_at')
            ->limit(30)
            ->get($this->activityOpenColumns())
            ->each(fn (\stdClass $position): Collection => $events->push($this->openActivityEvent($position, false)));
    }

    /** @param  Collection<int, ActivityEvent>  $events */
    private function appendClosedActivityEvents(Collection $events, Account $account): void
    {
        DB::table('positions')
            ->where('account_id', $account->id)
            ->where('status', 'closed')
            ->whereNotNull('closed_at')
            ->orderByDesc('closed_at')
            ->limit(30)
            ->get(['id', 'pnl', 'was_waped', 'parsed_trading_pair', 'direction', 'quantity', 'opening_price', 'closing_price', 'closed_at'])
            ->each(fn (\stdClass $position): Collection => $events->push($this->closedActivityEvent($position)));
    }

    /** @param  Collection<int, ActivityEvent>  $events */
    private function appendActiveWapActivityEvents(Collection $events, Account $account): void
    {
        DB::table('orders')
            ->join('positions', 'positions.id', '=', 'orders.position_id')
            ->where('positions.account_id', $account->id)
            ->whereIn('positions.status', self::OPEN_POSITION_STATUSES)
            ->where('orders.type', 'LIMIT')
            ->where('orders.status', 'FILLED')
            ->whereNotNull('orders.filled_at')
            ->orderByDesc('orders.filled_at')
            ->get($this->activityWapColumns())
            ->each(fn (\stdClass $order): Collection => $events->push($this->wapActivityEvent($order, true)));
    }

    /** @param  Collection<int, ActivityEvent>  $events */
    private function appendHistoryWapActivityEvents(Collection $events, Account $account): void
    {
        DB::table('orders')
            ->join('positions', 'positions.id', '=', 'orders.position_id')
            ->where('positions.account_id', $account->id)
            ->whereNotIn('positions.status', self::OPEN_POSITION_STATUSES)
            ->where('orders.type', 'LIMIT')
            ->where('orders.status', 'FILLED')
            ->whereNotNull('orders.filled_at')
            ->orderByDesc('orders.filled_at')
            ->limit(30)
            ->get($this->activityWapColumns())
            ->each(fn (\stdClass $order): Collection => $events->push($this->wapActivityEvent($order, false)));
    }

    /** @return list<string> */
    private function activityOpenColumns(): array
    {
        return ['id', 'parsed_trading_pair', 'direction', 'quantity', 'opening_price', 'opened_at'];
    }

    /** @return list<string> */
    private function activityWapColumns(): array
    {
        return ['positions.id as position_id', 'positions.parsed_trading_pair', 'positions.direction', 'orders.price', 'orders.quantity', 'orders.filled_at'];
    }

    /** @return ActivityEvent */
    private function openActivityEvent(\stdClass $position, bool $active): array
    {
        return [
            'kind' => 'OPEN',
            'sort' => (string) $position->opened_at,
            'time' => $this->activityTime($position->opened_at),
            'position_id' => (int) $position->id,
            'active' => $active,
            'side' => strtoupper((string) $position->direction),
            'symbol' => $position->parsed_trading_pair,
            'quantity' => $this->trimActivityNumber($position->quantity),
            'price' => $this->trimActivityNumber($position->opening_price),
            'pnl' => null,
        ];
    }

    /** @return ActivityEvent */
    private function wapActivityEvent(\stdClass $order, bool $active): array
    {
        return [
            'kind' => 'WAP',
            'sort' => (string) $order->filled_at,
            'time' => $this->activityTime($order->filled_at),
            'position_id' => (int) $order->position_id,
            'active' => $active,
            'side' => strtoupper((string) $order->direction),
            'symbol' => $order->parsed_trading_pair,
            'quantity' => $this->trimActivityNumber($order->quantity),
            'price' => $this->trimActivityNumber($order->price),
            'pnl' => null,
        ];
    }

    /** @return ActivityEvent */
    private function closedActivityEvent(\stdClass $position): array
    {
        return [
            'kind' => 'CLOSE',
            'sort' => (string) $position->closed_at,
            'time' => $this->activityTime($position->closed_at),
            'position_id' => (int) $position->id,
            'active' => false,
            'waped' => (bool) $position->was_waped,
            'side' => strtoupper((string) $position->direction),
            'symbol' => $position->parsed_trading_pair,
            'quantity' => null,
            'price' => $this->trimActivityNumber($position->closing_price),
            'pnl' => $this->closedPositionPnl($position),
        ];
    }

    private function activityTime(CarbonInterface|string|null $timestamp): string
    {
        return $this->humanAgo($timestamp) ?? 'just now';
    }

    private function trimActivityNumber(int|float|string|null $value): ?string
    {
        return $value === null ? null : rtrim(rtrim((string) $value, '0'), '.');
    }

    private function closedPositionPnl(\stdClass $position): ?string
    {
        if (is_numeric($position->pnl)) {
            return number_format((float) $position->pnl, 2, '.', '');
        }

        $values = $this->numericCloseValues($position);
        if ($values === null) {
            return null;
        }

        $delta = strtoupper((string) $position->direction) === 'LONG'
            ? bcsub($values['closing_price'], $values['opening_price'], 8)
            : bcsub($values['opening_price'], $values['closing_price'], 8);

        return number_format((float) bcmul($delta, $values['quantity'], 8), 2, '.', '');
    }

    /**
     * @return array{opening_price: numeric-string, closing_price: numeric-string, quantity: numeric-string}|null
     */
    private function numericCloseValues(\stdClass $position): ?array
    {
        $openingPrice = (string) $position->opening_price;
        $closingPrice = (string) $position->closing_price;
        $quantity = (string) $position->quantity;

        if (! $this->isNumericPrice($openingPrice)
            || ! $this->isNumericPrice($closingPrice)
            || ! $this->isNumericPrice($quantity)) {
            return null;
        }

        return [
            'opening_price' => $openingPrice,
            'closing_price' => $closingPrice,
            'quantity' => $quantity,
        ];
    }

    /**
     * @param  Collection<int, ActivityEvent>  $events
     * @return array<int, FinalActivityEvent>
     */
    private function finalizeActivityEvents(Collection $events): array
    {
        $active = $events->filter(fn (array $event): bool => $event['active']);
        $history = $events->reject(fn (array $event): bool => $event['active'])
            ->sortByDesc('sort')
            ->take(30);

        return $active->merge($history)
            ->sortByDesc('sort')
            ->map(function (array $event): array {
                unset($event['sort']);

                return $event;
            })
            ->values()
            ->all();
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
     * "Xm ago" for a DB timestamp. The connection is pinned to UTC and all
     * engine timestamps are true UTC, so no skew correction is needed. A
     * genuinely future stamp renders as "X from now" — surfaced, not masked —
     * so a clock/timezone regression can't silently read "just now" again.
     */
    private function humanAgo(mixed $ts): ?string
    {
        if ($ts === null) {
            return null;
        }

        return CarbonImmutable::parse($ts)->diffForHumans(['short' => true]);
    }

    /**
     * "Xh Ym" until a future DB timestamp (e.g. a cooldown expiry). Returns
     * null when the moment is already past. Mirror of humanAgo for the forward
     * direction; absolute syntax drops the "from now" suffix so callers can
     * phrase it ("resumes in …").
     */
    private function humanUntil(mixed $ts): ?string
    {
        if ($ts === null) {
            return null;
        }

        $at = CarbonImmutable::parse($ts);

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
     *  - Portfolio value: latest wallet snapshot + the recent snapshot
     *    series for the sparkline. Its 24h delta is trade-driven, not
     *    wallet-driven, so transferred money never reads as a gain.
     *  - P&L today / 30d: realized trade PnL via AccountFinancials (the
     *    same engine Projections and the marketing site read), so the
     *    dashboard can never disagree with them. 30d carries a sparkline
     *    of the cumulative daily realized series.
     *  - Open positions: count + long/short split from the live payload.
     *
     * @param  array<int, PositionPayload>  $positions
     * @return array<string, mixed>
     */
    private function kpis(Account $account, array $positions): array
    {
        $financials = new AccountFinancials($account);
        $now = CarbonImmutable::now();

        return [
            ...$this->portfolioKpis($account, $financials, $now),
            ...$this->realizedKpis($financials, $now),
            ...$this->positionCountKpis($positions),
        ];
    }

    /** @return array{balance: ?string, balance_delta_24h_pct: ?float, balance_spark: array<int, float>} */
    private function portfolioKpis(Account $account, AccountFinancials $financials, CarbonImmutable $now): array
    {
        $series = $this->balanceSeries($account);
        $balance = $series === [] ? null : end($series);
        $dayAgo = $this->balanceDayAgo($account, $now);
        $pnl24h = $financials->realizedDelta(Window::between($now->subDay(), $now));

        return [
            'balance' => $balance !== null ? number_format($balance, 2, '.', '') : null,
            'balance_delta_24h_pct' => $this->balanceDelta24hPct($pnl24h, $dayAgo),
            'balance_spark' => $series,
        ];
    }

    /** @return array<int, float> */
    private function balanceSeries(Account $account): array
    {
        return DB::table('account_balance_history')
            ->where('account_id', $account->id)
            ->orderByDesc('id')
            ->limit(30)
            ->pluck('total_wallet_balance')
            ->reverse()
            ->map(fn ($value): float => (float) $value)
            ->values()
            ->all();
    }

    private function balanceDayAgo(Account $account, CarbonImmutable $now): int|float|string|null
    {
        return DB::table('account_balance_history')
            ->where('account_id', $account->id)
            ->where('created_at', '<=', $now->subDay())
            ->orderByDesc('id')
            ->value('total_wallet_balance');
    }

    private function balanceDelta24hPct(?string $pnl24h, int|float|string|null $dayAgo): ?float
    {
        if ($pnl24h === null || ! $this->isPositiveNumber($dayAgo)) {
            return null;
        }

        return round((float) bcmul(bcdiv($pnl24h, (string) $dayAgo, 8), '100', 4), 2);
    }

    private function isPositiveNumber(int|float|string|null $value): bool
    {
        return $value !== null && is_numeric($value) && (float) $value > 0;
    }

    /** @return array{pnl_today: ?string, pnl_today_pct: ?float, pnl_yesterday: ?string, pnl_30d: ?string, pnl_30d_pct: ?float, pnl_30d_spark: array<int, float>} */
    private function realizedKpis(AccountFinancials $financials, CarbonImmutable $now): array
    {
        $basis = $financials->reportingDay();
        $todayWindow = Window::today($now, $basis);
        $yesterdayWindow = Window::between(
            $todayWindow->start->subDay(),
            $todayWindow->end->subDay(),
        );
        $monthWindow = Window::lastDays(30, $now, $basis);

        $pnlToday = $financials->realizedDelta($todayWindow);
        $pnlYesterday = $financials->realizedDelta($yesterdayWindow);
        $pnl30d = $financials->realizedDelta($monthWindow);
        $roi30dPct = $financials->realizedRoiPct($monthWindow);

        return [
            'pnl_today' => $pnlToday !== null ? number_format((float) $pnlToday, 2, '.', '') : null,
            'pnl_today_pct' => $this->pnlPercentage($pnlToday, $financials->startWallet($todayWindow)),
            'pnl_yesterday' => $pnlYesterday !== null ? bcround($pnlYesterday, precision: 2) : null,
            'pnl_30d' => $pnl30d !== null ? number_format((float) $pnl30d, 2, '.', '') : null,
            'pnl_30d_pct' => $roi30dPct !== null ? round($roi30dPct, 2) : null,
            'pnl_30d_spark' => $this->cumulativeRevenueSpark($financials->dailyRevenues($monthWindow)),
        ];
    }

    private function pnlPercentage(?string $pnl, ?string $start): ?float
    {
        if ($pnl === null || $start === null || bccomp($start, '0', 8) <= 0) {
            return null;
        }

        return round((float) bcmul(bcdiv($pnl, $start, 8), '100', 4), 2);
    }

    /**
     * @param  array<string, string>  $revenues
     * @return array<int, float>
     */
    private function cumulativeRevenueSpark(array $revenues): array
    {
        $cumulative = 0.0;
        $spark = [];

        foreach ($revenues as $delta) {
            $cumulative += (float) $delta;
            $spark[] = round($cumulative, 2);
        }

        return $spark;
    }

    /**
     * @param  array<int, PositionPayload>  $positions
     * @return array<string, int>
     */
    private function positionCountKpis(array $positions): array
    {
        $longCount = count(array_filter($positions, fn (array $position): bool => $position['direction'] === 'LONG'));

        return [
            'open_count' => count($positions),
            'long_count' => $longCount,
            'short_count' => count($positions) - $longCount,
        ];
    }

    /**
     * @param  array<int, PositionPayload>  $positions
     * @return array<string, mixed>
     */
    private function webKpis(Account $account, array $positions): array
    {
        $kpis = $this->kpis($account, $positions);

        return [
            ...$kpis,
            ...$this->openPositionKpis($positions, $kpis['balance']),
        ];
    }

    /**
     * @param  array<int, PositionPayload>  $positions
     * @return array{
     *     open_long_max_pain_total: ?string,
     *     open_long_max_pain_pct: ?float,
     *     open_short_max_pain_total: ?string,
     *     open_short_max_pain_pct: ?float,
     *     open_positions_pnl: ?string
     * }
     */
    private function openPositionKpis(array $positions, ?string $portfolioBalance): array
    {
        $summary = $this->summarizeOpenPositions($positions);

        $longMaxPain = $this->maxPainSummary(
            $summary['max_pain_totals']['LONG'],
            $summary['complete_max_pain']['LONG'],
            $portfolioBalance,
        );
        $shortMaxPain = $this->maxPainSummary(
            $summary['max_pain_totals']['SHORT'],
            $summary['complete_max_pain']['SHORT'],
            $portfolioBalance,
        );

        return [
            'open_long_max_pain_total' => $longMaxPain['total'],
            'open_long_max_pain_pct' => $longMaxPain['percentage'],
            'open_short_max_pain_total' => $shortMaxPain['total'],
            'open_short_max_pain_pct' => $shortMaxPain['percentage'],
            'open_positions_pnl' => $summary['complete_pnl']
                ? bcround($summary['pnl'], precision: 2)
                : null,
        ];
    }

    /**
     * @param  array<int, PositionPayload>  $positions
     * @return array{
     *     max_pain_totals: array{LONG: numeric-string, SHORT: numeric-string},
     *     complete_max_pain: array{LONG: bool, SHORT: bool},
     *     pnl: string,
     *     complete_pnl: bool
     * }
     */
    private function summarizeOpenPositions(array $positions): array
    {
        $maxPainTotals = ['LONG' => '0', 'SHORT' => '0'];
        $completeMaxPain = ['LONG' => true, 'SHORT' => true];
        $pnl = '0';
        $completePnl = true;

        foreach ($positions as $position) {
            $this->accumulateMaxPain($position, $maxPainTotals, $completeMaxPain);
            $this->accumulatePositionPnl($position, $pnl, $completePnl);
        }

        return [
            'max_pain_totals' => $maxPainTotals,
            'complete_max_pain' => $completeMaxPain,
            'pnl' => $pnl,
            'complete_pnl' => $completePnl,
        ];
    }

    /**
     * @param  array{direction?: string|null, max_pain?: int|float|string|null, pnl?: string|null}  $position
     * @param  array{LONG: numeric-string, SHORT: numeric-string}  $totals
     * @param  array{LONG: bool, SHORT: bool}  $complete
     */
    private function accumulateMaxPain(array $position, array &$totals, array &$complete): void
    {
        $direction = $position['direction'] ?? null;

        if (! is_string($direction)) {
            $this->markMaxPainIncomplete($complete);

            return;
        }

        if (! isset($totals[$direction])) {
            $this->markMaxPainIncomplete($complete);

            return;
        }

        $maxPain = $this->numericString($position['max_pain'] ?? null);
        if ($maxPain === null) {
            $complete[$direction] = false;

            return;
        }

        $total = $this->numericString($totals[$direction]);
        if ($total === null) {
            $this->markMaxPainIncomplete($complete);

            return;
        }

        $totals[$direction] = bcadd($total, $maxPain, scale: 8);
    }

    /** @param array{LONG: bool, SHORT: bool} $complete */
    private function markMaxPainIncomplete(array &$complete): void
    {
        $complete['LONG'] = false;
        $complete['SHORT'] = false;
    }

    /** @param  array{direction?: string|null, max_pain?: int|float|string|null, pnl?: string|null}  $position */
    private function accumulatePositionPnl(array $position, string &$pnl, bool &$complete): void
    {
        $value = $this->numericString($position['pnl'] ?? null);

        $currentPnl = $this->numericString($pnl);
        if ($value !== null && $currentPnl !== null) {
            $pnl = bcadd($currentPnl, $value, scale: 8);

            return;
        }

        $complete = false;
    }

    /**
     * @return array{total: ?string, percentage: ?float}
     */
    private function maxPainSummary(string $total, bool $isComplete, ?string $portfolioBalance): array
    {
        if (! $isComplete) {
            return $this->emptyMaxPainSummary();
        }

        $numericTotal = $this->numericString($total);
        if ($numericTotal === null) {
            return $this->emptyMaxPainSummary();
        }

        $percentage = null;
        if ($portfolioBalance !== null && bccomp($portfolioBalance, '0', scale: 8) > 0) {
            $percentage = (float) bcround(
                bcmul(bcdiv($numericTotal, $portfolioBalance, scale: 8), '100', scale: 4),
                precision: 2,
            );
        }

        return [
            'total' => bcround($numericTotal, precision: 2),
            'percentage' => $percentage,
        ];
    }

    /** @return array{total: null, percentage: null} */
    private function emptyMaxPainSummary(): array
    {
        return ['total' => null, 'percentage' => null];
    }

    /**
     * Bounded BSCS contract for the first-party mobile dashboard.
     *
     * @return array{
     *     score: ?int,
     *     band: ?string,
     *     blocked: bool,
     *     status: string,
     *     is_stale: bool,
     *     block_threshold: int,
     *     computed_ago: ?string,
     *     next_compute_in: string,
     *     position_cap: array{long: array{effective: int, maximum: int}, short: array{effective: int, maximum: int}, ratio_percent: int},
     *     components: list<array{key: string, label: string, value: ?float, fired: bool}>,
     * }
     */
    private function mobileBscsBadge(Account $account): array
    {
        $bscs = $this->bscsBadge($account);

        return [
            'score' => $bscs['score'],
            'band' => $bscs['band'],
            'blocked' => $bscs['blocked'],
            // Pause surface — covers all three sources (fast shock breaker,
            // slow regime gate, error-storm monitor latch) so the phone can
            // say WHY opens are parked and until when. 'monitor' has no
            // until: it holds until an operator clears it.
            'paused' => $bscs['paused'],
            'pause_reason' => $bscs['pause_reason'],
            'cooldown_remaining' => $bscs['cooldown_remaining'],
            'cooldown_until' => $bscs['cooldown_until'],
            'status' => $bscs['status'],
            'is_stale' => $bscs['is_stale'],
            'block_threshold' => $bscs['block_threshold'],
            'computed_ago' => $bscs['computed_ago'],
            // Countdown to the next hourly score recompute, so the tile can
            // promise when the market will be reassessed instead of only
            // saying how old the current reading is.
            'next_compute_in' => $bscs['next_compute_in'],
            'position_cap' => $bscs['position_cap'],
            // Sub-signal breakdown — the phone renders it only when the
            // regime is off calm or opens are parked, but the payload always
            // carries it so the tile never has to fetch twice. Empty list
            // until the first compute lands.
            'components' => $bscs['components'],
        ];
    }

    /**
     * Compact BSCS payload for the user dashboard — score, band, posture,
     * and the account-specific position cap. Falls back to a calm-display
     * when no compute has landed yet.
     *
     * @return BscsPayload
     */
    private function bscsBadge(Account $account): array
    {
        $bscs = Bscs::forAccount($account);
        $index = $bscs->details();
        $score = $index->score();
        $band = $index->band()?->value;
        $blocked = $index->shouldBlockOpens();
        $cooldownUntil = $index->cooldownUntil();
        $pauseState = $this->bscsPauseState($index, $score, $blocked);
        $snapshot = $index->latestSnapshot();

        return $this->bscsPayload($bscs, $index, $score, $band, $blocked, $cooldownUntil, $pauseState, $snapshot);
    }

    /**
     * @param  array{pause_reason: ?string, paused: bool, openings_switch_enabled: bool}  $pauseState
     * @return BscsPayload
     */
    private function bscsPayload(
        BscsAccountContext $bscs,
        BscsState $index,
        ?int $score,
        ?string $band,
        bool $blocked,
        ?CarbonImmutable $cooldownUntil,
        array $pauseState,
        ?MarketRegimeSnapshot $snapshot,
    ): array {
        return [
            'score' => $score,
            'band' => $band,
            'blocked' => $blocked,
            'pause_reason' => $pauseState['pause_reason'],
            'paused' => $pauseState['paused'],
            'openings_switch_enabled' => $pauseState['openings_switch_enabled'],
            'cooldown_active' => $index->isCooldownActive(),
            'cooldown_remaining' => $this->humanUntil($cooldownUntil),
            'cooldown_until' => $cooldownUntil?->toIso8601String(),
            'status' => $this->bscsStatus($pauseState['paused'], $score, $band),
            'is_stale' => $index->isStale(),
            'block_threshold' => $index->blockThreshold(),
            'computed_at' => $snapshot?->computed_at ? CarbonImmutable::parse($snapshot->computed_at)->toIso8601String() : null,
            'computed_ago' => $this->humanAgo($snapshot?->computed_at),
            'next_compute_in' => $this->nextBscsComputeIn(),
            'components' => $this->bscsComponents($snapshot),
            'position_cap' => $bscs->positions()->max()->toArray(),
        ];
    }

    /** @return array{pause_reason: ?string, paused: bool, openings_switch_enabled: bool} */
    private function bscsPauseState(BscsState $index, ?int $score, bool $blocked): array
    {
        $openingsSwitchEnabled = (bool) (Kraite::find(1)?->allow_opening_positions ?? true);
        $paused = $this->bscsIsPaused($blocked, $openingsSwitchEnabled);
        $pauseReason = $this->bscsPauseReason($index, $score, $blocked, $openingsSwitchEnabled);

        return [
            'pause_reason' => $pauseReason,
            'paused' => $paused,
            'openings_switch_enabled' => $openingsSwitchEnabled,
        ];
    }

    private function bscsIsPaused(bool $blocked, bool $openingsSwitchEnabled): bool
    {
        return $blocked || ! $openingsSwitchEnabled;
    }

    private function bscsPauseReason(BscsState $index, ?int $score, bool $blocked, bool $openingsSwitchEnabled): ?string
    {
        if (! $openingsSwitchEnabled) {
            return 'monitor';
        }

        if (! $blocked) {
            return null;
        }

        return $score !== null && $score >= $index->blockThreshold() ? 'regime' : 'shock';
    }

    private function bscsStatus(bool $paused, ?int $score, ?string $band): string
    {
        return match (true) {
            $paused => 'New trades paused.',
            $score === null => 'Awaiting first compute…',
            $band === 'critical' => 'Market in critical regime.',
            $band === 'fragile' => 'New trades use smaller size.',
            $band === 'elevated' => 'Market moving more than usual.',
            default => 'Market normal.',
        };
    }

    /** @return list<array{key: string, label: string, value: ?float, fired: bool}> */
    private function bscsComponents(?MarketRegimeSnapshot $snapshot): array
    {
        if (! $snapshot) {
            return [];
        }

        $components = [];

        foreach ($this->bscsSignalMap() as $key => $label) {
            $value = $snapshot->{$key.'_value'};
            $components[] = [
                'key' => $key,
                'label' => $label,
                'value' => $value !== null ? round((float) $value, 2) : null,
                'fired' => (bool) $snapshot->{$key.'_fired'},
            ];
        }

        return $components;
    }

    /** @return array<string, string> */
    private function bscsSignalMap(): array
    {
        return [
            'vol_expansion' => 'Vol expansion',
            'range_blowout' => 'Range blowout',
            'corr_regime' => 'Correlation regime',
            'rejection_pct' => 'Rejection %',
            'fut_vol' => 'Futures vol',
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

        $marginRatio = $this->marginRatio($latest);
        $drawdownPct = $this->historicalDrawdown($account);

        return [
            'balance' => number_format((float) $latest->total_wallet_balance, 2, '.', ''),
            'pnl' => number_format((float) $latest->total_unrealized_profit, 2, '.', ''),
            'drawdown_pct' => $drawdownPct,
            'margin_ratio' => $marginRatio,
            'is_stub' => false,
        ];
    }

    private function marginRatio(\stdClass $latest): ?string
    {
        $marginBalance = (string) ($latest->total_margin_balance ?? '0');
        $maintMargin = (string) ($latest->total_maintenance_margin ?? '0');

        if (! is_numeric($marginBalance) || bccomp($marginBalance, '0', 16) <= 0) {
            return null;
        }

        return number_format((float) bcmul(bcdiv($maintMargin, $marginBalance, 6), '100', 4), 2, '.', '');
    }

    private function historicalDrawdown(Account $account): ?string
    {
        $extremes = DB::table('account_balance_history')
            ->where('account_id', $account->id)
            ->selectRaw('MAX(total_margin_balance) AS peak, MIN(total_margin_balance) AS trough')
            ->first();

        if (! $extremes || $extremes->peak === null) {
            return null;
        }

        $peak = (string) $extremes->peak;
        $trough = (string) ($extremes->trough ?? $extremes->peak);
        if (! $this->isPositivePrice($peak) || ! $this->isNumericPrice($trough)) {
            return null;
        }

        $delta = bcsub($peak, $trough, 16);

        return number_format((float) bcmul(bcdiv($delta, $peak, 6), '100', 4), 2, '.', '');
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
        $btc = $this->findBtcSymbol();
        if (! $btc) {
            return null;
        }

        $exchangeSymbolId = (int) $btc->es_id;
        $opens = $this->loadCandleOpens([$exchangeSymbolId])[$exchangeSymbolId] ?? [];
        $btcEs = ExchangeSymbol::find($exchangeSymbolId);
        $mark = $this->btcMark($exchangeSymbolId, $btcEs);

        return [
            'token' => 'BTC',
            'name' => $btc->name ?? 'Bitcoin',
            'image' => $btc->image_url,
            'mark' => $this->btcFormattedMark($mark, $btcEs),
            'day_change_pct' => $this->btcTraderDayChangePct($exchangeSymbolId, $mark),
            'spark_4h' => $this->loadFourHourSparks([$exchangeSymbolId])[$exchangeSymbolId] ?? [],
            'dots' => $this->buildTimeframeDots($mark, $opens),
        ];
    }

    private function findBtcSymbol(): ?\stdClass
    {
        return DB::table('exchange_symbols')
            ->join('symbols', 'symbols.id', '=', 'exchange_symbols.symbol_id')
            ->join('api_systems', 'api_systems.id', '=', 'exchange_symbols.api_system_id')
            ->where('symbols.token', 'BTC')
            ->where('exchange_symbols.quote', 'USDT')
            ->where('api_systems.canonical', 'binance')
            ->select('exchange_symbols.id as es_id', 'symbols.name', 'symbols.image_url')
            ->first();
    }

    private function btcMark(int $exchangeSymbolId, ?ExchangeSymbol $exchangeSymbol): ?string
    {
        return $this->markOrLastClose(
            $exchangeSymbolId,
            $this->nullableString($exchangeSymbol?->mark_price),
        );
    }

    private function btcFormattedMark(?string $mark, ?ExchangeSymbol $exchangeSymbol): ?string
    {
        return $mark !== null && $exchangeSymbol ? api_format_price($mark, $exchangeSymbol) : $mark;
    }

    /**
     * Live BTC change from midnight in the trader's configured day basis.
     * Reporting offsets are quarter-hour aligned, so the matching 15-minute
     * candle is the exact opening price for that trader day.
     */
    private function btcTraderDayChangePct(int $exchangeSymbolId, ?string $mark): ?string
    {
        if (! is_numeric($mark)) {
            return null;
        }

        $dayOpen = $this->btcDayOpen($exchangeSymbolId);
        if (! is_numeric($dayOpen) || bccomp($dayOpen, '0', 16) <= 0) {
            return null;
        }

        $change = bcmul(
            bcdiv(bcsub($mark, $dayOpen, 16), $dayOpen, 16),
            '100',
            8,
        );
        $rounded = bcround($change, precision: 2);

        return bccomp($rounded, '0', 2) === 0 ? '0.00' : $rounded;
    }

    private function btcDayOpen(int $exchangeSymbolId): ?string
    {
        $dayStart = ReportingDay::forUser(Auth::user())
            ->startOfDayUtc(CarbonImmutable::now())
            ->getTimestamp();
        $dayOpen = DB::table('candles')
            ->where('exchange_symbol_id', $exchangeSymbolId)
            ->where('timeframe', '15m')
            ->where('timestamp', $dayStart)
            ->value('open');

        return $dayOpen === null ? null : (string) $dayOpen;
    }

    /**
     * Prefer the dedicated 15-minute visual series: 17 closes cover exactly
     * four hours from first to last point. Fall back per symbol to the finest
     * engine-active timeframe with a complete four-hour span while ingestion
     * warms the denser series.
     *
     * @param  array<int, int>  $exchangeSymbolIds
     * @return array<int, array<int, float>>
     */
    private function loadFourHourSparks(array $exchangeSymbolIds): array
    {
        if ($exchangeSymbolIds === []) {
            return [];
        }

        $timeframes = $this->fourHourTimeframes();
        if ($timeframes->isEmpty()) {
            return [];
        }

        $pointLimits = $this->fourHourPointLimits($timeframes);
        $series = $this->fetchFourHourSeries(
            $exchangeSymbolIds,
            $timeframes,
            (int) $pointLimits->max(),
        );

        return $this->selectFourHourSparks($exchangeSymbolIds, $timeframes, $pointLimits, $series);
    }

    /** @return Collection<int, string> */
    private function fourHourTimeframes(): Collection
    {
        return collect(['15m'])
            ->merge(Kraite::timeframes())
            ->filter(fn (string $timeframe): bool => $this->isFourHourTimeframe($timeframe))
            ->sortBy(fn (string $timeframe): int => self::TIMEFRAME_SECONDS[$timeframe])
            ->unique()
            ->values();
    }

    private function isFourHourTimeframe(string $timeframe): bool
    {
        return isset(self::TIMEFRAME_SECONDS[$timeframe])
            && self::TIMEFRAME_SECONDS[$timeframe] <= 14_400;
    }

    /**
     * @param  Collection<int, string>  $timeframes
     * @return Collection<string, int>
     */
    private function fourHourPointLimits(Collection $timeframes): Collection
    {
        return $timeframes->mapWithKeys(fn (string $timeframe): array => [
            $timeframe => intdiv(14_400, self::TIMEFRAME_SECONDS[$timeframe]) + 1,
        ]);
    }

    /**
     * @param  array<int, int>  $exchangeSymbolIds
     * @param  Collection<int, string>  $timeframes
     * @return array<int, array<string, array<int, float>>>
     */
    private function fetchFourHourSeries(array $exchangeSymbolIds, Collection $timeframes, int $maxPointLimit): array
    {
        $rows = $this->fourHourCandleRows($exchangeSymbolIds, $timeframes, $maxPointLimit);
        $series = [];
        foreach ($rows as $row) {
            if (is_numeric($row->close)) {
                $series[(int) $row->exchange_symbol_id][$row->timeframe][] = (float) $row->close;
            }
        }

        return $series;
    }

    /**
     * @param  array<int, int>  $exchangeSymbolIds
     * @param  Collection<int, string>  $timeframes
     * @return Collection<int, \stdClass>
     */
    private function fourHourCandleRows(array $exchangeSymbolIds, Collection $timeframes, int $maxPointLimit): Collection
    {
        $ranked = DB::table('candles')
            ->select('exchange_symbol_id', 'timeframe', 'close', 'timestamp')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY exchange_symbol_id, timeframe ORDER BY timestamp DESC) AS rn')
            ->whereIn('exchange_symbol_id', $exchangeSymbolIds)
            ->whereIn('timeframe', $timeframes)
            ->whereNotNull('close');

        return DB::query()
            ->fromSub($ranked, 'ranked_candles')
            ->select('exchange_symbol_id', 'timeframe', 'close', 'timestamp')
            ->where('rn', '<=', $maxPointLimit)
            ->orderBy('exchange_symbol_id')
            ->orderBy('timeframe')
            ->orderBy('timestamp')
            ->get();
    }

    /**
     * @param  array<int, int>  $exchangeSymbolIds
     * @param  Collection<int, string>  $timeframes
     * @param  Collection<string, int>  $pointLimits
     * @param  array<int, array<string, array<int, float>>>  $series
     * @return array<int, array<int, float>>
     */
    private function selectFourHourSparks(array $exchangeSymbolIds, Collection $timeframes, Collection $pointLimits, array $series): array
    {
        $sparks = [];
        foreach ($exchangeSymbolIds as $exchangeSymbolId) {
            foreach ($timeframes as $timeframe) {
                $pointLimit = (int) $pointLimits->get($timeframe);
                $candidate = $series[(int) $exchangeSymbolId][$timeframe] ?? [];

                if (count($candidate) >= $pointLimit) {
                    $sparks[(int) $exchangeSymbolId] = array_slice($candidate, -$pointLimit);

                    break;
                }
            }
        }

        return $sparks;
    }

    /**
     * Shape a single Position for the tile renderer, including its live
     * price, lifecycle metrics, timeframe directions and four-hour trend.
     *
     * @param  array<int, array<string, string>>  $candleOpensByExchangeSymbol  Keyed by exchange_symbol_id → [tf => current_candle_open]
     * @param  array<int, array<int, float>>  $fourHourSparksByExchangeSymbol  Keyed by exchange_symbol_id → chronological closes spanning four hours
     * @return PositionPayload
     */
    private function serializePosition(
        Position $position,
        array $candleOpensByExchangeSymbol = [],
        array $fourHourSparksByExchangeSymbol = [],
    ): array {
        $exchangeSymbol = $position->exchangeSymbol;
        $symbol = $exchangeSymbol?->symbol;
        $markPrice = $this->nullableString($exchangeSymbol?->mark_price);
        $context = $this->positionContext($position, $exchangeSymbol, $markPrice);

        return $this->positionPayload(
            $position,
            $exchangeSymbol,
            $symbol,
            $context,
            $candleOpensByExchangeSymbol,
            $fourHourSparksByExchangeSymbol,
        );
    }

    /** @return PositionContext */
    private function positionContext(Position $position, ?ExchangeSymbol $exchangeSymbol, ?string $markPrice): array
    {
        $prices = $this->positionPrices($position, $markPrice);
        $limits = $this->positionLimits($position, $exchangeSymbol);
        $progress = $this->positionProgress($position, $prices, $limits);
        $financials = $this->positionFinancials($position, $prices);

        return [...$prices, ...$limits, ...$progress, ...$financials];
    }

    /** @return array{current_price: ?string, first_profit: ?string, last_limit: ?string, current_tp: ?string, next_limit: ?string, stop_loss: ?string} */
    private function positionPrices(Position $position, ?string $markPrice): array
    {
        $profitOrder = $position->profitOrder();
        $stopLossOrder = $position->orders
            ->where('type', 'STOP-MARKET')
            ->whereNotNull('exchange_order_id')
            ->whereIn('status', ['NEW', 'PARTIALLY_FILLED', 'FILLED'])
            ->sortByDesc('id')
            ->first();

        return [
            'current_price' => $this->markOrLastClose(
                (int) $position->exchange_symbol_id,
                $markPrice,
            ),
            'first_profit' => $this->nullableString($position->first_profit_price),
            'last_limit' => $this->nullableString($position->lastLimitOrder()?->price),
            'current_tp' => $this->nullableString($profitOrder?->price),
            'next_limit' => $this->nullableString($position->nextPendingLimitOrderPrice()),
            'stop_loss' => $this->nullableString($stopLossOrder?->price),
        ];
    }

    /** @return array{limits: array<int, PositionLimit>, total_limits: int, filled_count: int} */
    private function positionLimits(Position $position, ?ExchangeSymbol $exchangeSymbol): array
    {
        $limits = $position->orders
            ->where('type', 'LIMIT')
            ->whereNotNull('exchange_order_id')
            ->whereIn('status', ['NEW', 'PARTIALLY_FILLED', 'FILLED'])
            ->sortBy('quantity')
            ->values()
            ->map(fn (Order $order, int $index): array => $this->serializeLimitOrder($order, $index, $exchangeSymbol))
            ->all();

        return [
            'limits' => $limits,
            'total_limits' => (int) ($position->total_limit_orders ?? count($limits)),
            'filled_count' => collect($limits)->filter(fn (array $limit): bool => $limit['filled'])->count(),
        ];
    }

    /** @return PositionLimit */
    private function serializeLimitOrder(Order $order, int $index, ?ExchangeSymbol $exchangeSymbol): array
    {
        $status = strtoupper((string) $order->status);

        return [
            'index' => $index + 1,
            'price' => $this->formatPositionPrice((string) $order->price, $exchangeSymbol),
            'price_raw' => (string) $order->price,
            'quantity' => $exchangeSymbol === null
                ? (string) $order->quantity
                : api_format_quantity((string) $order->quantity, $exchangeSymbol),
            'status' => $status,
            'filled' => $status === 'FILLED',
        ];
    }

    /**
     * @param  array{current_price: ?string, first_profit: ?string, last_limit: ?string, current_tp: ?string, next_limit: ?string, stop_loss: ?string}  $prices
     * @param  array{limits: array<int, PositionLimit>, total_limits: int, filled_count: int}  $limits
     * @return array{alpha_path_pct: string, alpha_limit_pct: string, track: ?TrackGeometry, direction: string, take_profit_distance_pct: ?string, next_limit_distance_pct: ?string}
     */
    private function positionProgress(Position $position, array $prices, array $limits): array
    {
        $direction = strtoupper((string) $position->direction);
        $alphaLimitPct = $this->computeAlphaLimitPercent($prices['current_tp'], $prices['next_limit'], $prices['current_price']);

        return [
            'alpha_path_pct' => $this->computeAlphaPathPercent($prices['first_profit'], $prices['last_limit'], $prices['current_price']),
            'alpha_limit_pct' => $alphaLimitPct,
            'track' => $this->trackGeometry(
                $limits['filled_count'],
                $limits['total_limits'],
                (float) $this->computeAlphaLimitPercent($prices['current_tp'], $prices['next_limit'] ?? $prices['stop_loss'], $prices['current_price']),
            ),
            'direction' => $direction,
            'take_profit_distance_pct' => $this->takeProfitDistancePercent($prices['current_price'], $prices['current_tp'], $direction),
            'next_limit_distance_pct' => $this->nextLimitDistancePercent($prices['current_price'], $prices['next_limit'], $direction),
        ];
    }

    /**
     * @param  array{current_price: ?string}  $prices
     * @return array{quantity: string, size: ?string, pnl: ?string, max_pain: int|float|string|null, opening_price: ?string, was_waped: bool, wap: ?string, entry_price: ?string}
     */
    private function positionFinancials(Position $position, array $prices): array
    {
        $openingPrice = $this->nullableString($position->opening_price);
        $wasWaped = (bool) $position->was_waped;
        $wap = $this->computeWap($position);

        return [
            'quantity' => (string) ($position->quantity ?? '0'),
            'size' => $this->computeSize((string) ($position->quantity ?? '0'), $prices['current_price']),
            'pnl' => $position->unrealizedPnl(),
            'max_pain' => $position->getAttribute('max_pain'),
            'opening_price' => $openingPrice,
            'was_waped' => $wasWaped,
            'wap' => $wap,
            'entry_price' => $wasWaped && $wap !== null ? $wap : $openingPrice,
        ];
    }

    /**
     * @param  PositionContext  $context
     * @param  array<int, array<string, string>>  $candleOpensByExchangeSymbol
     * @param  array<int, array<int, float>>  $fourHourSparksByExchangeSymbol
     * @return PositionPayload
     */
    private function positionPayload(
        Position $position,
        ?ExchangeSymbol $exchangeSymbol,
        ?Symbol $symbol,
        array $context,
        array $candleOpensByExchangeSymbol,
        array $fourHourSparksByExchangeSymbol,
    ): array {
        return [
            ...$this->positionHeaderPayload($position, $exchangeSymbol, $symbol, $context, $candleOpensByExchangeSymbol, $fourHourSparksByExchangeSymbol),
            ...$this->positionPricePayload($context, $exchangeSymbol),
            ...$this->positionProgressPayload($context),
            ...$this->positionRiskPayload($context, $exchangeSymbol),
            ...$this->positionLimitPayload($context),
        ];
    }

    /**
     * @param  PositionContext  $context
     * @param  array<int, array<string, string>>  $candleOpensByExchangeSymbol
     * @param  array<int, array<int, float>>  $fourHourSparksByExchangeSymbol
     * @return PositionHeaderPayload
     */
    private function positionHeaderPayload(
        Position $position,
        ?ExchangeSymbol $exchangeSymbol,
        ?Symbol $symbol,
        array $context,
        array $candleOpensByExchangeSymbol,
        array $fourHourSparksByExchangeSymbol,
    ): array {
        $currentPrice = $context['current_price'];

        return [
            'id' => $position->id,
            'status' => strtolower((string) $position->status),
            'symbol' => (string) ($position->parsed_trading_pair ?? $exchangeSymbol?->parsed_trading_pair ?? 'Unknown'),
            'token' => $exchangeSymbol?->token ?? ($symbol !== null ? $symbol->token : null),
            'token_name' => $symbol?->name ?? null,
            'token_image' => $symbol?->image_url ?? null,
            'direction' => $context['direction'],
            'leverage' => (int) $position->leverage,
            'opened_at' => optional($position->opened_at ?? $position->created_at)?->toIso8601String(),
            'age_human' => $this->humanAgo($position->opened_at ?? $position->created_at),
            'var_24h_pct' => null,
            'timeframe_dots' => $this->buildTimeframeDots($currentPrice, $candleOpensByExchangeSymbol[$position->exchange_symbol_id] ?? []),
            'spark_4h' => $fourHourSparksByExchangeSymbol[$position->exchange_symbol_id] ?? [],
            'side' => strtolower((string) $position->direction),
        ];
    }

    /**
     * @param  PositionContext  $context
     * @return PositionPricePayload
     */
    private function positionPricePayload(array $context, ?ExchangeSymbol $exchangeSymbol): array
    {
        return [
            'current_price' => $this->formatPositionPrice($context['current_price'], $exchangeSymbol),
            'opening_price' => $this->formatPositionPrice($context['opening_price'], $exchangeSymbol),
            'wap_price' => $this->formatPositionPrice($context['wap'], $exchangeSymbol),
            'was_waped' => $context['was_waped'],
            'entry_label' => $context['was_waped'] ? 'WAP' : 'Open',
            'entry_price' => $this->formatPositionPrice($context['entry_price'], $exchangeSymbol),
            'first_profit_price' => $this->formatPositionPrice($context['first_profit'], $exchangeSymbol),
            'profit_price' => $this->formatPositionPrice($context['current_tp'], $exchangeSymbol),
            'take_profit_distance_pct' => $context['take_profit_distance_pct'],
            'next_limit_price' => $this->formatPositionPrice($context['next_limit'], $exchangeSymbol),
            'next_limit_distance_pct' => $context['next_limit_distance_pct'],
            'last_limit_price' => $this->formatPositionPrice($context['last_limit'], $exchangeSymbol),
            'stop_loss_price' => $this->formatPositionPrice($context['stop_loss'], $exchangeSymbol),
        ];
    }

    /**
     * @param  PositionContext  $context
     * @return PositionProgressPayload
     */
    private function positionProgressPayload(array $context): array
    {
        return [
            'track' => $context['track'],
            'alpha_path_pct' => $context['alpha_path_pct'],
            'alpha_limit_pct' => $context['alpha_limit_pct'],
        ];
    }

    /**
     * @param  PositionContext  $context
     * @return PositionRiskPayload
     */
    private function positionRiskPayload(array $context, ?ExchangeSymbol $exchangeSymbol): array
    {
        return [
            'size' => $context['size'],
            'pnl' => $context['pnl'],
            'max_pain' => $context['max_pain'],
            'max_pain_formatted' => $this->formatPositionPrice($this->numericString($context['max_pain']), $exchangeSymbol),
        ];
    }

    /**
     * @param  PositionContext  $context
     * @return PositionLimitPayload
     */
    private function positionLimitPayload(array $context): array
    {
        return [
            'filled_count' => $context['filled_count'],
            'total_limits' => $context['total_limits'],
            'limits' => $context['limits'],
        ];
    }

    private function nullableString(int|float|string|null $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /** @return numeric-string|null */
    private function numericString(int|float|string|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = (string) $value;

        return $this->isNumericPrice($normalized) ? $normalized : null;
    }

    private function formatPositionPrice(?string $value, ?ExchangeSymbol $exchangeSymbol): ?string
    {
        if ($exchangeSymbol === null || $value === null || ! is_numeric($value)) {
            return $value;
        }

        return api_format_price($value, $exchangeSymbol);
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
        if ($exchangeSymbolIds === []) {
            return [];
        }

        return $this->indexCandleOpens($this->candleOpenRows($exchangeSymbolIds));
    }

    /**
     * @param  array<int, int>  $exchangeSymbolIds
     * @return Collection<int, \stdClass>
     */
    private function candleOpenRows(array $exchangeSymbolIds): Collection
    {
        return DB::table(DB::raw('(
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
    }

    /**
     * @param  Collection<int, \stdClass>  $rows
     * @return array<int, array<string, string>>
     */
    private function indexCandleOpens(Collection $rows): array
    {
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
     * @return array<int, array{timeframe: string, direction: string}>
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
     *  - SL owns the final 100% stage after the deepest configured rung.
     *  - TP starts hard-left (0% — a fresh position's first TP). Each WAP
     *    (filled rung) slides it right to that rung's slot; the tile draws
     *    a trace from 0% to the current TP so the slide stays visible.
     *  - PX marker travels from TP toward the NEXT pending rung's slot,
     *    proportional to that leg's progress. After every rung fills, PX
     *    travels from the WAP-adjusted TP toward SL instead.
     *
     * @return TrackGeometry|null
     */
    private function trackGeometry(int $filledCount, int $totalLimits, float $alphaLimitPct): ?array
    {
        if ($totalLimits < 1) {
            return null;
        }

        $tp = $filledCount === 0 ? 0.0 : $this->trackSlot(min($filledCount, $totalLimits), $totalLimits);
        $nextAnchor = $filledCount < $totalLimits
            ? $this->trackSlot($filledCount + 1, $totalLimits)
            : 100.0;

        $fraction = max(0.0, min(100.0, $alphaLimitPct)) / 100.0;
        $px = round($tp + ($nextAnchor - $tp) * $fraction, 1);

        return [
            'tp_pct' => $tp,
            'px_pct' => $px,
            'sl_pct' => 100.0,
            'gain_left' => round(min($tp, $px), 1),
            'gain_width' => round(abs($px - $tp), 1),
            'rungs' => $this->trackRungs($filledCount, $totalLimits),
        ];
    }

    private function trackSlot(int $index, int $totalLimits): float
    {
        if ($totalLimits === 1) {
            return 53.0;
        }

        return round(26.0 + ($index - 1) * (54.0 / ($totalLimits - 1)), 1);
    }

    /** @return array<int, array{index: int, pct: float}> */
    private function trackRungs(int $filledCount, int $totalLimits): array
    {
        $rungs = [];
        for ($i = $filledCount + 1; $i <= $totalLimits; $i++) {
            $rungs[] = ['index' => $i, 'pct' => $this->trackSlot($i, $totalLimits)];
        }

        return $rungs;
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
            $fill = $this->filledEntryValues($order);
            if ($fill === null) {
                continue;
            }

            $numerator = bcadd($numerator, bcmul($fill['price'], $fill['quantity'], 16), 16);
            $quantitySum = bcadd($quantitySum, $fill['quantity'], 16);
        }

        if (bccomp($quantitySum, '0', 16) === 0) {
            return null;
        }

        return bcdiv($numerator, $quantitySum, 8);
    }

    /**
     * @return array{price: numeric-string, quantity: numeric-string}|null
     */
    private function filledEntryValues(Order $order): ?array
    {
        if (! in_array(strtoupper((string) $order->type), ['MARKET', 'LIMIT'], true)) {
            return null;
        }

        if (strtoupper((string) $order->status) !== 'FILLED') {
            return null;
        }

        $price = (string) $order->price;
        $quantity = (string) $order->quantity;
        if (! $this->isNumericPrice($price) || ! $this->isNumericPrice($quantity)) {
            return null;
        }

        return [
            'price' => $price,
            'quantity' => $quantity,
        ];
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
        if (! $this->isNumericPrice($start) || ! $this->isNumericPrice($end) || ! $this->isNumericPrice($current)) {
            return '0.0';
        }

        return $this->formatAlphaFraction($this->alphaPathFraction($start, $end, $current));
    }

    /**
     * @param  numeric-string  $start
     * @param  numeric-string  $end
     * @param  numeric-string  $current
     * @return numeric-string
     */
    private function alphaPathFraction(string $start, string $end, string $current): string
    {
        if ($this->pricesEqual($start, $end)) {
            return '0';
        }

        return bccomp($end, $start, 16) >= 0
            ? $this->shortAlphaPathFraction($start, $end, $current)
            : $this->longAlphaPathFraction($start, $end, $current);
    }

    /**
     * @param  numeric-string  $start
     * @param  numeric-string  $end
     * @param  numeric-string  $current
     * @return numeric-string
     */
    private function shortAlphaPathFraction(string $start, string $end, string $current): string
    {
        if (bccomp($current, $start, 16) <= 0) {
            return '0';
        }

        if (bccomp($current, $end, 16) >= 0) {
            return '1';
        }

        return bcdiv(bcsub($current, $start, 16), bcsub($end, $start, 16), 6);
    }

    /**
     * @param  numeric-string  $start
     * @param  numeric-string  $end
     * @param  numeric-string  $current
     * @return numeric-string
     */
    private function longAlphaPathFraction(string $start, string $end, string $current): string
    {
        if (bccomp($current, $start, 16) >= 0) {
            return '0';
        }

        if (bccomp($current, $end, 16) <= 0) {
            return '1';
        }

        return bcdiv(bcsub($start, $current, 16), bcsub($start, $end, 16), 6);
    }

    /** @param  numeric-string  $fraction */
    private function formatAlphaFraction(string $fraction): string
    {
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
        if (! $this->isNumericPrice($tp) || ! $this->isNumericPrice($nextLimit) || ! $this->isNumericPrice($current)) {
            return '0.0';
        }

        $fraction = $this->alphaLimitFraction($tp, $nextLimit, $current);
        if ($fraction === null) {
            return '0.0';
        }

        return $this->formatAlphaFraction($this->clampAlphaFraction($fraction));
    }

    /**
     * @param  numeric-string  $tp
     * @param  numeric-string  $nextLimit
     * @param  numeric-string  $current
     * @return numeric-string|null
     */
    private function alphaLimitFraction(string $tp, string $nextLimit, string $current): ?string
    {
        if ($this->pricesEqual($tp, $nextLimit)) {
            return '0';
        }

        $ascending = bccomp($nextLimit, $tp, 16) >= 0;
        $num = $this->alphaLimitNumerator($tp, $current, $ascending);
        $den = $this->alphaLimitDenominator($tp, $nextLimit, $ascending);

        return bccomp($den, '0', 16) === 0 ? null : bcdiv($num, $den, 6);
    }

    /**
     * @param  numeric-string  $tp
     * @param  numeric-string  $current
     * @return numeric-string
     */
    private function alphaLimitNumerator(string $tp, string $current, bool $ascending): string
    {
        return $ascending ? bcsub($current, $tp, 16) : bcsub($tp, $current, 16);
    }

    /**
     * @param  numeric-string  $tp
     * @param  numeric-string  $nextLimit
     * @return numeric-string
     */
    private function alphaLimitDenominator(string $tp, string $nextLimit, bool $ascending): string
    {
        return $ascending ? bcsub($nextLimit, $tp, 16) : bcsub($tp, $nextLimit, 16);
    }

    /**
     * @param  numeric-string  $fraction
     * @return numeric-string
     */
    private function clampAlphaFraction(string $fraction): string
    {
        if (bccomp($fraction, '0', 16) < 0) {
            return '0';
        }

        return bccomp($fraction, '1', 16) > 0 ? '1' : $fraction;
    }

    private function takeProfitDistancePercent(?string $current, ?string $target, string $direction): ?string
    {
        return match ($direction) {
            'LONG' => $this->directionalPriceDistancePercent($current, $target, true),
            'SHORT' => $this->directionalPriceDistancePercent($current, $target, false),
            default => null,
        };
    }

    private function nextLimitDistancePercent(?string $current, ?string $target, string $direction): ?string
    {
        return match ($direction) {
            'LONG' => $this->directionalPriceDistancePercent($current, $target, false),
            'SHORT' => $this->directionalPriceDistancePercent($current, $target, true),
            default => null,
        };
    }

    private function directionalPriceDistancePercent(?string $current, ?string $target, bool $targetAbove): ?string
    {
        if (! $this->isPositivePrice($current) || ! $this->isNumericPrice($target)) {
            return null;
        }

        $distance = $this->priceDistance($current, $target, $targetAbove);

        if (bccomp($distance, '0', 16) <= 0) {
            return '0.00';
        }

        $percentage = bcmul(bcdiv($distance, $current, 16), '100', 8);

        return bcround($percentage, precision: 2);
    }

    /** @phpstan-assert-if-true numeric-string $value */
    private function isNumericPrice(?string $value): bool
    {
        return $value !== null && is_numeric($value);
    }

    /** @phpstan-assert-if-true numeric-string $value */
    private function isPositivePrice(?string $value): bool
    {
        return $this->isNumericPrice($value) && bccomp($value, '0', 16) > 0;
    }

    /**
     * @param  numeric-string  $first
     * @param  numeric-string  $second
     */
    private function pricesEqual(string $first, string $second): bool
    {
        return bccomp($first, $second, 16) === 0;
    }

    /**
     * @param  numeric-string  $current
     * @param  numeric-string  $target
     * @return numeric-string
     */
    private function priceDistance(string $current, string $target, bool $targetAbove): string
    {
        return $targetAbove
            ? bcsub($target, $current, 16)
            : bcsub($current, $target, 16);
    }
}
