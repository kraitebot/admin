<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kraite\Core\Models\Account;

class AccountsController extends Controller
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

    public function history(Request $request): JsonResponse
    {
        $request->validate([
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $account = Account::findOrFail($request->input('account_id'));
        $perPage = (int) $request->input('per_page', 25);
        $page = (int) $request->input('page', 1);

        $paginator = $account->positions()
            ->with([
                'exchangeSymbol',
                'orders' => fn ($q) => $q->orderBy('id'),
            ])
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        $positions = collect($paginator->items())->map(function ($p) {
            [$pnl, $pnlKind] = $this->computePnl($p);

            return [
                'id' => $p->id,
                'symbol' => $p->parsed_trading_pair ?? $p->exchangeSymbol?->symbol ?? 'Unknown',
                'direction' => $p->direction,
                'status' => $p->status,
                'quantity' => (string) $p->quantity,
                'opening_price' => (string) $p->opening_price,
                'closing_price' => (string) ($p->closing_price ?? ''),
                'mark_price' => $p->exchangeSymbol?->mark_price !== null ? (string) $p->exchangeSymbol->mark_price : null,
                'leverage' => (string) $p->leverage,
                'margin' => (string) $p->margin,
                'pnl' => $pnl,
                'pnl_kind' => $pnlKind,
                'created_at' => optional($p->created_at)->format('Y-m-d H:i:s'),
                'closed_at' => optional($p->closed_at ?? null)->format('Y-m-d H:i:s'),
                'order_count' => $p->orders->count(),
                'orders' => $p->orders->map(fn ($o) => [
                    'id' => $o->id,
                    'type' => strtoupper((string) $o->type),
                    'side' => strtoupper((string) $o->side),
                    'status' => strtoupper((string) $o->status),
                    'quantity' => (string) $o->quantity,
                    'price' => (string) $o->price,
                    'client_order_id' => $o->client_order_id,
                    'exchange_order_id' => $o->exchange_order_id,
                ])->all(),
            ];
        })->all();

        return response()->json([
            'positions' => $positions,
            'page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ]);
    }

    public function index()
    {
        $accounts = Account::with('apiSystem', 'user')
            ->orderBy('name')
            ->get()
            ->map(fn (Account $account) => [
                'id' => $account->id,
                'name' => $account->name,
                'exchange' => $account->apiSystem?->name ?? 'Unknown',
                'user' => $account->user?->name ?? 'Unknown',
                'can_trade' => (bool) $account->can_trade,
            ]);

        return view('system.accounts.index', compact('accounts'));
    }

    public function data(Request $request): JsonResponse
    {
        $request->validate([
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
        ]);

        $account = Account::with(['apiSystem'])->findOrFail($request->input('account_id'));

        // MySQL stores opened_at in the session tz (SYSTEM) but Laravel
        // reads datetimes as UTC, so Carbon diffs drift by the local tz
        // offset. Let MySQL compute the age in seconds — both sides stay in
        // the same tz, so the number is correct regardless of the mismatch.
        $dbPositions = $account->positions()
            ->whereIn('status', self::OPEN_POSITION_STATUSES)
            ->select('positions.*')
            ->selectRaw('TIMESTAMPDIFF(SECOND, COALESCE(opened_at, created_at), NOW()) as opened_seconds_ago')
            ->with([
                'exchangeSymbol',
                'orders' => fn ($q) => $q->whereIn('status', self::COMPARATOR_ORDER_STATUSES),
            ])
            ->get();

        $exchangePositions = [];
        $exchangeOrders = [];
        $symbolConfigs = [];
        $apiError = null;

        try {
            $positionsResult = $account->apiQueryPositions()->result ?? [];
            $exchangePositions = collect($positionsResult)
                ->filter(fn ($pos) => abs((float) ($pos['positionAmt'] ?? $pos['size'] ?? $pos['contracts'] ?? 0)) > 0)
                ->values()
                ->all();

            $exchangeOrders = $account->apiQueryOpenOrders()->result ?? [];
        } catch (\Throwable $e) {
            $apiError = $e->getMessage();
        }

        // Per-symbol leverage + marginType. Binance's v3 positionRisk stopped
        // returning these fields, so we need a separate endpoint; other
        // exchanges expose the same info and normalize to the same shape.
        if (method_exists($account, 'apiQuerySymbolConfig')) {
            try {
                $symbolConfigs = $account->apiQuerySymbolConfig()->result ?? [];
            } catch (\Throwable $e) {
                // Endpoint not supported or transient failure — treat as "no signal".
            }
        }

        // Algo / plan / stop orders live on separate endpoints per exchange.
        // Not all exchanges support all of them — fail silently and merge
        // whatever we get into the exchange-orders list.
        foreach (['apiQueryAlgoOrders', 'apiQueryPlanOrders', 'apiQueryStopOrders'] as $method) {
            if (! method_exists($account, $method)) {
                continue;
            }
            try {
                $extra = $account->{$method}()->result ?? [];
                if (is_array($extra) && ! empty($extra)) {
                    $exchangeOrders = array_merge($exchangeOrders, $extra);
                }
            } catch (\Throwable $e) {
                // Endpoint not supported by this exchange / mapper — skip.
            }
        }

        [$pairs, $matchedExchangeOrderIds] = $this->buildPairs($account, $dbPositions, $exchangePositions, $exchangeOrders, $symbolConfigs);
        $orphanOrders = $this->buildOrphanOrders($exchangeOrders, $matchedExchangeOrderIds);

        // Sort open positions newest → oldest by DB opened_at. Pairs without a
        // DB side (exchange-only) lack an age; push them to the bottom.
        $pairs = collect($pairs)
            ->sortBy(fn (array $p) => $p['db']['opened_seconds_ago'] ?? PHP_INT_MAX)
            ->values()
            ->all();

        return response()->json([
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
                'exchange' => $account->apiSystem?->name ?? 'Unknown',
                'can_trade' => (bool) $account->can_trade,
            ],
            'pairs' => $pairs,
            'orphan_orders' => $orphanOrders,
            'api_error' => $apiError,
        ]);
    }

    private function buildPairs(Account $account, $dbPositions, array $exchangePositions, array $exchangeOrders, array $symbolConfigs = []): array
    {
        $accountMarginMode = strtoupper((string) ($account->margin_mode ?? ''));

        $exchByKey = [];
        foreach ($exchangePositions as $pos) {
            $symbol = $pos['symbol'] ?? null;
            if (! $symbol) {
                continue;
            }
            $direction = $this->normalizeDirection($pos);
            $exchByKey[$symbol.'|'.$direction] = $pos;
        }

        $exchOrdersByCid = [];
        $exchOrdersByXid = [];
        foreach ($exchangeOrders as $idx => $order) {
            $cid = $order['clientOrderId'] ?? $order['orderLinkId'] ?? $order['clientAlgoId'] ?? null;
            $xid = $order['orderId'] ?? $order['id'] ?? $order['algoId'] ?? null;
            if ($cid !== null && $cid !== '') {
                $exchOrdersByCid[(string) $cid] = $idx;
            }
            if ($xid !== null && $xid !== '') {
                $exchOrdersByXid[(string) $xid] = $idx;
            }
        }

        $matched = [];
        $pairs = [];
        $seenKeys = [];

        foreach ($dbPositions as $dbPos) {
            $symbol = $dbPos->parsed_trading_pair ?? $dbPos->exchangeSymbol?->symbol;
            if (! $symbol) {
                continue;
            }
            $direction = $dbPos->direction;
            $key = $symbol.'|'.$direction;
            $seenKeys[$key] = true;

            $exchPos = $exchByKey[$key] ?? null;
            $pairs[] = $this->buildPair($symbol, $direction, $dbPos, $exchPos, $accountMarginMode, $exchangeOrders, $exchOrdersByCid, $exchOrdersByXid, $matched, $symbolConfigs[$symbol] ?? null);
        }

        foreach ($exchByKey as $key => $exchPos) {
            if (isset($seenKeys[$key])) {
                continue;
            }
            [$symbol, $direction] = explode('|', $key, 2);
            $pairs[] = $this->buildPair($symbol, $direction, null, $exchPos, $accountMarginMode, $exchangeOrders, $exchOrdersByCid, $exchOrdersByXid, $matched, $symbolConfigs[$symbol] ?? null);
        }

        return [$pairs, $matched];
    }

    private function buildPair(
        string $symbol,
        string $direction,
        $dbPos,
        ?array $exchPos,
        string $accountMarginMode,
        array $exchangeOrders,
        array $exchOrdersByCid,
        array $exchOrdersByXid,
        array &$matchedExchangeOrderIndices,
        ?array $symbolConfig = null,
    ): array {
        $dbPosData = $dbPos ? $this->dbPositionData($dbPos, $accountMarginMode) : null;
        $exchPosData = $exchPos ? $this->exchangePositionData($exchPos, $symbolConfig) : null;

        // Replace the stored opening_price snapshot with the weighted average
        // of every FILLED entry-side order. Binance reports the running avg
        // on the live position; the DB's opening_price only reflects the
        // first fill and drifts apart as limit ladders fill. Compare apples
        // to apples by computing the equivalent on our side.
        if ($dbPosData && $dbPos) {
            $avg = $this->computeWeightedAvgEntry($dbPos, $direction);
            if ($avg !== null) {
                $dbPosData['entry_price'] = $avg;
            }
        }

        // Sync analysis only runs on 'active' positions. Transient states
        // (opening, syncing, waping, closing, cancelling, new) mean the DB
        // is intentionally mid-flight and will produce false drift alarms.
        $dbIsActive = $dbPosData !== null && ($dbPosData['status'] ?? null) === 'active';

        $positionDriftFields = [];
        if ($dbPosData && $exchPosData && $dbIsActive) {
            foreach (self::POSITION_DRIFT_FIELDS as $field) {
                if (! $this->valuesEqual($field, $dbPosData[$field] ?? null, $exchPosData[$field] ?? null)) {
                    $positionDriftFields[] = $field;
                }
            }
        }

        // Pair orders inside this (symbol, direction)
        $orders = [];
        $dbOrderIds = [];
        if ($dbPos) {
            foreach ($dbPos->orders as $dbOrder) {
                $dbOrderIds[$dbOrder->id] = true;
                $cid = $dbOrder->client_order_id;
                $xid = $dbOrder->exchange_order_id;

                $exchIdx = null;
                if ($cid && isset($exchOrdersByCid[(string) $cid])) {
                    $exchIdx = $exchOrdersByCid[(string) $cid];
                } elseif ($xid && isset($exchOrdersByXid[(string) $xid])) {
                    $exchIdx = $exchOrdersByXid[(string) $xid];
                }

                $exchOrder = $exchIdx !== null ? $exchangeOrders[$exchIdx] : null;
                if ($exchIdx !== null) {
                    $matchedExchangeOrderIndices[$exchIdx] = true;
                }

                $dbOrderData = $this->dbOrderData($dbOrder);
                $exchOrderData = $exchOrder ? $this->exchangeOrderData($exchOrder) : null;

                $orders[] = $this->buildOrderPair($dbOrderData, $exchOrderData);
            }
        }

        // Exchange orders that belong to this pair but weren't matched via DB ids
        foreach ($exchangeOrders as $idx => $exchOrder) {
            if (isset($matchedExchangeOrderIndices[$idx])) {
                continue;
            }
            $exchSymbol = $exchOrder['symbol'] ?? null;
            if ($exchSymbol !== $symbol) {
                continue;
            }
            $exchDirection = $this->inferOrderDirection($exchOrder);
            if ($exchDirection !== null && $exchDirection !== $direction) {
                continue;
            }
            // If exchange order has no positionSide, only attach when we have a DB position to anchor the direction
            if ($exchDirection === null && $dbPos === null) {
                continue;
            }
            $matchedExchangeOrderIndices[$idx] = true;
            $orders[] = $this->buildOrderPair(null, $this->exchangeOrderData($exchOrder));
        }

        // Suppress per-order drift flags while the DB position is mid-flight
        // (step-dispatcher is writing). Otherwise we flag transient churn
        // as drift and alarm-fatigue the operator.
        if ($dbPosData && ! $dbIsActive) {
            foreach ($orders as &$o) {
                $o['status'] = self::PAIR_STATUS_SYNCED;
                $o['drift_fields'] = [];
            }
            unset($o);
        }

        $anyOrderDrift = collect($orders)->contains(fn ($o) => $o['status'] !== self::PAIR_STATUS_SYNCED);
        $positionDrift = ! empty($positionDriftFields);

        if ($dbPosData && ! $dbIsActive) {
            $status = self::PAIR_STATUS_TRANSIENT;
        } elseif ($dbPosData && ! $exchPosData) {
            $status = self::PAIR_STATUS_DB_ONLY;
        } elseif (! $dbPosData && $exchPosData) {
            $status = self::PAIR_STATUS_EXCHANGE_ONLY;
        } elseif ($positionDrift || $anyOrderDrift) {
            $status = self::PAIR_STATUS_DRIFT;
        } else {
            $status = self::PAIR_STATUS_SYNCED;
        }

        return [
            'symbol' => $symbol,
            'direction' => $direction,
            'status' => $status,
            'db' => $dbPosData,
            'exchange' => $exchPosData,
            'position_drift_fields' => $positionDriftFields,
            'orders' => $orders,
            'order_counts' => $this->countStatuses($orders),
        ];
    }

    private function buildOrderPair(?array $db, ?array $exch): array
    {
        if ($db && ! $exch) {
            // FILLED orders are historical — they no longer live on the
            // open-orders endpoint. Their absence is expected, not drift.
            if (strtoupper((string) ($db['status'] ?? '')) === 'FILLED') {
                return ['status' => self::PAIR_STATUS_SYNCED, 'db' => $db, 'exchange' => null, 'drift_fields' => []];
            }

            return ['status' => self::PAIR_STATUS_DB_ONLY, 'db' => $db, 'exchange' => null, 'drift_fields' => []];
        }
        if (! $db && $exch) {
            return ['status' => self::PAIR_STATUS_EXCHANGE_ONLY, 'db' => null, 'exchange' => $exch, 'drift_fields' => []];
        }

        $driftFields = [];
        foreach (self::ORDER_DRIFT_FIELDS as $field) {
            if (! $this->valuesEqual($field, $db[$field] ?? null, $exch[$field] ?? null)) {
                $driftFields[] = $field;
            }
        }

        return [
            'status' => empty($driftFields) ? self::PAIR_STATUS_SYNCED : self::PAIR_STATUS_DRIFT,
            'db' => $db,
            'exchange' => $exch,
            'drift_fields' => $driftFields,
        ];
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
        // Binance's position endpoint doesn't report per-position leverage
        // or marginType. We backfill from the symbol-config endpoint when
        // available; otherwise preserve null so the comparator treats the
        // field as "not reported" instead of flagging false drift.
        $leverage = $pos['leverage'] ?? $symbolConfig['leverage'] ?? null;
        $margin = $pos['isolatedMargin'] ?? $pos['positionBalance'] ?? $pos['margin'] ?? null;
        $marginMode = $pos['marginType'] ?? $pos['marginMode'] ?? $symbolConfig['marginType'] ?? null;

        // Binance CROSSED positions report isolatedMargin=0, which isn't a
        // real value to compare against — it means "no isolated margin".
        // Null it so the comparator treats it as "not reported".
        if ($margin !== null && bccomp((string) $margin, '0', 18) === 0) {
            $margin = null;
        }

        return [
            'id' => null,
            'quantity' => (string) abs((float) ($pos['positionAmt'] ?? $pos['size'] ?? $pos['contracts'] ?? 0)),
            'entry_price' => (string) ($pos['entryPrice'] ?? $pos['avgPrice'] ?? 0),
            'leverage' => $leverage !== null ? (string) $leverage : null,
            'margin' => $margin !== null ? (string) $margin : null,
            'margin_mode' => $marginMode !== null ? strtoupper((string) $marginMode) : null,
            'unrealized_pnl' => (string) ($pos['unRealizedProfit'] ?? $pos['unrealisedPnl'] ?? 0),
        ];
    }

    /**
     * Weighted-average entry price across a position's FILLED entry-side
     * orders (BUY for LONG, SELL for SHORT). Mirrors what Binance reports
     * as the running position entryPrice, so the comparator has a fair
     * DB-side value to check against. Null when there are no filled
     * entry orders (callers should fall back to the stored snapshot).
     */
    private function computeWeightedAvgEntry($dbPos, string $direction): ?string
    {
        $entrySide = strtoupper($direction) === 'LONG' ? 'BUY' : 'SELL';
        $totalQty = '0';
        $weightedCost = '0';

        foreach ($dbPos->orders as $o) {
            if (strtoupper((string) $o->status) !== 'FILLED') {
                continue;
            }
            if (strtoupper((string) $o->side) !== $entrySide) {
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

        if (bccomp($totalQty, '0', 18) <= 0) {
            return null;
        }

        return $this->trim(bcdiv($weightedCost, $totalQty, 18));
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
        ];
    }

    private function exchangeOrderData(array $order): array
    {
        $price = $order['price'] ?? null;
        if (($price === null || (string) $price === '' || (string) $price === '0' || (string) $price === '0.0') && isset($order['_price'])) {
            $price = $order['_price'];
        }
        if (($price === null || (string) $price === '' || (string) $price === '0' || (string) $price === '0.0') && isset($order['triggerPrice'])) {
            $price = $order['triggerPrice'];
        }

        return [
            'id' => null,
            'client_order_id' => $order['clientOrderId'] ?? $order['orderLinkId'] ?? $order['clientAlgoId'] ?? null,
            'exchange_order_id' => $order['orderId'] ?? $order['id'] ?? $order['algoId'] ?? null,
            'symbol' => $order['symbol'] ?? null,
            'status' => strtoupper((string) ($order['status'] ?? $order['algoStatus'] ?? '')),
            'side' => strtoupper((string) ($order['side'] ?? '')),
            'type' => strtoupper((string) ($order['type'] ?? $order['orderType'] ?? $order['_orderType'] ?? '')),
            'price' => (string) ($price ?? 0),
            'quantity' => (string) ($order['origQty'] ?? $order['qty'] ?? $order['size'] ?? $order['quantity'] ?? 0),
        ];
    }

    private function valuesEqual(string $field, $a, $b): bool
    {
        // Null on either side = "not reported" / "no signal" — we can't
        // meaningfully compare, so don't flag drift. Paired orders never
        // hit this branch because pairing only happens when both sides
        // exist; this is specifically for position fields that an exchange
        // may omit (e.g. Binance leverage/marginType on CROSSED accounts).
        if ($a === null || $b === null) {
            return true;
        }
        if (in_array($field, self::NUMERIC_FIELDS, true)) {
            $sa = (string) $a;
            $sb = (string) $b;

            // Fall back to string compare when values are empty or not
            // well-formed for bccomp (e.g. scientific notation, stray
            // characters). Prevents PHP 8 ValueError on bccomp.
            if ($sa === '' || $sb === '' || ! is_numeric($sa) || ! is_numeric($sb)) {
                return $sa === $sb;
            }

            if (in_array($field, self::PRICE_TOLERANCE_FIELDS, true)) {
                return $this->numericWithinTolerance($sa, $sb);
            }

            return bccomp($sa, $sb, 18) === 0;
        }
        if ($field === 'type') {
            return $this->typesEqual((string) $a, (string) $b);
        }

        return strtoupper((string) $a) === strtoupper((string) $b);
    }

    private function typesEqual(string $a, string $b): bool
    {
        $au = strtoupper($a);
        $bu = strtoupper($b);
        if ($au === $bu) {
            return true;
        }
        if (isset(self::TYPE_ALIASES[$au]) && in_array($bu, self::TYPE_ALIASES[$au], true)) {
            return true;
        }
        if (isset(self::TYPE_ALIASES[$bu]) && in_array($au, self::TYPE_ALIASES[$bu], true)) {
            return true;
        }

        return false;
    }

    private function normalizeDirection(array $pos): string
    {
        if (! empty($pos['positionSide']) && in_array(strtoupper($pos['positionSide']), ['LONG', 'SHORT'], true)) {
            return strtoupper($pos['positionSide']);
        }
        if (isset($pos['side'])) {
            return strtoupper($pos['side']) === 'BUY' ? 'LONG' : 'SHORT';
        }
        $qty = (float) ($pos['positionAmt'] ?? $pos['size'] ?? 0);

        return $qty >= 0 ? 'LONG' : 'SHORT';
    }

    private function inferOrderDirection(array $order): ?string
    {
        if (! empty($order['positionSide']) && in_array(strtoupper($order['positionSide']), ['LONG', 'SHORT'], true)) {
            return strtoupper($order['positionSide']);
        }

        return null;
    }

    /**
     * Compute gross PnL for a position. Returns [pnl (string|null), kind
     * ('realized'|'unrealized'|null)]. Realized for positions with a
     * closing_price; unrealized uses exchange_symbols.mark_price for
     * still-open positions. Fees are ignored (gross). Returns null when
     * we don't have enough data to compute.
     */
    private function computePnl($p): array
    {
        $open = $p->opening_price;
        $qty = $p->quantity;
        $direction = strtoupper((string) $p->direction);

        if ($open === null || $qty === null || (string) $qty === '' || (string) $open === '') {
            return [null, null];
        }

        $close = $p->closing_price ?? null;
        $isClosed = in_array(strtolower((string) $p->status), ['closed', 'cancelled', 'failed'], true);

        if ($close !== null && (string) $close !== '' && $isClosed) {
            $diff = $direction === 'LONG'
                ? bcsub((string) $close, (string) $open, 18)
                : bcsub((string) $open, (string) $close, 18);
            $pnl = bcmul($diff, (string) $qty, 18);

            return [$this->trim($pnl), 'realized'];
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

    private function trim(string $n): string
    {
        if (str_contains($n, '.')) {
            $n = rtrim(rtrim($n, '0'), '.');
        }

        return $n === '' || $n === '-' ? '0' : $n;
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
