<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kraite\Core\Models\Account;

class AccountsController extends Controller
{
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
            ]);

        return view('accounts.index', compact('accounts'));
    }

    public function data(Request $request): JsonResponse
    {
        $request->validate([
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
        ]);

        $account = Account::with(['apiSystem'])->findOrFail($request->input('account_id'));

        // Get DB positions (active) with orders
        $dbPositions = $account->positions()
            ->where('status', 'active')
            ->with(['exchangeSymbol', 'orders' => fn ($q) => $q->whereIn('status', ['NEW', 'PARTIALLY_FILLED'])])
            ->get()
            ->map(fn ($pos) => [
                'id' => $pos->id,
                'symbol' => $pos->parsed_trading_pair ?? $pos->exchangeSymbol?->symbol ?? 'Unknown',
                'direction' => $pos->direction,
                'quantity' => $pos->quantity,
                'margin' => $pos->margin,
                'leverage' => $pos->leverage,
                'entry_price' => $pos->opening_price,
                'orders' => $pos->orders->map(fn ($order) => [
                    'id' => $order->id,
                    'client_order_id' => $order->client_order_id,
                    'exchange_order_id' => $order->exchange_order_id,
                    'type' => $order->type,
                    'side' => $order->side,
                    'quantity' => $order->quantity,
                    'price' => $order->price,
                    'status' => $order->status,
                ])->toArray(),
            ])
            ->toArray();

        // Get exchange data
        $exchangePositions = [];
        $exchangeOrders = [];
        $apiError = null;

        try {
            // Fetch positions from exchange
            $positionsResponse = $account->apiQueryPositions();
            if ($positionsResponse->ok()) {
                $exchangePositions = collect($positionsResponse->data())->map(fn ($pos) => [
                    'symbol' => $pos['symbol'] ?? 'Unknown',
                    'direction' => $this->normalizeDirection($pos),
                    'quantity' => $pos['positionAmt'] ?? $pos['size'] ?? $pos['contracts'] ?? 0,
                    'margin' => $pos['isolatedMargin'] ?? $pos['positionBalance'] ?? $pos['margin'] ?? 0,
                    'leverage' => $pos['leverage'] ?? 0,
                    'entry_price' => $pos['entryPrice'] ?? $pos['avgPrice'] ?? 0,
                    'unrealized_pnl' => $pos['unRealizedProfit'] ?? $pos['unrealisedPnl'] ?? 0,
                ])->filter(fn ($pos) => abs((float) $pos['quantity']) > 0)->values()->toArray();
            }

            // Fetch open orders from exchange
            $ordersResponse = $account->apiQueryOpenOrders();
            if ($ordersResponse->ok()) {
                $exchangeOrders = collect($ordersResponse->data())->map(fn ($order) => [
                    'symbol' => $order['symbol'] ?? 'Unknown',
                    'order_id' => $order['orderId'] ?? $order['id'] ?? null,
                    'client_order_id' => $order['clientOrderId'] ?? $order['orderLinkId'] ?? null,
                    'type' => $order['type'] ?? $order['orderType'] ?? 'Unknown',
                    'side' => $order['side'] ?? 'Unknown',
                    'quantity' => $order['origQty'] ?? $order['qty'] ?? $order['size'] ?? 0,
                    'price' => $order['price'] ?? 0,
                    'status' => $order['status'] ?? 'Unknown',
                ])->toArray();
            }
        } catch (\Throwable $e) {
            $apiError = $e->getMessage();
        }

        // Detect discrepancies
        $discrepancies = $this->detectDiscrepancies($dbPositions, $exchangePositions, $exchangeOrders);

        return response()->json([
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
                'exchange' => $account->apiSystem?->name ?? 'Unknown',
            ],
            'db' => [
                'positions' => $dbPositions,
            ],
            'exchange' => [
                'positions' => $exchangePositions,
                'orders' => $exchangeOrders,
            ],
            'discrepancies' => $discrepancies,
            'api_error' => $apiError,
        ]);
    }

    private function normalizeDirection(array $pos): string
    {
        // Different exchanges use different field names
        if (isset($pos['positionSide'])) {
            return strtoupper($pos['positionSide']); // Binance
        }
        if (isset($pos['side'])) {
            return strtoupper($pos['side']) === 'BUY' ? 'LONG' : 'SHORT'; // Bybit/others
        }
        // Infer from quantity sign
        $qty = (float) ($pos['positionAmt'] ?? $pos['size'] ?? 0);

        return $qty >= 0 ? 'LONG' : 'SHORT';
    }

    private function detectDiscrepancies(array $dbPositions, array $exchangePositions, array $exchangeOrders): array
    {
        $discrepancies = [];

        // Map exchange positions by symbol for easy lookup
        $exchangeBySymbol = collect($exchangePositions)->keyBy('symbol');

        // Check DB positions against exchange
        foreach ($dbPositions as $dbPos) {
            $symbol = $dbPos['symbol'];
            $exchPos = $exchangeBySymbol->get($symbol);

            if (! $exchPos) {
                $discrepancies[] = [
                    'type' => 'position',
                    'issue' => 'db_only',
                    'symbol' => $symbol,
                    'message' => "Position exists in DB but not on exchange",
                ];
            }
        }

        // Check exchange positions against DB
        $dbBySymbol = collect($dbPositions)->keyBy('symbol');
        foreach ($exchangePositions as $exchPos) {
            $symbol = $exchPos['symbol'];
            if (! $dbBySymbol->has($symbol)) {
                $discrepancies[] = [
                    'type' => 'position',
                    'issue' => 'exchange_only',
                    'symbol' => $symbol,
                    'message' => "Position exists on exchange but not in DB",
                ];
            }
        }

        // Check DB orders against exchange orders
        $exchangeOrderIds = collect($exchangeOrders)->pluck('client_order_id')->filter()->toArray();
        foreach ($dbPositions as $dbPos) {
            foreach ($dbPos['orders'] as $dbOrder) {
                if ($dbOrder['client_order_id'] && ! in_array($dbOrder['client_order_id'], $exchangeOrderIds)) {
                    $discrepancies[] = [
                        'type' => 'order',
                        'issue' => 'db_only',
                        'symbol' => $dbPos['symbol'],
                        'order_id' => $dbOrder['client_order_id'],
                        'message' => "Order exists in DB but not on exchange",
                    ];
                }
            }
        }

        return $discrepancies;
    }
}
