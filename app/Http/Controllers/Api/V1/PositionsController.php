<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PositionsRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\CursorPaginator;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\Position;

final class PositionsController extends Controller
{
    private const PAGE_SIZE = 20;

    private const POSITION_COLUMNS = [
        'id', 'account_id', 'exchange_symbol_id', 'parsed_trading_pair',
        'direction', 'leverage', 'opened_at', 'closed_at', 'opening_price',
        'closing_price', 'margin', 'quantity', 'pnl', 'was_waped', 'was_fast_traded',
    ];

    public function __invoke(PositionsRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $accounts = $this->accountsFor($request->user()->id);
        $accountId = isset($validated['account_id']) ? (int) $validated['account_id'] : null;
        $selected = $this->selectedAccount($accounts, $accountId);

        return response()->json([
            'data' => [
                'accounts' => $accounts->map(fn (Account $account): array => $this->serializeAccount($account))->values(),
                'selected_account_id' => $selected?->id,
                'history' => $selected ? $this->historyFor($selected) : null,
            ],
        ]);
    }

    /**
     * @return Collection<int, Account>
     */
    private function accountsFor(int $userId): Collection
    {
        return Account::query()
            ->with(['apiSystem', 'user.subscription'])
            ->where('user_id', $userId)
            ->orderBy('name')
            ->get();
    }

    /** @param Collection<int, Account> $accounts */
    private function selectedAccount(Collection $accounts, ?int $accountId): ?Account
    {
        $selected = $accountId !== null
            ? $accounts->firstWhere('id', $accountId)
            : $accounts->first();

        if ($accountId !== null && ! $selected) {
            abort(404);
        }

        return $selected;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAccount(Account $account): array
    {
        return [
            'id' => $account->id,
            'name' => $account->name,
            'exchange' => $account->apiSystem?->name ?? 'Unknown',
            'is_trading' => $account->isReadyToTrade(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function historyFor(Account $account): array
    {
        $query = $this->closedPositions($account);
        $summary = $this->positionSummaryFor($query);
        $positions = $this->positionPageFor($query);

        return [
            'summary' => $summary,
            'positions' => collect($positions->items())
                ->map(fn (Position $position): array => $this->serializePosition($position))
                ->values(),
            'next_cursor' => $positions->nextCursor()?->encode(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  Builder<Position>  $query
     * @return array{count: int, long: int, short: int, wins: int, losses: int, realized_pnl: ?string}
     */
    private function positionSummaryFor(Builder $query): array
    {
        $summary = (clone $query)
            ->selectRaw('COUNT(*) as position_count')
            ->selectRaw("SUM(CASE WHEN direction = 'LONG' THEN 1 ELSE 0 END) as long_count")
            ->selectRaw("SUM(CASE WHEN direction = 'SHORT' THEN 1 ELSE 0 END) as short_count")
            ->selectRaw('SUM(CASE WHEN '.$this->realizedPnlExpression().' > 0 THEN 1 ELSE 0 END) as wins')
            ->selectRaw('SUM(CASE WHEN '.$this->realizedPnlExpression().' < 0 THEN 1 ELSE 0 END) as losses')
            ->selectRaw('SUM('.$this->realizedPnlExpression().') as realized_pnl')
            ->first();

        return [
            'count' => (int) ($summary?->position_count ?? 0),
            'long' => (int) ($summary?->long_count ?? 0),
            'short' => (int) ($summary?->short_count ?? 0),
            'wins' => (int) ($summary?->wins ?? 0),
            'losses' => (int) ($summary?->losses ?? 0),
            'realized_pnl' => $summary?->realized_pnl !== null
                ? $this->trimNumber((string) $summary->realized_pnl)
                : null,
        ];
    }

    /**
     * @param  Builder<Position>  $query
     * @return CursorPaginator<int, Position>
     */
    private function positionPageFor(Builder $query): CursorPaginator
    {
        return $query
            ->select(self::POSITION_COLUMNS)
            ->with([
                'exchangeSymbol:id,symbol_id,quote,price_precision,quantity_precision,tick_size',
                'exchangeSymbol.symbol:id,token,name,image_url',
            ])
            ->orderByDesc('closed_at')
            ->orderByDesc('id')
            ->cursorPaginate(self::PAGE_SIZE);
    }

    /**
     * @return Builder<Position>
     */
    private function closedPositions(Account $account): Builder
    {
        return Position::query()
            ->whereBelongsTo($account)
            ->where('status', 'closed')
            ->whereNotNull('closed_at');
    }

    private function realizedPnlExpression(): string
    {
        return "COALESCE(pnl, CASE
            WHEN opening_price IS NULL OR closing_price IS NULL OR quantity IS NULL THEN NULL
            WHEN direction = 'LONG' THEN (closing_price - opening_price) * quantity
            WHEN direction = 'SHORT' THEN (opening_price - closing_price) * quantity
            ELSE NULL
        END)";
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePosition(Position $position): array
    {
        return [
            ...$this->positionIdentity($position),
            ...$this->positionTrade($position),
            ...$this->positionFinancials($position),
            'was_waped' => (bool) $position->was_waped,
            'was_fast_traded' => (bool) $position->was_fast_traded,
        ];
    }

    /** @return array<string, int|string|null> */
    private function positionIdentity(Position $position): array
    {
        $exchangeSymbol = $position->exchangeSymbol;
        $symbol = $exchangeSymbol?->symbol;

        return [
            'id' => $position->id,
            'symbol' => $this->positionSymbol($position, $symbol?->token, $exchangeSymbol?->quote),
            'token' => $symbol?->token,
            'token_name' => $symbol?->name,
            'token_image' => $symbol?->image_url,
        ];
    }

    private function positionSymbol(Position $position, ?string $token, ?string $quote): string
    {
        return $position->parsed_trading_pair
            ?? ($token && $quote ? "{$token}{$quote}" : $token)
            ?? 'Unknown';
    }

    /** @return array<string, int|string|null> */
    private function positionTrade(Position $position): array
    {
        $durationSeconds = $position->opened_at && $position->closed_at
            ? (int) $position->opened_at->diffInSeconds($position->closed_at, true)
            : null;

        return [
            'direction' => mb_strtoupper((string) $position->direction),
            'leverage' => (int) $position->leverage,
            'opened_at' => $position->opened_at?->toIso8601String(),
            'closed_at' => $position->closed_at?->toIso8601String(),
            'duration_seconds' => $durationSeconds,
        ];
    }

    /** @return array<string, string|float|null> */
    private function positionFinancials(Position $position): array
    {
        $exchangeSymbol = $position->exchangeSymbol;
        $pnl = $this->realizedPnl($position);
        $margin = $position->margin !== null ? (string) $position->margin : null;

        return [
            'entry_price' => $this->formatPrice($position->opening_price, $exchangeSymbol),
            'exit_price' => $this->formatPrice($position->closing_price, $exchangeSymbol),
            'quantity' => $this->formatQuantity($position->quantity, $exchangeSymbol),
            'margin' => $this->formattedMargin($margin),
            'pnl' => $pnl,
            'return_pct' => $pnl !== null && $margin !== null && bccomp($margin, '0', 8) > 0
                ? round((float) bcdiv(bcmul($pnl, '100', 8), $margin, 8), 2)
                : null,
        ];
    }

    private function formattedMargin(?string $margin): ?string
    {
        return $margin !== null ? $this->trimNumber($margin) : null;
    }

    private function realizedPnl(Position $position): ?string
    {
        return $position->pnl !== null
            ? $this->trimNumber((string) $position->pnl)
            : $this->calculatedPnl($position);
    }

    private function calculatedPnl(Position $position): ?string
    {
        if ($position->opening_price === null || $position->closing_price === null || $position->quantity === null) {
            return null;
        }

        $difference = mb_strtoupper((string) $position->direction) === 'SHORT'
            ? bcsub((string) $position->opening_price, (string) $position->closing_price, 18)
            : bcsub((string) $position->closing_price, (string) $position->opening_price, 18);

        return $this->trimNumber(bcmul($difference, (string) $position->quantity, 18));
    }

    private function formatPrice(mixed $price, mixed $exchangeSymbol): ?string
    {
        if ($price === null) {
            return null;
        }

        return $exchangeSymbol
            ? api_format_price((string) $price, $exchangeSymbol)
            : $this->trimNumber((string) $price);
    }

    private function formatQuantity(mixed $quantity, mixed $exchangeSymbol): ?string
    {
        if ($quantity === null) {
            return null;
        }

        return $exchangeSymbol
            ? api_format_quantity((string) $quantity, $exchangeSymbol)
            : $this->trimNumber((string) $quantity);
    }

    private function trimNumber(string $number): string
    {
        if (str_contains($number, '.')) {
            $number = mb_rtrim(mb_rtrim($number, '0'), '.');
        }

        return $number === '' || $number === '-' ? '0' : $number;
    }
}
