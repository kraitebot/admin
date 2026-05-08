<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Kraite\Core\Models\Account;
use Kraite\Core\Support\Financial\AccountFinancials;
use Kraite\Core\Support\Financial\Window;

class ProjectionsController extends Controller
{
    public function index(): View
    {
        $isAdmin = (bool) Auth::user()->is_admin;

        $query = Account::with(['apiSystem', 'user']);
        if (! $isAdmin) {
            $query->where('user_id', Auth::id());
        }

        $accounts = $query
            ->orderBy('user_id')
            ->orderBy('name')
            ->get()
            ->map(fn (Account $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'exchange' => $a->apiSystem?->name ?? 'Unknown',
                'owner' => $a->user?->name ?? 'Unknown',
            ]);

        return view('projections', [
            'accounts' => $accounts,
            'isAdmin' => $isAdmin,
        ]);
    }

    /**
     * Per-month feed for the projections calendar. Returns:
     *  - `actuals`     : map of YYYY-MM-DD → realised wallet delta for that
     *                    day (only days with at least one snapshot).
     *  - `current_wallet`: latest `total_wallet_balance` for the account.
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

        $query = Account::where('id', $request->input('account_id'));
        if (! Auth::user()->is_admin) {
            $query->where('user_id', Auth::id());
        }
        $account = $query->firstOrFail();

        $year = (int) $request->input('year');
        $month = (int) $request->input('month');

        $now = CarbonImmutable::now();
        $monthAnchor = CarbonImmutable::create($year, $month, 1);
        $monthWindow = Window::thisMonth($monthAnchor);
        $currentMonthWindow = Window::thisMonth($now);

        $financials = new AccountFinancials($account);

        return response()->json([
            'account_id' => $account->id,
            'year' => $year,
            'month' => $month,
            'actuals' => $this->normalizeRevenues($financials->dailyRevenues($monthWindow)),
            'current_wallet' => $financials->currentWallet(),
            'month_start_wallet' => $financials->startWallet($monthWindow),
            'scenarios' => $this->normalizeScenarios($financials->scenarios($currentMonthWindow)),
            'today' => $now->toDateString(),
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
