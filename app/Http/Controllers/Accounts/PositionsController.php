<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounts;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSnapshot;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;

/**
 * @phpstan-type ScalarValue int|float|string|bool|null
 * @phpstan-type PositionData array<string, ScalarValue>
 * @phpstan-type RawExchangeData array<string, ScalarValue>
 * @phpstan-type PairOrderData array<string, ScalarValue|PositionData|RawExchangeData|array<int, string>>
 */
final class PositionsController extends Controller
{
    private const POSITION_DRIFT_FIELDS = ['quantity', 'entry_price', 'leverage', 'margin', 'margin_mode'];

    private const ORDER_DRIFT_FIELDS = ['status', 'side', 'type', 'price', 'quantity'];

    private const NUMERIC_FIELDS = ['quantity', 'price', 'entry_price', 'margin'];

    // Price fields where exchange-reported precision exceeds what we store
    // (e.g. Binance returns a 13-decimal volume-weighted entry while we
    // persist the fill price). Broker-side averaging shifts the reported
    // entry by fractions of a tick per fill — real drift is orders of
    // magnitude larger — so a 10bp (0.1%) band absorbs rounding without
    // letting actual desync slip through.
    private const PRICE_TOLERANCE_FIELDS = ['price', 'entry_price'];

    private const PRICE_TOLERANCE = '0.001';

    private const COMPARATOR_ORDER_STATUSES = ['NEW', 'PARTIALLY_FILLED', 'FILLED'];

    private const OPEN_POSITION_STATUSES = ['new', 'opening', 'active', 'syncing', 'waping', 'closing', 'cancelling'];

    private const PAIR_STATUS_SYNCED = 'synced';

    private const PAIR_STATUS_DRIFT = 'drift';

    private const PAIR_STATUS_DB_ONLY = 'db_only';

    private const PAIR_STATUS_EXCHANGE_ONLY = 'exchange_only';

    private const PAIR_STATUS_TRANSIENT = 'transient';

    /**
     * "Can't verify from here" — the exchange-side picture for this row
     * doesn't exist yet (no snapshot at all, or the row's order type lives
     * on an exchange endpoint the engine doesn't snapshot yet). Explicitly
     * NOT drift: the comparator refuses to claim desync it can't prove.
     */
    private const PAIR_STATUS_UNVERIFIED = 'unverified';

    /**
     * Kraite order types that exist ONLY on the exchange's algo/plan
     * endpoint (post Dec-2025 Binance algo migration) — absent from the
     * open-orders snapshot BY DESIGN. Without an algo snapshot their
     * absence proves nothing.
     */
    private const ALGO_ENDPOINT_TYPES = ['STOP-LIMIT', 'STOP-MARKET', 'TRAILING-STOP', 'PROFIT-MARKET'];

    /** Whether the account has an algo-orders snapshot (any content). */
    private bool $algoSnapshotPresent = false;

    /**
     * Kraite's internal type labels ↔ the broader set of labels an exchange
     * can return for the same functional order. E.g. Kraite's "PROFIT-LIMIT"
     * is placed on Binance as a plain reduce-only "LIMIT" (post Dec-2025
     * algo migration) but older responses may still tag it "TAKE_PROFIT".
     */
    private const TYPE_ALIASES = [
        'PROFIT-LIMIT' => ['LIMIT', 'TAKE_PROFIT', 'TAKE_PROFIT_LIMIT'],
        'PROFIT-MARKET' => ['MARKET', 'TAKE_PROFIT_MARKET'],
        'STOP-LIMIT' => ['STOP', 'STOP_LIMIT'],
        'STOP-MARKET' => ['STOP_MARKET'],
        'TRAILING-STOP' => ['TRAILING_STOP_MARKET'],
    ];

    /**
     * Cross-exchange order status equivalents. Binance's REST shape uses
     * "NEW"; BitGet uses "LIVE"; Bybit historically used "ACTIVE". Bucket
     * them all into the same "open and resting" bucket so the comparator
     * doesn't flag drift purely on terminology.
     */
    private const STATUS_ALIASES = [
        'NEW' => ['LIVE', 'ACTIVE', 'OPEN'],
        'PARTIALLY_FILLED' => ['PARTIAL_FILL', 'PARTIALLYFILLED', 'PARTIALLY-FILLED'],
        'FILLED' => ['CLOSED', 'EXECUTED'],
    ];

    /**
     * Order types whose "natural" exchange-side quantity is the WHOLE
     * position at trigger time (BitGet plan orders, etc.). They report
     * size=0 because nothing is bound; the order will close whatever is
     * open when the trigger fires. Suppress qty drift on these when the
     * exchange-reported quantity is zero — comparing against the DB's
     * intended qty would always flag false drift.
     */
    private const CLOSE_POSITION_TYPES = [
        'PROFIT-LIMIT', 'PROFIT-MARKET', 'STOP-LIMIT', 'STOP-MARKET', 'TRAILING-STOP',
    ];

    /**
     * Resolve a per-exchange logo to a self-hosted asset path. Avoids
     * leaning on api_systems.logo_url, which points at the exchange's
     * own favicon — a 16×16 .ico that scales to a fuzzy mess in the
     * 48×48 chip and would force a CSP whitelist for each exchange host.
     * The local PNGs (64×64, sourced from CoinMarketCap) sit in
     * public/logos/exchanges/{canonical}.png.
     */
    private function exchangeLogoUrl(?string $canonical): ?string
    {
        if (! $canonical) {
            return null;
        }

        $path = public_path("logos/exchanges/{$canonical}.png");

        return file_exists($path) ? "/logos/exchanges/{$canonical}.png" : null;
    }

    public function history(Request $request): JsonResponse
    {
        $account = $this->historyAccount($request);

        return $this->historyResponse($this->historyPaginator($account, $request));
    }

    private function historyAccount(Request $request): Account
    {
        $request->validate([
            'account_id' => ['required', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        return Account::query()
            ->where('id', $request->input('account_id'))
            ->where('user_id', Auth::id())
            ->firstOrFail();
    }

    /** @return LengthAwarePaginator<int, Position> */
    private function historyPaginator(Account $account, Request $request): LengthAwarePaginator
    {
        return $account->positions()
            ->with([
                'exchangeSymbol',
                'orders' => fn ($q) => $q->orderBy('id'),
            ])
            ->orderByDesc('created_at')
            ->paginate((int) $request->input('per_page', 25), ['*'], 'page', (int) $request->input('page', 1));
    }

    /** @param LengthAwarePaginator<int, Position> $paginator */
    private function historyResponse(LengthAwarePaginator $paginator): JsonResponse
    {
        $positions = collect($paginator->items())->map(fn ($p): array => $this->historyPositionRow($p))->all();

        return response()->json([
            'positions' => $positions,
            'page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ]);
    }

    /** @return array<string, mixed> */
    private function historyPositionRow(mixed $position): array
    {
        $entryPrice = $this->entryPrice($position);
        [$pnl, $pnlKind] = $this->computePnl($position, $entryPrice);

        return [
            ...$this->historyPositionFields($position, $entryPrice, $pnl, $pnlKind),
            'orders' => $this->historyOrderRows($position),
            'pnl_projections' => $this->computePnlProjections($position),
        ];
    }

    /** @return array<string, mixed> */
    private function historyPositionFields(mixed $position, ?string $entryPrice, ?string $pnl, string $pnlKind): array
    {
        return [
            'id' => $position->id,
            'symbol' => $position->parsed_trading_pair ?? $position->exchangeSymbol?->symbol ?? 'Unknown',
            'direction' => $position->direction,
            'status' => $position->status,
            'quantity' => (string) $position->quantity,
            'opening_price' => $this->displayEntryPrice($entryPrice),
            'closing_price' => (string) ($position->closing_price ?? ''),
            'mark_price' => $position->exchangeSymbol?->mark_price !== null ? (string) $position->exchangeSymbol->mark_price : null,
            'leverage' => (string) $position->leverage,
            'margin' => (string) $position->margin,
            'pnl' => $pnl,
            'pnl_kind' => $pnlKind,
            'created_at' => optional($position->created_at)->format('Y-m-d H:i:s'),
            'closed_at' => optional($position->closed_at ?? null)->format('Y-m-d H:i:s'),
            'order_count' => $position->orders->count(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function historyOrderRows(mixed $position): array
    {
        return $position->orders->map(fn ($order): array => [
            'id' => $order->id,
            'type' => strtoupper((string) $order->type),
            'side' => strtoupper((string) $order->side),
            'status' => strtoupper((string) $order->status),
            'quantity' => (string) $order->quantity,
            'price' => (string) $order->price,
            'client_order_id' => $order->client_order_id,
            'exchange_order_id' => $order->exchange_order_id,
        ])->all();
    }

    public function index(): View
    {
        return view('accounts.positions', $this->indexViewData($this->indexAccountModels()));
    }

    /** @return EloquentCollection<int, Account> */
    private function indexAccountModels(): EloquentCollection
    {
        return Account::with('apiSystem', 'user')
            ->where('user_id', Auth::id())
            ->orderBy('user_id')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  EloquentCollection<int, Account>  $accountModels
     * @return array<string, mixed>
     */
    private function indexViewData(EloquentCollection $accountModels): array
    {
        $accountIds = $accountModels->pluck('id')->all();
        [$positions, $closed, $details, $noPositions] = $this->indexPositionData($accountIds);

        return [
            'accounts' => $accountModels->map(fn (Account $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'exchange' => $account->apiSystem?->name ?? 'Unknown',
                'exchange_canonical' => $account->apiSystem?->canonical,
                'exchange_logo' => $this->exchangeLogoUrl($account->apiSystem?->canonical),
                'user' => $account->user?->name ?? 'Unknown',
                'can_trade' => (bool) $account->can_trade,
            ]),
            'positions' => $positions,
            'closed' => $closed,
            'details' => $details,
            'noPositions' => $noPositions,
        ];
    }

    /**
     * @param  array<int, int>  $accountIds
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>, 2: array<int, array<string, mixed>>, 3: bool}
     */
    private function indexPositionData(array $accountIds): array
    {
        $openModels = $this->openPositionModels($accountIds);
        $closedModels = $this->closedPositionModels($accountIds);

        return [
            $openModels->map(fn ($p): array => $this->mapOpenRow($p))->all(),
            $closedModels->map(fn ($p): array => $this->mapClosedRow($p))->all(),
            $this->positionDetails($openModels, $closedModels),
            ! Position::query()->whereIn('account_id', $accountIds)->exists(),
        ];
    }

    /** @return array<int|string, mixed> */
    private function positionRelations(): array
    {
        return ['exchangeSymbol.symbol', 'account.apiSystem', 'orders' => fn ($query) => $query->orderBy('id')];
    }

    /**
     * @param  array<int, int>  $accountIds
     * @return EloquentCollection<int, Position>
     */
    private function openPositionModels(array $accountIds): EloquentCollection
    {
        return Position::query()
            ->whereIn('account_id', $accountIds)
            ->whereIn('status', self::OPEN_POSITION_STATUSES)
            ->select('positions.*')
            ->selectRaw('TIMESTAMPDIFF(SECOND, COALESCE(opened_at, created_at), NOW()) as opened_seconds_ago')
            ->with($this->positionRelations())
            ->orderByDesc('opened_at')
            ->get();
    }

    /**
     * @param  array<int, int>  $accountIds
     * @return EloquentCollection<int, Position>
     */
    private function closedPositionModels(array $accountIds): EloquentCollection
    {
        return Position::query()
            ->whereIn('account_id', $accountIds)
            ->where('status', 'closed')
            ->select('positions.*')
            ->selectRaw('TIMESTAMPDIFF(SECOND, COALESCE(opened_at, created_at), COALESCE(closed_at, NOW())) as held_seconds')
            ->selectRaw('TIMESTAMPDIFF(SECOND, closed_at, NOW()) as closed_seconds_ago')
            ->with($this->positionRelations())
            ->orderByDesc('closed_at')
            ->limit(50)
            ->get();
    }

    /**
     * @param  EloquentCollection<int, Position>  $openModels
     * @param  EloquentCollection<int, Position>  $closedModels
     * @return array<int, array<string, mixed>>
     */
    private function positionDetails(EloquentCollection $openModels, EloquentCollection $closedModels): array
    {
        $details = [];
        foreach ($openModels as $position) {
            $details[$position->id] = $this->mapDetail($position, false);
        }
        foreach ($closedModels as $position) {
            $details[$position->id] = $this->mapDetail($position, true);
        }

        return $details;
    }

    /** Trailing-zero-trimmed numeric string for display. */
    private function fmtNum(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '—';
        }

        return $this->trim((string) $v);
    }

    /** Human age from a seconds count (e.g. "28h", "2d 4h"). */
    private function humanAge(?int $seconds): string
    {
        $h = (int) round(((int) $seconds) / 3600);
        if ($h < 1) {
            return max(1, (int) round(((int) $seconds) / 60)).'m';
        }
        if ($h < 24) {
            return $h.'h';
        }
        $d = intdiv($h, 24);
        $rem = $h % 24;

        return $d.'d'.($rem ? ' '.$rem.'h' : '');
    }

    /**
     * Map a DB open position to the open-table row shape. Liq is exchange-only
     * (not stored) so it renders "—".
     *
     * @return array<string, mixed>
     */
    private function mapOpenRow($p): array
    {
        $metrics = $this->openRowMetrics($p);

        return array_merge(
            $this->openRowIdentity($p, $metrics),
            $this->openRowValues($p, $metrics),
        );
    }

    /** @return array<string, mixed> */
    private function openRowMetrics(mixed $position): array
    {
        $entryPrice = $this->entryPrice($position);
        [$pnl] = $this->computePnl($position, $entryPrice);
        $limits = $position->orders->where('type', 'LIMIT');

        return [
            'entryPrice' => $entryPrice,
            'pnl' => $pnl !== null ? (float) $pnl : 0.0,
            'margin' => (float) ($position->margin ?? 0),
            'mark' => $position->exchangeSymbol?->mark_price,
            'notional' => (float) ($position->quantity ?? 0) * $this->markForNotional($position->exchangeSymbol?->mark_price, $entryPrice),
            'total' => max(1, (int) ($position->total_limit_orders ?? $limits->count())),
            'filled' => $limits->where('status', 'FILLED')->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>
     */
    private function openRowIdentity(mixed $position, array $metrics): array
    {
        return [
            'rowId' => $position->id,
            'sym' => $position->exchangeSymbol?->symbol?->token ?? $position->parsed_trading_pair ?? '—',
            'market' => $position->parsed_trading_pair ?? ($position->exchangeSymbol?->symbol ?? '—'),
            'name' => $position->exchangeSymbol?->symbol?->name ?? '',
            'icon' => $position->exchangeSymbol?->symbol?->image_url,
            'side' => strtolower((string) $position->direction),
            'lev' => ((int) $position->leverage).'×',
            'status' => $this->openRowStatus(strtolower((string) $position->status)),
            'filled' => $metrics['filled'].' / '.$metrics['total'],
            'open' => $this->fmtNum($this->displayEntryPrice($metrics['entryPrice'])),
        ];
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>
     */
    private function openRowValues(mixed $position, array $metrics): array
    {
        return [
            'tp' => $this->fmtNum($position->orders->firstWhere('type', 'PROFIT-LIMIT')?->price ?? $position->first_profit_price),
            'mark' => $this->displayMark($metrics['mark']),
            'liq' => '—',
            'pnl' => round($metrics['pnl'], 2),
            'size' => $this->fmtNum($position->quantity),
            'notional' => (int) round($metrics['notional']),
            'margin' => (int) round($metrics['margin']),
            'roe' => $this->returnOnEquity($metrics['pnl'], $metrics['margin']),
            'ageH' => round(((int) ($position->opened_seconds_ago ?? 0)) / 3600, 2),
        ];
    }

    private function markForNotional(mixed $mark, ?string $entryPrice): float
    {
        return $mark !== null && (string) $mark !== '' ? (float) $mark : (float) $entryPrice;
    }

    private function openRowStatus(string $status): ?string
    {
        return $status === 'waping' ? 'waped' : ($status === 'opening' ? 'opening' : null);
    }

    private function displayMark(mixed $mark): string
    {
        return $mark !== null && (string) $mark !== '' ? $this->fmtNum($mark) : '—';
    }

    private function returnOnEquity(float $pnl, float $margin): float
    {
        return $margin > 0 ? round($pnl / $margin * 100, 2) : 0.0;
    }

    /**
     * Map a DB closed position to the closed-table row shape.
     *
     * @return array<string, mixed>
     */
    private function mapClosedRow($p): array
    {
        $entryPrice = $this->entryPrice($p);
        [$pnl] = $this->computePnl($p, $entryPrice);
        $pnlF = $pnl !== null ? (float) $pnl : 0.0;
        $marginF = (float) ($p->margin ?? 0);

        return [
            'rowId' => $p->id,
            'sym' => $p->exchangeSymbol?->symbol?->token ?? $p->parsed_trading_pair ?? '—',
            'name' => $p->exchangeSymbol?->symbol?->name ?? '',
            'icon' => $p->exchangeSymbol?->symbol?->image_url,
            'side' => strtolower((string) $p->direction),
            'lev' => ((int) $p->leverage).'×',
            'entry' => $this->fmtNum($this->displayEntryPrice($entryPrice)),
            'exit' => $this->fmtNum($p->closing_price),
            'size' => $this->fmtNum($p->quantity),
            'pnl' => round($pnlF, 2),
            'roe' => $marginF > 0 ? round($pnlF / $marginF * 100, 2) : 0.0,
            'durH' => round(((int) ($p->held_seconds ?? 0)) / 3600, 2),
            'closedAgo' => $this->humanAge((int) ($p->closed_seconds_ago ?? 0)),
            'reason' => $this->closeReason($p),
        ];
    }

    /**
     * Infer a close reason from the position's filled exit orders.
     * Approximate — there's no stored reason column.
     */
    private function closeReason($p): string
    {
        $filledExit = $p->orders->first(fn ($o) => strtoupper((string) $o->status) === 'FILLED'
            && in_array(strtoupper((string) $o->type), ['PROFIT-LIMIT', 'PROFIT-MARKET'], true));
        if ($filledExit) {
            return 'tp';
        }

        $filledStop = $p->orders->first(fn ($o) => in_array(strtoupper((string) $o->status), ['FILLED', 'TRIGGERED'], true)
            && str_contains(strtoupper((string) $o->type), 'STOP'));
        if ($filledStop) {
            return 'stop';
        }

        return 'manual';
    }

    /**
     * Build the expandable detail panel payload from real orders.
     *
     * @return array<string, mixed>
     */
    private function mapDetail($p, bool $closed): array
    {
        $entryPrice = $this->entryPrice($p);
        [$pnl] = $this->computePnl($p, $entryPrice);

        return [
            ...$this->detailLifecycle($p, $closed),
            ...$this->detailMetrics($p, $closed, $entryPrice, $pnl),
            'orders' => $this->detailOrderRows($p),
        ];
    }

    /** @return array<string, mixed> */
    private function detailLifecycle(mixed $p, bool $closed): array
    {
        $heldSeconds = $closed ? (int) ($p->held_seconds ?? 0) : (int) ($p->opened_seconds_ago ?? 0);

        return [
            'closed' => $closed,
            'reason' => $closed ? $this->closeReason($p) : null,
            'exch' => $p->account?->apiSystem?->name ?? 'Exchange',
            'account' => $p->account?->name ?? '',
            'openedAt' => optional($p->opened_at ?? $p->created_at)?->timestamp,
            'closedAt' => $closed ? optional($p->closed_at)?->timestamp : null,
            'duration' => $this->humanAge($heldSeconds),
        ];
    }

    /** @return array<string, mixed> */
    private function detailMetrics(mixed $p, bool $closed, ?string $entryPrice, ?string $pnl): array
    {
        return [
            'leverage' => ((int) $p->leverage).'×',
            'margin' => (int) round((float) ($p->margin ?? 0)),
            'qty' => $this->fmtNum($p->quantity),
            'total' => max(1, (int) ($p->total_limit_orders ?? $p->orders->where('type', 'LIMIT')->count())),
            'openPrice' => $this->fmtNum($this->displayEntryPrice($entryPrice)),
            'markPrice' => $this->detailMarkPrice($p, $closed),
            'pnl' => $pnl !== null ? round((float) $pnl, 2) : 0.0,
            'tpPct' => $p->profit_percentage !== null ? (float) $p->profit_percentage : 0.0,
            'slPct' => $p->stop_market_percentage !== null ? (float) $p->stop_market_percentage : 0.0,
            'firstProfit' => $this->fmtNum($p->first_profit_price),
            'tf' => $p->indicators_timeframe ?? '—',
        ];
    }

    private function detailMarkPrice(mixed $position, bool $closed): string
    {
        if ($closed) {
            return $this->fmtNum($position->closing_price);
        }

        return $this->displayMark($position->exchangeSymbol?->mark_price);
    }

    /** @return array<int, array<string, int|string|null>> */
    private function detailOrderRows(mixed $position): array
    {
        return $position->orders->map(fn ($order): array => [
            'id' => $order->id,
            'type' => strtoupper((string) $order->type),
            'side' => strtoupper((string) $order->side),
            'status' => strtoupper((string) $order->status),
            'qty' => $this->fmtNum($order->quantity),
            'price' => $this->fmtNum($order->price),
            'opened' => optional($order->opened_at ?? $order->created_at)?->timestamp,
            'filled' => optional($order->filled_at)?->timestamp,
        ])->all();
    }

    public function data(Request $request): JsonResponse
    {
        $account = $this->comparisonAccount($request);
        $dbPositions = $this->comparisonPositions($account);
        [$pairs, $orphanOrders, $exchangeAsOfSeconds, $snapshotsPresent] = $this->comparisonData($account, $dbPositions);

        return response()->json([
            'account' => $this->comparisonAccountPayload($account),
            'pairs' => $pairs,
            'orphan_orders' => $orphanOrders,
            'exchange_as_of_seconds' => $exchangeAsOfSeconds,
            'exchange_snapshots_missing' => ! $snapshotsPresent,
        ]);
    }

    /**
     * @param  EloquentCollection<int, Position>  $dbPositions
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>, 2: int|null, 3: bool}
     */
    private function comparisonData(Account $account, EloquentCollection $dbPositions): array
    {
        [$exchangePositions, $exchangeOrders, $asOfSeconds, $snapshotsPresent, $oldestAt, $ordersAt] = $this->exchangeStateFromSnapshots($account);
        $tokenInfoBySymbol = $this->tokenInfoBySymbol($dbPositions);
        [$pairs, $matched] = $this->buildPairs($account, $dbPositions, $exchangePositions, $exchangeOrders, [], $tokenInfoBySymbol);
        $pairs = $this->applySnapshotFreshness($pairs, $dbPositions, $snapshotsPresent, $oldestAt, $ordersAt);
        $pairs = collect($pairs)->sortBy(fn (array $pair) => $pair['db']['opened_seconds_ago'] ?? PHP_INT_MAX)->values()->all();

        return [$pairs, $this->buildOrphanOrders($exchangeOrders, $matched), $asOfSeconds, $snapshotsPresent];
    }

    /** @return array{id: int, name: string, exchange: string, can_trade: bool} */
    private function comparisonAccountPayload(Account $account): array
    {
        return [
            'id' => $account->id,
            'name' => $account->name,
            'exchange' => $account->apiSystem?->name ?? 'Unknown',
            'can_trade' => (bool) $account->can_trade,
        ];
    }

    private function comparisonAccount(Request $request): Account
    {
        $request->validate(['account_id' => ['required', 'integer']]);

        return Account::with(['apiSystem'])
            ->where('id', $request->input('account_id'))
            ->where('user_id', Auth::id())
            ->firstOrFail();
    }

    /** @return EloquentCollection<int, Position> */
    private function comparisonPositions(Account $account): EloquentCollection
    {
        // Age is computed against the database clock in the connection's UTC frame.
        return $account->positions()
            ->whereIn('status', self::OPEN_POSITION_STATUSES)
            ->select('positions.*')
            ->selectRaw(DB::connection()->getDriverName() === 'mysql'
                ? 'TIMESTAMPDIFF(SECOND, COALESCE(opened_at, created_at), NOW()) as opened_seconds_ago'
                : "CAST(strftime('%s','now') - strftime('%s', COALESCE(opened_at, created_at)) AS INTEGER) as opened_seconds_ago")
            ->with([
                'exchangeSymbol.symbol',
                'orders' => fn ($query) => $query->whereIn('status', self::COMPARATOR_ORDER_STATUSES),
            ])
            ->get();
    }

    /**
     * @param  EloquentCollection<int, Position>  $dbPositions
     * @return array<string, array{token: string|null, token_image: string|null}>
     */
    private function tokenInfoBySymbol(EloquentCollection $dbPositions): array
    {
        $tokenInfoBySymbol = [];
        foreach ($dbPositions as $position) {
            $pair = $position->parsed_trading_pair ?? $position->exchangeSymbol?->symbol;
            if ($pair && $position->exchangeSymbol?->symbol) {
                $symbol = $position->exchangeSymbol->symbol;
                $tokenInfoBySymbol[$pair] = [
                    'token' => $symbol->token ?? null,
                    'token_image' => $symbol->image_url ?? null,
                ];
            }
        }

        return $tokenInfoBySymbol;
    }

    /**
     * @param  array<int, array<string, mixed>>  $pairs
     * @param  EloquentCollection<int, Position>  $dbPositions
     * @return array<int, array<string, mixed>>
     */
    private function applySnapshotFreshness(array $pairs, EloquentCollection $dbPositions, bool $snapshotsPresent, ?string $snapshotOldestAt, ?string $ordersSnapshotAt): array
    {
        if (! $snapshotsPresent) {
            return array_map(fn (array $pair): array => $this->unverifiedPair($pair), $pairs);
        }

        if ($snapshotOldestAt !== null) {
            $pairs = array_map(
                fn (array $pair): array => $this->protectRecentPositionPair($pair, $dbPositions, $snapshotOldestAt),
                $pairs,
            );
        }

        if ($ordersSnapshotAt !== null) {
            $ordersSnapTs = strtotime($ordersSnapshotAt);
            $pairs = array_map(
                fn (array $pair): array => $this->protectRecentOrderPair($pair, $ordersSnapTs),
                $pairs,
            );
        }

        return $pairs;
    }

    /**
     * @param  array<string, mixed>  $pair
     * @return array<string, mixed>
     */
    private function unverifiedPair(array $pair): array
    {
        $pair['status'] = self::PAIR_STATUS_UNVERIFIED;
        $pair['position_drift_fields'] = [];
        $pair['orders'] = array_map(
            fn (array $order): array => array_merge($order, ['status' => self::PAIR_STATUS_UNVERIFIED, 'drift_fields' => []]),
            $pair['orders'],
        );
        $pair['order_counts'] = $this->countStatuses($pair['orders']);

        return $pair;
    }

    /**
     * A position newer than the snapshot cannot be proven absent from the exchange.
     *
     * @param  array<string, mixed>  $pair
     * @param  EloquentCollection<int, Position>  $dbPositions
     * @return array<string, mixed>
     */
    private function protectRecentPositionPair(array $pair, EloquentCollection $dbPositions, string $snapshotOldestAt): array
    {
        $dbId = $pair['db']['id'] ?? null;
        if (! $this->openedAfterSnapshot($dbId, $dbPositions, $snapshotOldestAt)) {
            return $pair;
        }

        $pair['orders'] = array_map(
            fn (array $order): array => $order['status'] === self::PAIR_STATUS_DB_ONLY
                ? array_merge($order, ['status' => self::PAIR_STATUS_UNVERIFIED, 'drift_fields' => []])
                : $order,
            $pair['orders'],
        );
        $pair['order_counts'] = $this->countStatuses($pair['orders']);

        if ($pair['status'] === self::PAIR_STATUS_DB_ONLY) {
            $pair['status'] = self::PAIR_STATUS_UNVERIFIED;
        } else {
            $pair = $this->restorePairStatusIfDriftCleared($pair);
        }

        return $pair;
    }

    /** @param EloquentCollection<int, Position> $dbPositions */
    private function openedAfterSnapshot(mixed $dbId, EloquentCollection $dbPositions, string $snapshotOldestAt): bool
    {
        $openedAt = $dbId !== null
            ? (string) optional($dbPositions->firstWhere('id', $dbId))->getRawOriginal('opened_at')
            : '';

        return $openedAt !== '' && strtotime($openedAt) >= strtotime($snapshotOldestAt) - 60;
    }

    /**
     * @param  array<string, mixed>  $pair
     * @return array<string, mixed>
     */
    private function protectRecentOrderPair(array $pair, int|false $ordersSnapTs): array
    {
        $changed = false;
        $pair['orders'] = array_map(function (array $order) use ($ordersSnapTs, &$changed): array {
            $updatedAt = $order['db']['updated_at'] ?? null;
            if ($order['status'] === self::PAIR_STATUS_DRIFT
                && $updatedAt !== null && $updatedAt !== ''
                && strtotime($updatedAt) > $ordersSnapTs + 60) {
                $changed = true;

                return array_merge($order, ['status' => self::PAIR_STATUS_UNVERIFIED, 'drift_fields' => []]);
            }

            return $order;
        }, $pair['orders']);

        if (! $changed) {
            return $pair;
        }

        $pair['order_counts'] = $this->countStatuses($pair['orders']);

        return $this->restorePairStatusIfDriftCleared($pair);
    }

    /**
     * @param  array<string, mixed>  $pair
     * @return array<string, mixed>
     */
    private function restorePairStatusIfDriftCleared(array $pair): array
    {
        if ($pair['status'] === self::PAIR_STATUS_DRIFT
            && ! $this->hasOrderDrift($pair['orders'])
            && empty($pair['position_drift_fields'])) {
            $pair['status'] = self::PAIR_STATUS_SYNCED;
        }

        return $pair;
    }

    /**
     * The exchange-side picture from the engine's api_snapshots: open
     * positions (keyed map → list), open orders merged with algo orders
     * when that snapshot exists, and the age of the oldest snapshot used.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>, 2: int|null, 3: bool, 4: string|null, 5: string|null}
     */
    private function exchangeStateFromSnapshots(Account $account): array
    {
        $positions = ApiSnapshot::getFrom($account, 'account-positions');
        $openOrders = ApiSnapshot::getFrom($account, 'account-open-orders');
        $algoOrders = ApiSnapshot::getFrom($account, 'account-algo-orders');

        $this->algoSnapshotPresent = is_array($algoOrders);
        $snapshotsPresent = is_array($positions) || is_array($openOrders);

        $exchangePositions = collect($this->snapshotRows($positions))
            ->filter(fn ($pos) => abs((float) ($pos['positionAmt'] ?? $pos['size'] ?? $pos['contracts'] ?? 0)) > 0)
            ->values()
            ->all();

        $exchangeOrders = array_merge($this->snapshotRows($openOrders), $this->snapshotRows($algoOrders));

        [$age, $oldestAt, $ordersSnapshotAt] = $snapshotsPresent
            ? $this->snapshotFreshness($account)
            : [null, null, null];

        return [$exchangePositions, $exchangeOrders, $age, $snapshotsPresent, $oldestAt, $ordersSnapshotAt];
    }

    /** @return array<int, mixed> */
    private function snapshotRows(mixed $snapshot): array
    {
        return is_array($snapshot) ? array_values($snapshot) : [];
    }

    /** @return array{0: int|null, 1: string|null, 2: string|null} */
    private function snapshotFreshness(Account $account): array
    {
        // Oldest of the snapshots used — raw, for same-frame comparison
        // against positions.opened_at (both written by the trading apps).
        $oldestAt = $account->apiSnapshots()
            ->whereIn('canonical', ['account-positions', 'account-open-orders'])
            ->min('updated_at');
        $oldestAt = $oldestAt !== null ? (string) $oldestAt : null;

        $age = rescue(fn () => $account->apiSnapshots()
            ->whereIn('canonical', ['account-positions', 'account-open-orders'])
            ->selectRaw(DB::connection()->getDriverName() === 'mysql'
                ? 'MAX(TIMESTAMPDIFF(SECOND, updated_at, NOW())) as age'
                : "MAX(CAST(strftime('%s','now') - strftime('%s', updated_at) AS INTEGER)) as age")
            ->value('age'), null, false);
        $age = $age !== null ? (int) $age : null;

        // Freshness of the exchange ORDER picture specifically (open + algo
        // orders) — used below to ignore field-drift on an order that was
        // re-placed AFTER this snapshot was taken.
        $ordersSnapshotAt = $account->apiSnapshots()
            ->whereIn('canonical', ['account-open-orders', 'account-algo-orders'])
            ->min('updated_at');
        $ordersSnapshotAt = $ordersSnapshotAt !== null ? (string) $ordersSnapshotAt : null;

        return [$age, $oldestAt, $ordersSnapshotAt];
    }

    /**
     * @param  EloquentCollection<int, Position>  $dbPositions
     * @param  array<int, RawExchangeData>  $exchangePositions
     * @param  array<int, RawExchangeData>  $exchangeOrders
     * @param  array<string, array<string, mixed>>  $symbolConfigs
     * @param  array<string, array{token: string|null, token_image: string|null}>  $tokenInfoBySymbol
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, bool>}
     */
    private function buildPairs(Account $account, EloquentCollection $dbPositions, array $exchangePositions, array $exchangeOrders, array $symbolConfigs = [], array $tokenInfoBySymbol = []): array
    {
        [$exchangeOrdersByClientId, $exchangeOrdersByExchangeId] = $this->exchangeOrderIndices($exchangeOrders);
        $context = [
            'account_margin_mode' => strtoupper((string) ($account->margin_mode ?? '')),
            'exchange_orders' => $exchangeOrders,
            'exchange_orders_by_client_id' => $exchangeOrdersByClientId,
            'exchange_orders_by_exchange_id' => $exchangeOrdersByExchangeId,
            'symbol_configs' => $symbolConfigs,
            'token_info_by_symbol' => $tokenInfoBySymbol,
        ];
        $exchangeByKey = $this->exchangePositionsByKey($exchangePositions);
        $matched = [];
        $seenKeys = [];
        $pairs = $this->buildDatabasePairs($dbPositions, $exchangeByKey, $context, $matched, $seenKeys);
        $pairs = array_merge($pairs, $this->buildExchangeOnlyPairs($exchangeByKey, $context, $matched, $seenKeys));

        return [$pairs, $matched];
    }

    /**
     * @param  iterable<int, Position>  $dbPositions
     * @param  array<string, RawExchangeData>  $exchangeByKey
     * @param  array<string, mixed>  $context
     * @param  array<int, bool>  $matched
     * @param  array<string, bool>  $seenKeys
     * @return array<int, array<string, mixed>>
     */
    private function buildDatabasePairs(iterable $dbPositions, array $exchangeByKey, array $context, array &$matched, array &$seenKeys): array
    {
        $pairs = [];
        foreach ($dbPositions as $dbPos) {
            $symbol = $dbPos->parsed_trading_pair ?? data_get($dbPos->exchangeSymbol, 'symbol');
            if (! $symbol) {
                continue;
            }
            $direction = $dbPos->direction;
            $key = $symbol.'|'.$direction;
            $seenKeys[$key] = true;
            $pairs[] = $this->buildPair($symbol, $direction, $dbPos, $exchangeByKey[$key] ?? null, $context, $matched);
        }

        return $pairs;
    }

    /**
     * @param  array<string, RawExchangeData>  $exchangeByKey
     * @param  array<string, mixed>  $context
     * @param  array<int, bool>  $matched
     * @param  array<string, bool>  $seenKeys
     * @return array<int, array<string, mixed>>
     */
    private function buildExchangeOnlyPairs(array $exchangeByKey, array $context, array &$matched, array $seenKeys): array
    {
        $pairs = [];
        foreach ($exchangeByKey as $key => $exchangePosition) {
            if (isset($seenKeys[$key])) {
                continue;
            }
            [$symbol, $direction] = explode('|', $key, 2);
            $pairs[] = $this->buildPair($symbol, $direction, null, $exchangePosition, $context, $matched);
        }

        return $pairs;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<int, bool>  $matched
     * @return array<string, mixed>
     */
    private function buildPair(string $symbol, string $direction, mixed $dbPos, ?array $exchPos, array $context, array &$matched): array
    {
        $position = $this->buildPairPositionSnapshot(
            $dbPos,
            $exchPos,
            $context['account_margin_mode'],
            $context['symbol_configs'][$symbol] ?? null,
            $direction,
        );
        $orders = $this->buildPairOrders(
            $dbPos,
            $symbol,
            $direction,
            $context['exchange_orders'],
            $context['exchange_orders_by_client_id'],
            $context['exchange_orders_by_exchange_id'],
            $matched,
        );
        $orders = $this->suppressTransientOrderDrift($orders, $this->isTransientPosition($position['db'], $position['is_active']));

        return $this->buildPairPayload($symbol, $direction, $dbPos, $context, $position, $orders);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $position
     * @param  array<int, PairOrderData>  $orders
     * @return array<string, mixed>
     */
    private function buildPairPayload(string $symbol, string $direction, mixed $dbPos, array $context, array $position, array $orders): array
    {
        $status = $this->resolvePairStatus(
            $position['db'],
            $position['exchange'],
            $position['is_active'],
            $this->hasPairDrift($position['drift_fields'], $this->hasOrderDrift($orders)),
        );

        return [
            'symbol' => $symbol,
            'token' => $context['token_info_by_symbol'][$symbol]['token'] ?? null,
            'token_image' => $context['token_info_by_symbol'][$symbol]['token_image'] ?? null,
            'direction' => $direction,
            'status' => $status,
            'db' => $position['db'],
            'exchange' => $position['exchange'],
            'position_drift_fields' => $position['drift_fields'],
            'orders' => $orders,
            'order_counts' => $this->countStatuses($orders),
            'pnl_projections' => $this->computePnlProjections($dbPos),
        ];
    }

    /** @param array<int, array<string, mixed>> $exchangePositions
     * @return array<string, array<string, mixed>>
     */
    private function exchangePositionsByKey(array $exchangePositions): array
    {
        $byKey = [];
        foreach ($exchangePositions as $position) {
            $symbol = $position['symbol'] ?? null;
            if (! $symbol) {
                continue;
            }

            $byKey[$symbol.'|'.$this->normalizeDirection($position)] = $position;
        }

        return $byKey;
    }

    /** @param array<int, array<string, mixed>> $exchangeOrders
     * @return array{0: array<string, int>, 1: array<string, int>}
     */
    private function exchangeOrderIndices(array $exchangeOrders): array
    {
        $byClientId = [];
        $byExchangeId = [];
        foreach ($exchangeOrders as $index => $order) {
            $clientId = $order['clientOrderId'] ?? $order['orderLinkId'] ?? $order['clientAlgoId'] ?? null;
            $exchangeId = $order['orderId'] ?? $order['id'] ?? $order['algoId'] ?? null;
            $this->indexExchangeOrder($byClientId, $clientId, $index);
            $this->indexExchangeOrder($byExchangeId, $exchangeId, $index);
        }

        return [$byClientId, $byExchangeId];
    }

    /** @param array<string, int> $indices */
    private function indexExchangeOrder(array &$indices, mixed $id, int $index): void
    {
        if ($id !== null && $id !== '') {
            $indices[(string) $id] = $index;
        }
    }

    /**
     * @param  RawExchangeData|null  $exchPos
     * @param  RawExchangeData|null  $symbolConfig
     * @return array{db: PositionData|null, exchange: PositionData|null, is_active: bool, drift_fields: array<int, string>}
     */
    private function buildPairPositionSnapshot(
        ?Position $dbPos,
        ?array $exchPos,
        string $accountMarginMode,
        ?array $symbolConfig,
        string $direction,
    ): array {
        $dbPosData = $dbPos ? $this->dbPositionData($dbPos, $accountMarginMode) : null;
        $dbPosData = $this->weightedPairPositionData($dbPosData, $dbPos, $direction);
        $exchPosData = $exchPos ? $this->exchangePositionData($exchPos, $symbolConfig) : null;
        $dbIsActive = $this->isActivePairPosition($dbPosData);

        return [
            'db' => $dbPosData,
            'exchange' => $exchPosData,
            'is_active' => $dbIsActive,
            'drift_fields' => $this->findPositionDriftFields($dbPosData, $exchPosData, $dbIsActive),
        ];
    }

    /**
     * @param  PositionData|null  $dbPosData
     * @return PositionData|null
     */
    private function weightedPairPositionData(?array $dbPosData, ?Position $dbPos, string $direction): ?array
    {
        if ($dbPosData === null || $dbPos === null) {
            return $dbPosData;
        }

        $avg = $this->computeWeightedAvgEntry($dbPos, $direction);
        if ($avg !== null) {
            $dbPosData['entry_price'] = $avg;
        }

        return $dbPosData;
    }

    /** @param PositionData|null $dbPosData */
    private function isActivePairPosition(?array $dbPosData): bool
    {
        return $dbPosData !== null && ($dbPosData['status'] ?? null) === 'active';
    }

    /**
     * @param  PositionData|null  $dbPosData
     * @param  PositionData|null  $exchPosData
     * @return array<int, string>
     */
    private function findPositionDriftFields(?array $dbPosData, ?array $exchPosData, bool $dbIsActive): array
    {
        if (! $this->canComparePairPositions($dbPosData, $exchPosData, $dbIsActive)) {
            return [];
        }

        $positionDriftFields = [];
        foreach (self::POSITION_DRIFT_FIELDS as $field) {
            if (! $this->valuesEqual($field, $dbPosData[$field] ?? null, $exchPosData[$field] ?? null)) {
                $positionDriftFields[] = $field;
            }
        }

        return $positionDriftFields;
    }

    /**
     * @param  PositionData|null  $dbPosData
     * @param  PositionData|null  $exchPosData
     */
    private function canComparePairPositions(?array $dbPosData, ?array $exchPosData, bool $dbIsActive): bool
    {
        return $dbPosData !== null && $exchPosData !== null && $dbIsActive;
    }

    /**
     * @param  array<int, RawExchangeData>  $exchangeOrders
     * @param  array<string, int>  $exchOrdersByCid
     * @param  array<string, int>  $exchOrdersByXid
     * @param  array<int, bool>  $matchedExchangeOrderIndices
     * @return array<int, PairOrderData>
     */
    private function buildPairOrders(
        ?Position $dbPos,
        string $symbol,
        string $direction,
        array $exchangeOrders,
        array $exchOrdersByCid,
        array $exchOrdersByXid,
        array &$matchedExchangeOrderIndices,
    ): array {
        $orders = $this->buildDatabaseOrderPairs($dbPos, $exchangeOrders, $exchOrdersByCid, $exchOrdersByXid, $matchedExchangeOrderIndices);

        return $this->appendUnmatchedExchangeOrderPairs(
            $orders,
            $symbol,
            $direction,
            $dbPos !== null,
            $exchangeOrders,
            $matchedExchangeOrderIndices,
        );
    }

    /**
     * @param  array<int, RawExchangeData>  $exchangeOrders
     * @param  array<string, int>  $exchOrdersByCid
     * @param  array<string, int>  $exchOrdersByXid
     * @param  array<int, bool>  $matchedExchangeOrderIndices
     * @return array<int, PairOrderData>
     */
    private function buildDatabaseOrderPairs(
        ?Position $dbPos,
        array $exchangeOrders,
        array $exchOrdersByCid,
        array $exchOrdersByXid,
        array &$matchedExchangeOrderIndices,
    ): array {
        if ($dbPos === null) {
            return [];
        }

        $orders = [];
        foreach ($dbPos->orders as $dbOrder) {
            $orders[] = $this->buildDatabaseOrderPair(
                $dbOrder,
                $exchangeOrders,
                $exchOrdersByCid,
                $exchOrdersByXid,
                $matchedExchangeOrderIndices,
            );
        }

        return $orders;
    }

    /**
     * @param  array<int, RawExchangeData>  $exchangeOrders
     * @param  array<string, int>  $exchOrdersByCid
     * @param  array<string, int>  $exchOrdersByXid
     * @param  array<int, bool>  $matchedExchangeOrderIndices
     * @return PairOrderData
     */
    private function buildDatabaseOrderPair(
        Order $dbOrder,
        array $exchangeOrders,
        array $exchOrdersByCid,
        array $exchOrdersByXid,
        array &$matchedExchangeOrderIndices,
    ): array {
        $exchIdx = $this->findExchangeOrderIndex($dbOrder, $exchOrdersByCid, $exchOrdersByXid);
        $exchOrder = $exchIdx !== null ? $exchangeOrders[$exchIdx] : null;
        if ($exchIdx !== null) {
            $matchedExchangeOrderIndices[$exchIdx] = true;
        }

        return $this->buildOrderPair(
            $this->dbOrderData($dbOrder),
            $exchOrder ? $this->exchangeOrderData($exchOrder) : null,
        );
    }

    /**
     * @param  array<string, int>  $exchOrdersByCid
     * @param  array<string, int>  $exchOrdersByXid
     */
    private function findExchangeOrderIndex(Order $dbOrder, array $exchOrdersByCid, array $exchOrdersByXid): ?int
    {
        $cid = $dbOrder->client_order_id;
        if ($cid && isset($exchOrdersByCid[(string) $cid])) {
            return $exchOrdersByCid[(string) $cid];
        }

        $xid = $dbOrder->exchange_order_id;
        if ($xid && isset($exchOrdersByXid[(string) $xid])) {
            return $exchOrdersByXid[(string) $xid];
        }

        return null;
    }

    /**
     * @param  array<int, PairOrderData>  $orders
     * @param  array<int, RawExchangeData>  $exchangeOrders
     * @param  array<int, bool>  $matchedExchangeOrderIndices
     * @return array<int, PairOrderData>
     */
    private function appendUnmatchedExchangeOrderPairs(
        array $orders,
        string $symbol,
        string $direction,
        bool $hasDbPosition,
        array $exchangeOrders,
        array &$matchedExchangeOrderIndices,
    ): array {
        foreach ($exchangeOrders as $idx => $exchOrder) {
            if (isset($matchedExchangeOrderIndices[$idx])) {
                continue;
            }
            if (! $this->isExchangeOrderForPair($exchOrder, $symbol, $direction, $hasDbPosition)) {
                continue;
            }

            $matchedExchangeOrderIndices[$idx] = true;
            $orders[] = $this->buildOrderPair(null, $this->exchangeOrderData($exchOrder));
        }

        return $orders;
    }

    /** @param RawExchangeData $exchOrder */
    private function isExchangeOrderForPair(array $exchOrder, string $symbol, string $direction, bool $hasDbPosition): bool
    {
        if (($exchOrder['symbol'] ?? null) !== $symbol) {
            return false;
        }

        $exchDirection = $this->inferOrderDirection($exchOrder);
        if ($exchDirection !== null) {
            return $exchDirection === $direction;
        }

        return $hasDbPosition;
    }

    /**
     * @param  array<int, PairOrderData>  $orders
     * @return array<int, PairOrderData>
     */
    private function suppressTransientOrderDrift(array $orders, bool $isTransient): array
    {
        if (! $isTransient) {
            return $orders;
        }

        foreach ($orders as &$order) {
            $order['status'] = self::PAIR_STATUS_SYNCED;
            $order['drift_fields'] = [];
        }
        unset($order);

        return $orders;
    }

    /** @param PositionData|null $dbPosData */
    private function isTransientPosition(?array $dbPosData, bool $dbIsActive): bool
    {
        return $dbPosData !== null && ! $dbIsActive;
    }

    /**
     * @param  array<int, PairOrderData>  $orders
     */
    private function hasOrderDrift(array $orders): bool
    {
        foreach ($orders as $order) {
            if (! in_array($order['status'], [self::PAIR_STATUS_SYNCED, self::PAIR_STATUS_UNVERIFIED], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $positionDriftFields
     */
    private function hasPairDrift(array $positionDriftFields, bool $hasOrderDrift): bool
    {
        if ($positionDriftFields !== []) {
            return true;
        }

        return $hasOrderDrift;
    }

    /**
     * @param  PositionData|null  $dbPosData
     * @param  PositionData|null  $exchPosData
     */
    private function resolveDatabasePairStatus(?array $dbPosData, ?array $exchPosData, bool $dbIsActive): ?string
    {
        if ($dbPosData === null) {
            return null;
        }
        if (! $dbIsActive) {
            return self::PAIR_STATUS_TRANSIENT;
        }
        if ($exchPosData === null) {
            return self::PAIR_STATUS_DB_ONLY;
        }

        return null;
    }

    /**
     * @param  PositionData|null  $dbPosData
     * @param  PositionData|null  $exchPosData
     */
    private function resolveExchangeOnlyPairStatus(?array $dbPosData, ?array $exchPosData): ?string
    {
        if ($dbPosData !== null || $exchPosData === null) {
            return null;
        }

        return self::PAIR_STATUS_EXCHANGE_ONLY;
    }

    /**
     * @param  PositionData|null  $dbPosData
     * @param  PositionData|null  $exchPosData
     */
    private function resolvePairStatus(
        ?array $dbPosData,
        ?array $exchPosData,
        bool $dbIsActive,
        bool $hasPairDrift,
    ): string {
        $status = $this->resolveDatabasePairStatus($dbPosData, $exchPosData, $dbIsActive);
        if ($status !== null) {
            return $status;
        }

        $status = $this->resolveExchangeOnlyPairStatus($dbPosData, $exchPosData);
        if ($status !== null) {
            return $status;
        }

        if ($hasPairDrift) {
            return self::PAIR_STATUS_DRIFT;
        }

        return self::PAIR_STATUS_SYNCED;
    }

    private function buildOrderPair(?array $db, ?array $exch): array
    {
        $singleSidedPair = $this->singleSidedOrderPair($db, $exch);
        if ($singleSidedPair !== null) {
            return $singleSidedPair;
        }

        $driftFields = $this->orderPairDriftFields($db, $exch);

        return [
            'status' => $driftFields === [] ? self::PAIR_STATUS_SYNCED : self::PAIR_STATUS_DRIFT,
            'db' => $db,
            'exchange' => $exch,
            'drift_fields' => $driftFields,
        ];
    }

    /**
     * @param  RawExchangeData|null  $db
     * @param  RawExchangeData|null  $exch
     * @return PairOrderData|null
     */
    private function singleSidedOrderPair(?array $db, ?array $exch): ?array
    {
        if ($db) {
            if (! $exch) {
                return $this->databaseOnlyOrderPair($db);
            }

            return null;
        }

        if ($exch) {
            return $this->exchangeOnlyOrderPair($exch);
        }

        return null;
    }

    /**
     * @param  RawExchangeData  $db
     * @return PairOrderData
     */
    private function databaseOnlyOrderPair(array $db): array
    {
        // FILLED orders are historical — they no longer live on the
        // open-orders endpoint. Their absence is expected, not drift.
        if (strtoupper((string) ($db['status'] ?? '')) === 'FILLED') {
            return ['status' => self::PAIR_STATUS_SYNCED, 'db' => $db, 'exchange' => null, 'drift_fields' => []];
        }

        // Algo-endpoint types (stop-losses etc.) can't appear in the
        // open-orders snapshot at all. With no algo snapshot to check
        // against, their absence proves nothing — mark unverified.
        // Once an algo snapshot exists (even empty), absence is real.
        if (! $this->algoSnapshotPresent) {
            if (in_array(strtoupper((string) ($db['type'] ?? '')), self::ALGO_ENDPOINT_TYPES, true)) {
                return ['status' => self::PAIR_STATUS_UNVERIFIED, 'db' => $db, 'exchange' => null, 'drift_fields' => []];
            }
        }

        return ['status' => self::PAIR_STATUS_DB_ONLY, 'db' => $db, 'exchange' => null, 'drift_fields' => []];
    }

    /**
     * @param  RawExchangeData  $exch
     * @return PairOrderData
     */
    private function exchangeOnlyOrderPair(array $exch): array
    {
        return ['status' => self::PAIR_STATUS_EXCHANGE_ONLY, 'db' => null, 'exchange' => $exch, 'drift_fields' => []];
    }

    /**
     * @param  RawExchangeData|null  $db
     * @param  RawExchangeData|null  $exch
     * @return array<int, string>
     */
    private function orderPairDriftFields(?array $db, ?array $exch): array
    {
        if ($db === null) {
            return [];
        }
        if ($exch === null) {
            return [];
        }

        // Close-position plan orders (TP/SL on BitGet, certain Bybit algos):
        //  - qty: exchange reports 0 because the order will close whatever
        //    is open at trigger time — no bound size.
        //  - side: BitGet hedge-mode TP/SL encodes the *position* side
        //    (sell = short) rather than the *action* side (buy to close
        //    short), so the literal value disagrees with the DB's
        //    action-side convention. Same logical order, different
        //    encoding — skip the drift check.
        $isClosePos = $this->isClosePositionType(isset($db['type']) ? (string) $db['type'] : null);
        $skipQty = $this->shouldSkipClosePositionQuantity($isClosePos, $exch);

        return $this->collectOrderPairDriftFields($db, $exch, $skipQty, $isClosePos);
    }

    /**
     * @param  RawExchangeData  $db
     * @param  RawExchangeData  $exch
     * @return array<int, string>
     */
    private function collectOrderPairDriftFields(array $db, array $exch, bool $skipQty, bool $skipSide): array
    {
        $driftFields = [];
        foreach (self::ORDER_DRIFT_FIELDS as $field) {
            if ($this->orderPairFieldIsDrift($field, $db, $exch, $skipQty, $skipSide)) {
                $driftFields[] = $field;
            }
        }

        return $driftFields;
    }

    /** @param RawExchangeData $exch */
    private function shouldSkipClosePositionQuantity(bool $isClosePos, array $exch): bool
    {
        if (! $isClosePos) {
            return false;
        }
        $quantity = (string) ($exch['quantity'] ?? '');
        if ($quantity === '') {
            return false;
        }
        if (! is_numeric($quantity)) {
            return false;
        }

        return bccomp($quantity, '0', 18) === 0;
    }

    /**
     * @param  RawExchangeData  $db
     * @param  RawExchangeData  $exch
     */
    private function orderPairFieldIsDrift(string $field, array $db, array $exch, bool $skipQty, bool $skipSide): bool
    {
        if ($field === 'quantity' && $skipQty) {
            return false;
        }
        if ($field === 'side' && $skipSide) {
            return false;
        }

        return ! $this->valuesEqual($field, $db[$field] ?? null, $exch[$field] ?? null);
    }

    private function isClosePositionType(?string $type): bool
    {
        if ($type === null) {
            return false;
        }

        return in_array(strtoupper($type), self::CLOSE_POSITION_TYPES, true);
    }

    private function buildOrphanOrders(array $exchangeOrders, array $matched): array
    {
        $orphans = [];
        foreach ($exchangeOrders as $idx => $exchOrder) {
            if (isset($matched[$idx])) {
                continue;
            }
            $orphans[] = $this->exchangeOrderData($exchOrder);
        }

        return $orphans;
    }

    private function dbPositionData($pos, string $accountMarginMode): array
    {
        return [
            'id' => $pos->id,
            'status' => strtolower((string) $pos->status),
            'quantity' => (string) $pos->quantity,
            'entry_price' => (string) $pos->opening_price,
            'leverage' => (string) $pos->leverage,
            'margin' => (string) $pos->margin,
            'margin_mode' => $accountMarginMode,
            'opened_seconds_ago' => $pos->opened_seconds_ago !== null ? (int) $pos->opened_seconds_ago : null,
            'unrealized_pnl' => null,
        ];
    }

    private function exchangePositionData(array $pos, ?array $symbolConfig = null): array
    {
        // Per-exchange field name normalisation — Binance, BitGet, Bybit
        // all report the same logical fields under different keys. Order
        // the fallback chain so the most specific (per-exchange) name wins,
        // then fall back to canonical-ish names. Adding a new exchange
        // means appending its native key to each list, not branching here.
        $leverage = $pos['leverage'] ?? $symbolConfig['leverage'] ?? null;
        $margin = $pos['isolatedMargin']
            ?? $pos['positionBalance']
            ?? $pos['marginSize']      // BitGet
            ?? $pos['margin']
            ?? null;
        $marginMode = $pos['marginType']
            ?? $pos['marginMode']
            ?? $symbolConfig['marginType']
            ?? null;

        // Cross-margin positions don't have a meaningful per-position
        // margin to compare. Binance reports isolatedMargin=0 (so the
        // bccomp branch nulls it); BitGet reports `marginSize` = the
        // current maintenance-margin REQUIREMENT, which is a different
        // concept than the DB's allocated-margin column. Null both.
        $margin = $this->comparableExchangeMargin($margin, $marginMode);

        return [
            'id' => null,
            'quantity' => (string) abs((float) ($pos['positionAmt'] ?? $pos['size'] ?? $pos['contracts'] ?? $pos['total'] ?? 0)),
            // Entry-price field varies: Binance entryPrice, Bybit avgPrice,
            // BitGet openPriceAvg. Fall through in that order.
            'entry_price' => (string) ($pos['entryPrice'] ?? $pos['avgPrice'] ?? $pos['openPriceAvg'] ?? 0),
            'leverage' => $leverage !== null ? (string) $leverage : null,
            'margin' => $margin !== null ? (string) $margin : null,
            'margin_mode' => $marginMode !== null ? strtoupper((string) $marginMode) : null,
            'unrealized_pnl' => (string) ($pos['unRealizedProfit'] ?? $pos['unrealisedPnl'] ?? $pos['unrealizedPL'] ?? 0),
        ];
    }

    private function comparableExchangeMargin(mixed $margin, mixed $marginMode): mixed
    {
        if (strtoupper((string) ($marginMode ?? '')) === 'CROSSED') {
            return null;
        }
        if ($margin !== null && is_numeric((string) $margin) && bccomp((string) $margin, '0', 18) === 0) {
            return null;
        }

        return $margin;
    }

    /**
     * Weighted-average entry price across a position's executed entry-side
     * orders (BUY for LONG, SELL for SHORT). Mirrors what the exchange
     * reports as the running position entry price so the comparator has a
     * fair DB-side value to check against.
     *
     * FILLED rows carry their executed qty/price directly. PARTIALLY_FILLED
     * rows are trickier — only the original ladder qty is persisted, not
     * the executed portion. The gap between the position's total quantity
     * (exchange-truth) and the sum of FILLED entry rows IS the executed
     * portion of the partial rungs; credit it back at each rung's limit
     * price, in id order (creation order = ladder ascending). Otherwise
     * positions mid-ladder-fill flag false entry-price drift.
     *
     * Null when there are no executed entry orders (callers should fall
     * back to the stored opening_price snapshot).
     */
    private function computeWeightedAvgEntry($dbPos, string $direction): ?string
    {
        $entrySide = strtoupper($direction) === 'LONG' ? 'BUY' : 'SELL';
        [$totalQty, $weightedCost] = $this->filledEntryTotals($dbPos, $entrySide);

        $posQty = (string) ($dbPos->quantity ?? '0');
        [$totalQty, $weightedCost] = $this->partialEntryTotals($dbPos, $entrySide, $posQty, $totalQty, $weightedCost);

        if (bccomp($totalQty, '0', 18) <= 0) {
            return null;
        }

        return $this->trim(bcdiv($weightedCost, $totalQty, 18));
    }

    /**
     * @param  numeric-string  $totalQty
     * @param  numeric-string  $weightedCost
     * @return array{0: numeric-string, 1: numeric-string}
     */
    private function partialEntryTotals(mixed $dbPos, string $entrySide, string $posQty, string $totalQty, string $weightedCost): array
    {
        if (! is_numeric($posQty) || bccomp($posQty, $totalQty, 18) <= 0) {
            return [$totalQty, $weightedCost];
        }

        $remaining = bcsub($posQty, $totalQty, 18);

        return $this->accumulatePartialOrders($dbPos, $entrySide, $remaining, $totalQty, $weightedCost);
    }

    /**
     * @param  numeric-string  $remaining
     * @param  numeric-string  $totalQty
     * @param  numeric-string  $weightedCost
     * @return array{0: numeric-string, 1: numeric-string}
     */
    private function accumulatePartialOrders(mixed $dbPos, string $entrySide, string $remaining, string $totalQty, string $weightedCost): array
    {
        $partials = $dbPos->orders
            ->filter(fn ($order): bool => $this->isPartialEntryOrder($order, $entrySide))
            ->sortBy('id');

        foreach ($partials as $order) {
            if (bccomp($remaining, '0', 18) <= 0) {
                break;
            }

            $rungQty = (string) $order->quantity;
            $rungPrice = (string) $order->price;
            if (! is_numeric($rungQty) || ! is_numeric($rungPrice)) {
                continue;
            }

            $take = $this->partialRungAmount($remaining, $rungQty);
            $totalQty = bcadd($totalQty, $take, 18);
            $weightedCost = bcadd($weightedCost, bcmul($take, $rungPrice, 18), 18);
            $remaining = bcsub($remaining, $take, 18);
        }

        return [$totalQty, $weightedCost];
    }

    /**
     * @param  numeric-string  $remaining
     * @param  numeric-string  $rungQty
     * @return numeric-string
     */
    private function partialRungAmount(string $remaining, string $rungQty): string
    {
        return bccomp($remaining, $rungQty, 18) <= 0 ? $remaining : $rungQty;
    }

    private function isPartialEntryOrder(mixed $order, string $entrySide): bool
    {
        return strtoupper((string) $order->status) === 'PARTIALLY_FILLED'
            && in_array(strtoupper((string) $order->type), ['MARKET', 'LIMIT'], true)
            && strtoupper((string) $order->side) === $entrySide;
    }

    /** @return array{0: numeric-string, 1: numeric-string} */
    private function filledEntryTotals(mixed $dbPos, string $entrySide): array
    {
        $totalQty = '0';
        $weightedCost = '0';
        foreach ($dbPos->orders as $o) {
            if (! $this->isFilledEntryOrder($o, $entrySide)) {
                continue;
            }
            $q = (string) $o->quantity;
            $p = (string) $o->price;
            if (! is_numeric($q) || ! is_numeric($p)) {
                continue;
            }
            $totalQty = bcadd($totalQty, $q, 18);
            $weightedCost = bcadd($weightedCost, bcmul($q, $p, 18), 18);
        }

        return [$totalQty, $weightedCost];
    }

    private function isFilledEntryOrder(mixed $order, string $entrySide): bool
    {
        return strtoupper((string) $order->side) === $entrySide
            && in_array(strtoupper((string) $order->type), ['MARKET', 'LIMIT'], true)
            && strtoupper((string) $order->status) === 'FILLED';
    }

    /**
     * Relative equality for price-like fields where the exchange reports
     * finer precision than we persist. Uses float math because the tolerance
     * (1 basis point) is well above double-precision rounding error, and
     * floats sidestep bccomp's strict well-formedness checks on edge inputs.
     */
    private function numericWithinTolerance(string $a, string $b): bool
    {
        if (! is_numeric($a) || ! is_numeric($b)) {
            return false;
        }

        $fa = (float) $a;
        $fb = (float) $b;

        if ($fa === $fb) {
            return true;
        }

        $scale = max(abs($fa), abs($fb));
        if ($scale === 0.0) {
            return false;
        }

        return abs($fa - $fb) / $scale <= (float) self::PRICE_TOLERANCE;
    }

    private function dbOrderData($order): array
    {
        return [
            'id' => $order->id,
            'client_order_id' => $order->client_order_id,
            'exchange_order_id' => $order->exchange_order_id,
            'status' => strtoupper((string) $order->status),
            'side' => strtoupper((string) $order->side),
            'type' => strtoupper((string) $order->type),
            'price' => (string) $order->price,
            'quantity' => (string) $order->quantity,
            // Raw last-modified stamp (same writer frame as the snapshots) — lets
            // the comparator tell a genuinely-drifting order apart from one simply
            // re-placed AFTER a now-stale exchange snapshot was taken.
            'updated_at' => (string) $order->getRawOriginal('updated_at'),
        ];
    }

    private function exchangeOrderData(array $order): array
    {
        $price = $this->exchangeOrderPrice($order);

        // Type resolution: the mapper writes `_orderType` as the canonical
        // upstream type (e.g. STOP_MARKET, TAKE_PROFIT) for plan/stop/algo
        // orders; the raw `orderType` field on those rows is just the
        // execution mode ("market" / "limit"). Read the canonical first
        // so PROFIT-LIMIT ↔ TAKE_PROFIT alias-matching actually fires.
        //
        // Status resolution: BitGet plan orders return their state under
        // `planStatus`; algo orders sometimes use `algoStatus`. Stack them
        // all so cross-exchange status aliasing (LIVE ↔ NEW) works.
        return [
            'id' => null,
            'client_order_id' => $order['clientOrderId'] ?? $order['orderLinkId'] ?? $order['clientAlgoId'] ?? null,
            'exchange_order_id' => $order['orderId'] ?? $order['id'] ?? $order['algoId'] ?? null,
            'symbol' => $order['symbol'] ?? null,
            'status' => strtoupper((string) ($order['status'] ?? $order['planStatus'] ?? $order['algoStatus'] ?? '')),
            'side' => strtoupper((string) ($order['side'] ?? '')),
            'type' => strtoupper((string) ($order['_orderType'] ?? $order['type'] ?? $order['orderType'] ?? '')),
            'price' => (string) ($price ?? 0),
            'quantity' => (string) ($order['origQty'] ?? $order['qty'] ?? $order['size'] ?? $order['quantity'] ?? 0),
        ];
    }

    /** @param array<string, mixed> $order */
    private function exchangeOrderPrice(array $order): mixed
    {
        $price = $order['price'] ?? null;
        if ($this->missingOrderPrice($price) && isset($order['_price'])) {
            $price = $order['_price'];
        }
        if ($this->missingOrderPrice($price) && isset($order['triggerPrice'])) {
            $price = $order['triggerPrice'];
        }

        return $price;
    }

    private function missingOrderPrice(mixed $price): bool
    {
        return $price === null || (string) $price === '' || (string) $price === '0' || (string) $price === '0.0';
    }

    private function valuesEqual(string $field, $a, $b): bool
    {
        // Null on either side = "not reported" / "no signal" — we can't
        // meaningfully compare, so don't flag drift. Paired orders never
        // hit this branch because pairing only happens when both sides
        // exist; this is specifically for position fields that an exchange
        // may omit (e.g. Binance leverage/marginType on CROSSED accounts).
        if ($this->hasNoComparableValue($a, $b)) {
            return true;
        }
        if (in_array($field, self::NUMERIC_FIELDS, true)) {
            return $this->numericValuesEqual($field, (string) $a, (string) $b);
        }
        if ($field === 'type') {
            return $this->typesEqual((string) $a, (string) $b);
        }
        if ($field === 'status') {
            return $this->statusesEqual((string) $a, (string) $b);
        }

        return strtoupper((string) $a) === strtoupper((string) $b);
    }

    private function hasNoComparableValue(mixed $a, mixed $b): bool
    {
        return $a === null || $b === null;
    }

    private function numericValuesEqual(string $field, string $a, string $b): bool
    {
        if ($a === '' || $b === '' || ! is_numeric($a) || ! is_numeric($b)) {
            return $a === $b;
        }

        return $this->compareNumericValues($field, $a, $b);
    }

    /**
     * @param  numeric-string  $a
     * @param  numeric-string  $b
     */
    private function compareNumericValues(string $field, string $a, string $b): bool
    {
        if (in_array($field, self::PRICE_TOLERANCE_FIELDS, true)) {
            return $this->numericWithinTolerance($a, $b);
        }

        return bccomp($a, $b, 18) === 0;
    }

    /**
     * Order-status equality with cross-exchange aliasing. Same-shape
     * resolver as typesEqual — direct uppercase match wins, else either
     * side appears in the other's alias list.
     */
    private function statusesEqual(string $a, string $b): bool
    {
        return $this->equalWithAliases($a, $b, self::STATUS_ALIASES);
    }

    private function typesEqual(string $a, string $b): bool
    {
        return $this->equalWithAliases($a, $b, self::TYPE_ALIASES);
    }

    /** @param array<string, array<int, string>> $aliases */
    private function equalWithAliases(string $a, string $b, array $aliases): bool
    {
        $au = strtoupper($a);
        $bu = strtoupper($b);
        if ($au === $bu) {
            return true;
        }

        return $this->aliasContains($aliases, $au, $bu)
            || $this->aliasContains($aliases, $bu, $au);
    }

    /** @param array<string, array<int, string>> $aliases */
    private function aliasContains(array $aliases, string $source, string $target): bool
    {
        return isset($aliases[$source]) && in_array($target, $aliases[$source], true);
    }

    private function normalizeDirection(array $pos): string
    {
        $direction = $this->explicitDirection($pos);
        if ($direction !== null) {
            return $direction;
        }
        if (isset($pos['side'])) {
            return strtoupper($pos['side']) === 'BUY' ? 'LONG' : 'SHORT';
        }
        $qty = (float) ($pos['positionAmt'] ?? $pos['size'] ?? 0);

        return $qty >= 0 ? 'LONG' : 'SHORT';
    }

    private function inferOrderDirection(array $order): ?string
    {
        return $this->explicitDirection($order);
    }

    /** @param array<string, mixed> $positionOrOrder */
    private function explicitDirection(array $positionOrOrder): ?string
    {
        $side = $positionOrOrder['positionSide'] ?? null;

        return ! empty($side) && in_array(strtoupper($side), ['LONG', 'SHORT'], true)
            ? strtoupper($side)
            : null;
    }

    /**
     * Compute gross PnL for a position. Returns [pnl (string|null), kind
     * ('realized'|'unrealized'|null)]. Realized for positions with a
     * closing_price; unrealized uses exchange_symbols.mark_price for
     * still-open positions. Fees are ignored (gross). Returns null when
     * we don't have enough data to compute.
     */
    private function computePnl($p, ?string $entryPrice): array
    {
        $isClosed = $this->isTerminalPnlStatus($p->status);

        // Prefer exchange-reported PnL when available (backfilled by cron)
        if ($isClosed && $p->pnl !== null) {
            return [$this->trim((string) $p->pnl), 'realized'];
        }

        $inputs = $this->pnlInputs($entryPrice, $p->quantity);
        if ($inputs === null) {
            return [null, null];
        }

        return $this->fallbackPnl($p, $inputs[0], $inputs[1], strtoupper((string) $p->direction), $isClosed);
    }

    /**
     * @return array{0: non-empty-string, 1: non-empty-string}|null
     */
    private function pnlInputs(?string $entryPrice, mixed $quantity): ?array
    {
        if ($entryPrice === null || $entryPrice === '' || $quantity === null || (string) $quantity === '') {
            return null;
        }

        return [$entryPrice, (string) $quantity];
    }

    /**
     * @param  non-empty-string  $open
     * @param  non-empty-string  $qty
     * @return array{0: string|null, 1: string|null}
     */
    private function fallbackPnl(mixed $p, string $open, string $qty, string $direction, bool $isClosed): array
    {
        $realized = $this->realizedFallbackPnl($p, $open, $qty, $direction, $isClosed);
        if ($realized !== null) {
            return [$realized, 'realized'];
        }

        $mark = $p->exchangeSymbol?->mark_price;
        if ($mark === null || (string) $mark === '') {
            return [null, null];
        }

        $diff = $direction === 'LONG'
            ? bcsub((string) $mark, (string) $open, 18)
            : bcsub((string) $open, (string) $mark, 18);
        $pnl = bcmul($diff, (string) $qty, 18);

        return [$this->trim($pnl), 'unrealized'];
    }

    /**
     * @param  non-empty-string  $open
     * @param  non-empty-string  $qty
     */
    private function realizedFallbackPnl(mixed $p, string $open, string $qty, string $direction, bool $isClosed): ?string
    {
        // Fallback for closed positions without exchange PnL yet.
        $close = $p->closing_price ?? null;
        if ($close === null || (string) $close === '' || ! $isClosed) {
            return null;
        }

        $diff = $direction === 'LONG'
            ? bcsub((string) $close, (string) $open, 18)
            : bcsub((string) $open, (string) $close, 18);

        return $this->trim(bcmul($diff, (string) $qty, 18));
    }

    private function isTerminalPnlStatus(mixed $status): bool
    {
        return in_array(strtolower((string) $status), ['closed', 'cancelled', 'failed'], true);
    }

    /**
     * The stored opening_price is only the first fill. Once ladder orders
     * fill, the exchange and every PnL calculation use the weighted entry.
     */
    private function entryPrice($position): ?string
    {
        return $this->computeWeightedAvgEntry($position, strtoupper((string) $position->direction))
            ?? ($position->opening_price !== null ? (string) $position->opening_price : null);
    }

    private function displayEntryPrice(?string $entryPrice): ?string
    {
        return $entryPrice !== null ? bcadd($entryPrice, '0', 8) : null;
    }

    private function trim(string $n): string
    {
        if (str_contains($n, '.')) {
            $n = rtrim(rtrim($n, '0'), '.');
        }

        return $n === '' || $n === '-' ? '0' : $n;
    }

    /**
     * Per-stage PnL grid for a position. One row per lifecycle order:
     * MARKET → each LIMIT rung (qty asc) → STOP-MARKET, in that order.
     *
     * Each row carries the position's *post-fill* state at that point —
     * cumulative qty, WAP'd avg entry, and what the unrealized PnL would
     * be at the row's fill price plus what the profit would be at today's
     * stored TP. SL row assumes every limit rung filled before SL hit
     * (worst-case max size).
     *
     * Returns [] when no DB position is attached or the position has no
     * entry-side fills/orders. CANCELLED + EXPIRED orders are excluded.
     */
    private function computePnlProjections($dbPos): array
    {
        if (! $dbPos) {
            return [];
        }

        return $this->projectionRows($this->projectionContext($dbPos));
    }

    /** @return array<string, mixed> */
    private function projectionContext(mixed $dbPos): array
    {
        $isLong = strtoupper((string) $dbPos->direction) === 'LONG';
        $orders = $dbPos->orders->reject(fn ($o) => in_array(strtoupper((string) $o->status), ['CANCELLED', 'EXPIRED'], true));

        return [
            'sign' => $isLong ? '1' : '-1',
            'tpMultiplier' => $this->projectionTpMultiplier($dbPos, $isLong),
            'marketRows' => $orders->where('type', 'MARKET')->sortBy('id')->values(),
            'limitRows' => $orders->where('type', 'LIMIT')->sortBy(fn ($o) => (float) $o->quantity)->values(),
            'slOrder' => $orders->where('type', 'STOP-MARKET')->sortByDesc('id')->first(),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    private function projectionRows(array $context): array
    {
        $slPrice = $this->projectionStopPrice($context['slOrder']);

        $rows = [];
        $cumulativeQty = '0';
        $cumulativeCost = '0';

        foreach ($context['marketRows'] as $marketOrder) {
            $this->appendProjectionEntryRow($rows, $cumulativeQty, $cumulativeCost, 'MARKET', $marketOrder, $context['tpMultiplier'], $context['sign']);
        }
        foreach ($context['limitRows'] as $idx => $limitOrder) {
            $this->appendProjectionEntryRow($rows, $cumulativeQty, $cumulativeCost, 'LIMIT '.($idx + 1), $limitOrder, $context['tpMultiplier'], $context['sign']);
        }

        $this->appendProjectionStopRow($rows, $context['slOrder'], $slPrice, $cumulativeQty, $cumulativeCost, $context['tpMultiplier'], $context['sign']);

        return $rows;
    }

    private function projectionStopPrice(mixed $order): ?string
    {
        return $order?->price !== null ? (string) $order->price : null;
    }

    /** @return numeric-string|null */
    private function projectionTpMultiplier(mixed $position, bool $isLong): ?string
    {
        $profitPct = $position->profit_percentage;
        $tpFraction = $profitPct !== null && is_numeric((string) $profitPct)
            ? bcdiv((string) $profitPct, '100', 18)
            : null;

        return $tpFraction !== null
            ? ($isLong ? bcadd('1', $tpFraction, 18) : bcsub('1', $tpFraction, 18))
            : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  numeric-string  $cumulativeQty
     * @param  numeric-string  $cumulativeCost
     * @param  numeric-string|null  $tpMultiplier
     * @param  numeric-string  $sign
     */
    private function appendProjectionEntryRow(array &$rows, string &$cumulativeQty, string &$cumulativeCost, string $label, mixed $order, ?string $tpMultiplier, string $sign): void
    {
        $qty = (string) $order->quantity;
        $price = (string) $order->price;
        if (! is_numeric($qty) || ! is_numeric($price)) {
            return;
        }

        // A zero-price MARKET fill is not priced yet; including its quantity
        // would understate the average entry and inflate projected profit.
        if (bccomp($price, '0', 18) <= 0) {
            return;
        }

        $cumulativeQty = bcadd($cumulativeQty, $qty, 18);
        $cumulativeCost = bcadd($cumulativeCost, bcmul($qty, $price, 18), 18);
        if (bccomp($cumulativeQty, '0', 18) <= 0) {
            return;
        }

        $avgEntry = bcdiv($cumulativeCost, $cumulativeQty, 18);
        $rows[] = $this->projectionRow($label, $order, $price, $cumulativeQty, $avgEntry, $tpMultiplier, $sign);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  numeric-string  $cumulativeQty
     * @param  numeric-string  $cumulativeCost
     * @param  numeric-string|null  $tpMultiplier
     * @param  numeric-string  $sign
     */
    private function appendProjectionStopRow(array &$rows, mixed $slOrder, ?string $slPrice, string $cumulativeQty, string $cumulativeCost, ?string $tpMultiplier, string $sign): void
    {
        if ($slPrice === null || ! is_numeric($slPrice) || bccomp($cumulativeQty, '0', 18) <= 0) {
            return;
        }

        $avgEntry = bcdiv($cumulativeCost, $cumulativeQty, 18);
        $rows[] = $this->projectionRow('STOP-MARKET', $slOrder, $slPrice, $cumulativeQty, $avgEntry, $tpMultiplier, $sign);
    }

    /**
     * @param  numeric-string  $price
     * @param  numeric-string  $quantity
     * @param  numeric-string  $avgEntry
     * @param  numeric-string|null  $tpMultiplier
     * @param  numeric-string  $sign
     * @return array<string, mixed>
     */
    private function projectionRow(string $label, mixed $order, string $price, string $quantity, string $avgEntry, ?string $tpMultiplier, string $sign): array
    {
        $pnlAtFill = bcmul(bcmul(bcsub($price, $avgEntry, 18), $quantity, 18), $sign, 18);
        $tpPrice = $tpMultiplier !== null ? bcmul($avgEntry, $tpMultiplier, 18) : null;
        $profitAtTp = $tpPrice !== null
            ? bcmul(bcmul(bcsub($tpPrice, $avgEntry, 18), $quantity, 18), $sign, 18)
            : null;

        return [
            'type' => $label,
            'side' => strtoupper((string) $order->side),
            'price' => $this->trim($price),
            'size' => $this->trim($quantity),
            'avg_entry' => $this->trim($avgEntry),
            'tp_price' => $tpPrice !== null ? $this->trim($tpPrice) : null,
            'pnl_at_fill' => $this->trim($pnlAtFill),
            'profit_at_tp' => $profitAtTp !== null ? $this->trim($profitAtTp) : null,
            'status' => strtoupper((string) $order->status),
        ];
    }

    private function countStatuses(array $orders): array
    {
        $counts = ['synced' => 0, 'drift' => 0, 'db_only' => 0, 'exchange_only' => 0];
        foreach ($orders as $o) {
            $counts[$o['status']] = ($counts[$o['status']] ?? 0) + 1;
        }
        $counts['total'] = count($orders);

        return $counts;
    }
}
