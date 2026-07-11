<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Kraite\Core\Models\Account;

/**
 * Sysadmin fleet-wide position picture — general level.
 *
 * Collapsed: one row per account CURRENTLY HOLDING open positions (idle /
 * paused / deactivated accounts don't appear): owner + exchange, position
 * count, margin ratio (liquidation proximity), unrealised PnL, and a
 * roll-up dot coloured by the account's WORST position so danger shows
 * without expanding.
 *
 * Expanded: Shorts / Longs tabs, one general-level row per position:
 * token, rungs filled, alpha path %, position PnL. Alpha-path bands are
 * Bruno's triage line: green ≤ 50, yellow 50–85, red ≥ 85.
 *
 * Deep per-position analysis is deliberately out of scope (v2 topic).
 */
class PositionsController extends Controller
{
    /** Statuses that make a position "open" for this page. */
    private const OPEN_FLAG = 1;

    public function index(): View
    {
        return view('system.positions', [
            'positions' => [
                'accounts' => rescue(fn () => $this->accountRows(), []),
            ],
        ]);
    }

    public function data(): JsonResponse
    {
        return response()->json([
            'accounts' => rescue(fn () => $this->accountRows(), []),
        ]);
    }

    /**
     * Expanded payload for one account: its open positions split into
     * longs / shorts, general-picture columns only.
     */
    public function positions(Account $account): JsonResponse
    {
        $rows = rescue(fn () => $this->positionRows((int) $account->id), []);

        return response()->json([
            'longs' => array_values(array_filter($rows, fn (array $r): bool => $r['direction'] === 'LONG')),
            'shorts' => array_values(array_filter($rows, fn (array $r): bool => $r['direction'] === 'SHORT')),
        ]);
    }

    // ------------------------------------------------------------------
    // Collapsed account rows
    // ------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function accountRows(): array
    {
        $positions = $this->openPositions();

        if ($positions->isEmpty()) {
            return [];
        }

        $byAccount = $positions->groupBy('account_id');
        $accountIds = $byAccount->keys()->all();

        $accounts = DB::table('accounts')
            ->join('users', 'users.id', '=', 'accounts.user_id')
            ->join('api_systems', 'api_systems.id', '=', 'accounts.api_system_id')
            ->whereIn('accounts.id', $accountIds)
            ->get([
                'accounts.id',
                'users.name as owner',
                'api_systems.name as exchange',
            ])
            ->keyBy('id');

        // Freshest exchange-reported account state we hold — one row per
        // account. Margin ratio the Binance way: maintenance margin over
        // margin balance; 100% = liquidation.
        $balances = DB::table('account_balance_history as abh')
            ->whereIn('abh.account_id', $accountIds)
            ->whereIn('abh.id', function ($query) use ($accountIds): void {
                $query->selectRaw('MAX(id)')
                    ->from('account_balance_history')
                    ->whereIn('account_id', $accountIds)
                    ->groupBy('account_id');
            })
            ->get(['abh.account_id', 'abh.total_maintenance_margin', 'abh.total_margin_balance', 'abh.total_unrealized_profit'])
            ->keyBy('account_id');

        $rows = [];
        foreach ($byAccount as $accountId => $accountPositions) {
            $meta = $accounts->get($accountId);
            if ($meta === null) {
                continue;
            }

            $balance = $balances->get($accountId);
            $marginBalance = (float) ($balance->total_margin_balance ?? 0);
            $marginRatio = $marginBalance > 0.0
                ? round(((float) $balance->total_maintenance_margin / $marginBalance) * 100, 2)
                : null;

            $worstAlpha = (float) $accountPositions->max(fn (object $p): float => $this->alphaPathPct($p));

            $rows[] = [
                'id' => (int) $accountId,
                'owner' => $meta->owner,
                'exchange' => $meta->exchange,
                'positions' => $accountPositions->count(),
                'margin_ratio' => $marginRatio,
                'pnl' => $balance !== null ? round((float) $balance->total_unrealized_profit, 2) : null,
                'worst_alpha_pct' => round($worstAlpha, 1),
                'band' => $this->band($worstAlpha),
            ];
        }

        // Worst first: deepest roll-up, then closest to liquidation.
        usort($rows, static fn (array $a, array $b): int => [$b['worst_alpha_pct'], $b['margin_ratio'] ?? 0]
            <=> [$a['worst_alpha_pct'], $a['margin_ratio'] ?? 0]);

        return $rows;
    }

    // ------------------------------------------------------------------
    // Expanded position rows
    // ------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function positionRows(int $accountId): array
    {
        return $this->openPositions($accountId)->map(function (object $p): array {
            $alpha = $this->alphaPathPct($p);

            return [
                'id' => (int) $p->id,
                'symbol' => $p->parsed_trading_pair ?: ($p->token ?? '?'),
                'direction' => $p->direction,
                'rungs_filled' => (int) $p->filled_limits,
                'rungs_total' => (int) $p->total_limit_orders,
                'alpha_pct' => round($alpha, 1),
                'band' => $this->band($alpha),
                'pnl' => $this->positionPnl($p),
                'opened_at' => $p->created_at,
            ];
        })->all();
    }

    // ------------------------------------------------------------------
    // Shared queries + math
    // ------------------------------------------------------------------

    /**
     * Open positions with everything the alpha-path / rungs / PnL math
     * needs, in one portable query: symbol price, the deepest live LIMIT
     * rung (largest quantity — same definition as the engine's
     * lastLimitOrder()), and the filled-rung count.
     *
     * @return Collection<int, object>
     */
    private function openPositions(?int $accountId = null): Collection
    {
        return DB::table('positions as p')
            ->leftJoin('exchange_symbols as es', 'es.id', '=', 'p.exchange_symbol_id')
            ->where('p.is_open', self::OPEN_FLAG)
            ->when($accountId !== null, fn ($q) => $q->where('p.account_id', $accountId))
            ->select([
                'p.id', 'p.account_id', 'p.direction', 'p.parsed_trading_pair',
                'p.first_profit_price', 'p.opening_price', 'p.quantity',
                'p.total_limit_orders', 'p.created_at',
                'es.mark_price', 'es.token',
            ])
            ->selectSub(function ($query): void {
                $query->from('orders')
                    ->select('price')
                    ->whereColumn('orders.position_id', 'p.id')
                    ->where('orders.type', 'LIMIT')
                    ->whereNotNull('orders.exchange_order_id')
                    ->whereIn('orders.status', ['NEW', 'PARTIALLY_FILLED', 'FILLED'])
                    ->orderByDesc('orders.quantity')
                    ->limit(1);
            }, 'last_limit_price')
            ->selectSub(function ($query): void {
                $query->from('orders')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('orders.position_id', 'p.id')
                    ->where('orders.type', 'LIMIT')
                    ->where('orders.status', 'FILLED');
            }, 'filled_limits')
            ->get();
    }

    /**
     * Alpha path % — how far price has walked the ladder corridor, from
     * the first profit price (0) to the deepest live rung (100). Mirrors
     * the engine's fraction math (direction-aware, clamped); missing
     * inputs read 0, same as the engine's safe default. Price source is
     * mark_price — the freshest price the platform holds.
     */
    private function alphaPathPct(object $p): float
    {
        $start = $p->first_profit_price !== null ? (float) $p->first_profit_price : null;
        $end = $p->last_limit_price !== null ? (float) $p->last_limit_price : null;
        $current = $p->mark_price !== null ? (float) $p->mark_price : null;

        if ($start === null || $end === null || $current === null || $start === $end) {
            return 0.0;
        }

        // LONG corridors run downward (TP above, rungs below); SHORT the
        // inverse. The generic form handles both:
        $fraction = ($start - $current) / ($start - $end);

        return max(0.0, min(1.0, $fraction)) * 100;
    }

    /**
     * Bruno's triage bands: green ≤ 50 · yellow 50–85 · red ≥ 85.
     */
    private function band(float $alphaPct): string
    {
        return match (true) {
            $alphaPct >= 85.0 => 'red',
            $alphaPct > 50.0 => 'yellow',
            default => 'green',
        };
    }

    /**
     * Unrealised PnL from entry vs mark — the same math the trader-side
     * feed uses for open positions.
     */
    private function positionPnl(object $p): ?float
    {
        if ($p->opening_price === null || $p->mark_price === null || $p->quantity === null) {
            return null;
        }

        $diff = $p->direction === 'LONG'
            ? (float) $p->mark_price - (float) $p->opening_price
            : (float) $p->opening_price - (float) $p->mark_price;

        return round($diff * (float) $p->quantity, 2);
    }
}
