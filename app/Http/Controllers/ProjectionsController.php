<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\YearlyProjectionPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Financial\AccountFinancials;
use Kraite\Core\Support\Financial\FleetFinancials;
use Kraite\Core\Support\Financial\Window;

class ProjectionsController extends Controller
{
    public function index(): View
    {
        $accounts = Account::with(['apiSystem', 'user'])
            ->where('user_id', Auth::id())
            ->orderBy('user_id')
            ->orderBy('name')
            ->get()
            ->map(fn (Account $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'exchange' => $a->apiSystem?->name ?? 'Unknown',
                'owner' => $a->user?->name ?? 'Unknown',
            ]);

        // First-run gate: projections are built from realized revenue — an
        // account that has never traded has nothing to project. On any query
        // failure default to SHOWING the page (the data feed degrades on its
        // own) rather than hiding it behind the first-run screen.
        return view('projections', [
            'accounts' => $accounts,
            'noPositions' => $this->hasNoPositions(),
        ]);
    }

    /**
     * Per-month feed for the projections calendar. Returns:
     *  - `actuals`     : map of YYYY-MM-DD → exchange-reported net PnL from
     *                    positions closed on that day.
     *  - `current_wallet`: latest `total_wallet_balance` for the account.
     *  - `realized_roi_pct`: what the viewed month has actually returned —
     *                    its daily rates chained (time-weighted), so a
     *                    deposit inside the month never reads as a gain.
     *  - `investment_basis`: auto-assessed personal capital still funding
     *                    the account, plus PnL-coverage metadata.
     *  - `scenarios`   : pessimistic / neutral / optimistic *daily*
     *                    percentages, computed from the **current calendar
     *                    month** to-date (independent of which month the
     *                    operator is currently viewing).
     *  - `today`       : server-side today (YYYY-MM-DD) so the frontend
     *                    paints past vs future cells against an authoritative
     *                    boundary instead of the browser's clock.
     */
    public function data(Request $request): JsonResponse
    {
        $request->validate([
            'account_id' => ['required', 'integer'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $account = Account::query()
            ->where('id', $request->input('account_id'))
            ->where('user_id', Auth::id())
            ->firstOrFail();

        $year = (int) $request->input('year');
        $month = (int) $request->input('month');

        $now = CarbonImmutable::now();
        $financials = new AccountFinancials($account);

        // Calendar days, the month's edges and "today" all follow the day
        // basis the trader's exchange uses, so a close at 22:30 UTC lands on
        // the same square here as it does on their exchange statement.
        $dayBasis = $financials->reportingDay();
        // Midday, not midnight: a trader west of UTC would see 00:00 on the
        // 1st shifted back into the previous month, and the whole calendar
        // would silently render the wrong month.
        $monthAnchor = CarbonImmutable::create($year, $month, 1, 12);
        $monthWindow = Window::thisMonth($monthAnchor, $dayBasis);
        $currentMonthWindow = Window::thisMonth($now, $dayBasis);

        $investmentBasis = $financials->investmentBasis();

        return response()->json([
            'account_id' => $account->id,
            'year' => $year,
            'month' => $month,
            'actuals' => $this->normalizeRevenues($financials->dailyRevenues($monthWindow)),
            'current_wallet' => $investmentBasis['current_wallet'],
            'month_start_wallet' => $financials->startWallet($monthWindow),
            'realized_roi_pct' => $financials->realizedRoiPct($monthWindow),
            'scenarios' => $this->normalizeScenarios($financials->scenarios($currentMonthWindow)),
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
        ]);
    }

    public function yearly(): View
    {
        return view('projections.yearly', [
            'noPositions' => $this->hasNoPositions(),
        ]);
    }

    private function hasNoPositions(): bool
    {
        return rescue(fn (): bool => ! Position::query()
            ->whereIn(
                'account_id',
                Account::where('user_id', Auth::id())->select('id'),
            )
            ->exists(), false);
    }

    public function yearlyData(YearlyProjectionPlanner $planner): JsonResponse
    {
        // `incomes_synced_from` comes along deliberately: the financial engine
        // reads it off the account to decide whether a window can be built
        // from the exchange income ledger, and an account hydrated without it
        // would quietly fall back to close-day figures.
        $accounts = Account::query()
            ->where('user_id', Auth::id())
            ->get(['id', 'incomes_synced_from']);
        $now = CarbonImmutable::now();
        $financials = new FleetFinancials($accounts);
        $scenarios = $financials->scenarios(Window::thisMonth($now));
        $currentWallet = $financials->totalCurrentWallet();

        return response()->json([
            'account_count' => $financials->count(),
            'current_wallet' => $currentWallet,
            'days_observed' => $scenarios['days_observed'],
            'today' => $now->toDateString(),
            'outlook' => $planner->plan(
                currentWallet: $currentWallet,
                scenarios: $scenarios,
                now: $now,
            ),
        ]);
    }

    /**
     * Convert the bcmath-scaled deltas returned by AccountFinancials
     * into the 4-decimal strings the existing frontend expects.
     *
     * @param  array<string, string>  $rev
     * @return array<string, string>
     */
    private function normalizeRevenues(array $rev): array
    {
        $out = [];
        foreach ($rev as $day => $delta) {
            $out[$day] = number_format((float) $delta, 4, '.', '');
        }

        return $out;
    }

    private function normalizeMoney(?string $value): ?string
    {
        return $value === null ? null : number_format((float) $value, 4, '.', '');
    }

    /**
     * Match the legacy payload shape consumed by `projectionsPage()`
     * Alpine state — 6-decimal pct strings + a `days_with_revenue`
     * companion count. AccountFinancials only emits the worst/best/mid
     * trio, so derive the revenue-day count locally.
     *
     * @param  array{pessimistic_pct: ?string, neutral_pct: ?string, optimistic_pct: ?string, days_observed: int}  $scenarios
     * @return array<string, string|int|null>
     */
    private function normalizeScenarios(array $scenarios): array
    {
        $fmt = static function (?string $v): ?string {
            return $v === null ? null : number_format((float) $v, 6, '.', '');
        };

        return [
            'pessimistic_pct' => $fmt($scenarios['pessimistic_pct']),
            'neutral_pct' => $fmt($scenarios['neutral_pct']),
            'optimistic_pct' => $fmt($scenarios['optimistic_pct']),
            'days_observed' => $scenarios['days_observed'],
            'days_with_revenue' => $scenarios['days_observed'],
        ];
    }
}
