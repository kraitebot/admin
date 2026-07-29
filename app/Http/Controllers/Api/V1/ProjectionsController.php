<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProjectionsRequest;
use App\Services\YearlyProjectionPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\Financial\AccountFinancials;
use Kraite\Core\Support\Financial\FleetFinancials;
use Kraite\Core\Support\Financial\Window;

class ProjectionsController extends Controller
{
    public function __invoke(
        ProjectionsRequest $request,
        YearlyProjectionPlanner $planner,
    ): JsonResponse {
        $validated = $request->validated();
        $now = CarbonImmutable::now();
        $accounts = $this->accountsFor($request->user()->id);
        $accountId = isset($validated['account_id']) ? (int) $validated['account_id'] : null;
        $selected = $accountId !== null
            ? $accounts->firstWhere('id', $accountId)
            : $accounts->first();

        if ($accountId !== null && ! $selected) {
            abort(404);
        }

        $year = (int) ($validated['year'] ?? $now->year);
        $month = (int) ($validated['month'] ?? $now->month);

        return response()->json([
            'data' => [
                'accounts' => $accounts
                    ->map(fn (Account $account): array => $this->serializeAccount($account))
                    ->values(),
                'selected_account_id' => $selected?->id,
                'calendar' => $selected
                    ? $this->calendarFor($selected, $year, $month, $now)
                    : null,
                'yearly' => $this->yearlyFor($accounts, $planner, $now),
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
    private function calendarFor(
        Account $account,
        int $year,
        int $month,
        CarbonImmutable $now,
    ): array {
        $financials = new AccountFinancials($account);

        // Same day basis the web calendar uses — the phone must not slice the
        // month on different hours than the browser does.
        $dayBasis = $financials->reportingDay();

        // Midday, not midnight: a trader west of UTC would see 00:00 on the
        // 1st shifted back into the previous month, and the whole calendar
        // would silently render the wrong month.
        $monthAnchor = CarbonImmutable::create($year, $month, 1, 12);
        $monthWindow = Window::thisMonth($monthAnchor, $dayBasis);

        $investmentBasis = $financials->investmentBasis();

        return [
            'account_id' => $account->id,
            'year' => $year,
            'month' => $month,
            'actuals' => collect($financials->dailyRevenues($monthWindow))
                ->map(fn (string $value): string => number_format((float) $value, 4, '.', ''))
                ->all(),
            'current_wallet' => $investmentBasis['current_wallet'],
            'month_start_wallet' => $financials->startWallet($monthWindow),
            'realized_roi_pct' => $financials->realizedRoiPct($monthWindow),
            'scenarios' => $this->normalizeScenarios(
                $financials->scenarios(Window::thisMonth($now, $dayBasis)),
            ),
            'investment_basis' => [
                'amount' => $this->normalizeMoney($investmentBasis['amount']),
                'known_realized_pnl' => $this->normalizeMoney($investmentBasis['known_realized_pnl']),
                'tracking_started_at' => $investmentBasis['tracking_started_at'],
                'tracking_ended_at' => $investmentBasis['tracking_ended_at'],
                'closed_positions' => $investmentBasis['closed_positions'],
                'missing_pnl_positions' => $investmentBasis['missing_pnl_positions'],
                'is_complete' => $investmentBasis['is_complete'],
            ],
            'today' => $dayBasis->dateOf($now),
            'utc_offset_minutes' => $dayBasis->offsetMinutes,
            'day_basis_label' => $dayBasis->label(),
        ];
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @return array<string, mixed>
     */
    private function yearlyFor(
        Collection $accounts,
        YearlyProjectionPlanner $planner,
        CarbonImmutable $now,
    ): array {
        $financials = new FleetFinancials($accounts);
        $scenarios = $financials->scenarios(Window::thisMonth($now));
        $currentWallet = $financials->totalCurrentWallet();

        return [
            'account_count' => $financials->count(),
            'current_wallet' => $currentWallet,
            'days_observed' => $scenarios['days_observed'],
            'today' => $now->toDateString(),
            'outlook' => $planner->plan(
                currentWallet: $currentWallet,
                scenarios: $scenarios,
                now: $now,
            ),
        ];
    }

    /**
     * @param  array{
     *     pessimistic_pct: ?string,
     *     neutral_pct: ?string,
     *     optimistic_pct: ?string,
     *     days_observed: int
     * }  $scenarios
     * @return array<string, string|int|null>
     */
    private function normalizeScenarios(array $scenarios): array
    {
        $format = static fn (?string $value): ?string => $value === null
            ? null
            : number_format((float) $value, 6, '.', '');

        return [
            'pessimistic_pct' => $format($scenarios['pessimistic_pct']),
            'neutral_pct' => $format($scenarios['neutral_pct']),
            'optimistic_pct' => $format($scenarios['optimistic_pct']),
            'days_observed' => $scenarios['days_observed'],
            'days_with_revenue' => $scenarios['days_observed'],
        ];
    }

    private function normalizeMoney(?string $value): ?string
    {
        return $value === null ? null : number_format((float) $value, 4, '.', '');
    }
}
