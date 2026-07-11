<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\Position;

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

            $worstAlpha = (float) $accountPositions->max(fn (Position $p): float => $this->alphaPct($p));

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
        return $this->openPositions($accountId)
            ->map(fn (Position $p): array => $this->positionRow($p))
            ->all();
    }

    // ------------------------------------------------------------------
    // Shared queries + math — the ENGINE'S OWN getters (Bruno's call:
    // same Position methods as every other surface, zero drift).
    // ------------------------------------------------------------------

    /**
     * Open positions as real models so rows read the canonical getters:
     * unrealizedPnl(), alphaPathPercent(), nextPendingLimitOrderPrice().
     * Row counts here are small (a few per account), so the getters'
     * per-position queries are fine.
     *
     * @return Collection<int, Position>
     */
    private function openPositions(?int $accountId = null): Collection
    {
        return Position::query()
            ->where('is_open', self::OPEN_FLAG)
            ->when($accountId !== null, fn ($q) => $q->where('account_id', $accountId))
            ->with(['exchangeSymbol', 'orders'])
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function positionRow(Position $p): array
    {
        $alpha = $this->alphaPct($p);
        $limits = $p->orders->where('type', 'LIMIT')->whereNotNull('exchange_order_id');

        return [
            'id' => (int) $p->id,
            'symbol' => $p->parsed_trading_pair ?: ($p->exchangeSymbol?->token ?? '?'),
            'direction' => $p->direction,
            'rungs_filled' => $limits->where('status', 'FILLED')->count(),
            'rungs_total' => (int) $p->total_limit_orders,
            'alpha_pct' => round($alpha, 1),
            'alpha_limit_pct' => $this->alphaLimitPct($p),
            'band' => $this->band($alpha),
            'pnl' => $this->pnl($p),
            'opened_at' => optional($p->created_at)->format('Y-m-d H:i:s'),
            'ladder' => $this->ladder($p, $alpha),
        ];
    }

    /**
     * The ladder drawing: TP anchors the corridor at 0%, the deepest live
     * rung at 100%, every rung tick and the live price marker placed at
     * their proportional positions — the same corridor scale as alpha
     * path, so the price marker sits exactly at the alpha-path percent.
     *
     * @return array<string, mixed>|null
     */
    private function ladder(Position $p, float $alphaPct): ?array
    {
        $tp = $p->first_profit_price !== null ? (float) $p->first_profit_price : null;
        $mark = $p->exchangeSymbol?->mark_price !== null ? (float) $p->exchangeSymbol->mark_price : null;

        // Live rungs in ladder order (quantity ascending — martingale
        // doubles each rung), same liveness rule as the engine's
        // lastLimitOrder(): placed on the exchange, not terminal.
        $rungs = $p->orders
            ->where('type', 'LIMIT')
            ->whereNotNull('exchange_order_id')
            ->whereIn('status', ['NEW', 'PARTIALLY_FILLED', 'FILLED'])
            ->sortBy(fn ($o) => (float) $o->quantity)
            ->values();

        $deepest = $rungs->last()?->price;
        $deepest = $deepest !== null ? (float) $deepest : null;

        if ($tp === null || $deepest === null || $tp === $deepest) {
            return null;
        }

        $span = $deepest - $tp;
        $pct = fn (float $price): float => round(max(0.0, min(1.0, ($price - $tp) / $span)) * 100, 1);

        return [
            'tp_price' => $tp,
            'deepest_price' => $deepest,
            'mark_price' => $mark,
            'price_pct' => round($alphaPct, 1),
            'rungs' => $rungs->map(fn ($o, int $i): array => [
                'n' => $i + 1,
                'price' => (float) $o->price,
                'pct' => $pct((float) $o->price),
                'filled' => $o->status === 'FILLED',
            ])->all(),
        ];
    }

    /**
     * Alpha path % via the engine's own getter. Its price source is the
     * 5m candle close (null when stale — pheme holds no fresh candles),
     * so when the engine reads no price we fall back to the SAME corridor
     * math on the live mark price rather than showing a dead 0.
     */
    private function alphaPct(Position $p): float
    {
        try {
            if ($p->exchangeSymbol?->current_price !== null) {
                return (float) $p->alphaPathPercent();
            }
        } catch (\Throwable) {
            // candles table absent (dev suite) — fall through to mark math
        }

        $start = $p->first_profit_price !== null ? (float) $p->first_profit_price : null;
        $end = null;
        try {
            $end = $p->lastLimitOrder()?->price;
            $end = $end !== null ? (float) $end : null;
        } catch (\Throwable) {
            $end = null;
        }
        $current = $p->exchangeSymbol?->mark_price !== null ? (float) $p->exchangeSymbol->mark_price : null;

        if ($start === null || $end === null || $current === null || $start === $end) {
            return 0.0;
        }

        $fraction = ($start - $current) / ($start - $end);

        return max(0.0, min(1.0, $fraction)) * 100;
    }

    /**
     * Alpha limit % — distance price still has to travel to fill the NEXT
     * pending rung (engine's qty-ascending pending getter), as a percent
     * of the current mark. 0 when price already sits at/past the rung.
     */
    private function alphaLimitPct(Position $p): ?float
    {
        try {
            $next = $p->nextPendingLimitOrderPrice();
        } catch (\Throwable) {
            return null;
        }
        $mark = $p->exchangeSymbol?->mark_price;

        if ($next === null || $mark === null || (float) $mark === 0.0) {
            return null;
        }

        $distance = $p->direction === 'LONG'
            ? ((float) $mark - (float) $next) / (float) $mark
            : ((float) $next - (float) $mark) / (float) $mark;

        return round(max(0.0, $distance) * 100, 1);
    }

    /**
     * Bruno's triage bands: green <= 50 - yellow 50-85 - red >= 85.
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
     * The engine's unrealizedPnl(): live mark vs the cost-weighted average
     * entry across every filled order — identical numbers to the trader
     * surfaces. Null (rendered as em-dash) before the first fill lands.
     */
    private function pnl(Position $p): ?float
    {
        try {
            $value = $p->unrealizedPnl();
        } catch (\Throwable) {
            return null;
        }

        return $value !== null && is_numeric($value) ? round((float) $value, 2) : null;
    }
}
