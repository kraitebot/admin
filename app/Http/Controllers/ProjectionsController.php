<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Kraite\Core\Models\Account;

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
            'year'       => ['required', 'integer', 'min:2000', 'max:2100'],
            'month'      => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $query = Account::where('id', $request->input('account_id'));
        if (! Auth::user()->is_admin) {
            $query->where('user_id', Auth::id());
        }
        $account = $query->firstOrFail();

        $year  = (int) $request->input('year');
        $month = (int) $request->input('month');

        $monthStart = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $monthEnd   = (clone $monthStart)->endOfMonth();

        $actuals = $this->dailyRevenues($account->id, $monthStart, $monthEnd);

        return response()->json([
            'account_id'         => $account->id,
            'year'               => $year,
            'month'              => $month,
            'actuals'            => $actuals,
            'current_wallet'     => $this->currentWallet($account->id),
            'month_start_wallet' => $this->monthStartWallet($account->id, $monthStart),
            'scenarios'          => $this->scenariosFromCurrentMonth($account->id),
            'today'              => Carbon::now()->toDateString(),
        ]);
    }

    /**
     * The earliest `total_wallet_balance` snapshot recorded inside the
     * given month — used as the "started month at" anchor on the totals
     * strip for past / current months.
     */
    private function monthStartWallet(int $accountId, Carbon $monthStart): ?string
    {
        $val = DB::table('account_balance_history')
            ->where('account_id', $accountId)
            ->whereBetween('created_at', [
                $monthStart->copy()->startOfMonth()->toDateTimeString(),
                $monthStart->copy()->endOfMonth()->toDateTimeString(),
            ])
            ->orderBy('id')
            ->value('total_wallet_balance');

        return $val !== null ? (string) $val : null;
    }

    /**
     * Last known `total_wallet_balance` for the account — the projection
     * compound chain anchors here.
     */
    private function currentWallet(int $accountId): ?string
    {
        $val = DB::table('account_balance_history')
            ->where('account_id', $accountId)
            ->orderByDesc('id')
            ->value('total_wallet_balance');

        return $val !== null ? (string) $val : null;
    }

    /**
     * Pessimistic / neutral / optimistic daily percentages derived from
     * the current calendar month's day-by-day deltas. % per day = revenue
     * / wallet-at-day-start, so each scenario stays compoundable.
     *
     * Returns nulls when the month has no data — frontend will hide the
     * scenario switcher / projected cells instead of painting noise.
     *
     * @return array<string, string|null>
     */
    private function scenariosFromCurrentMonth(int $accountId): array
    {
        $now = Carbon::now();
        $start = (clone $now)->startOfMonth();
        $end   = (clone $now)->endOfDay();

        $revs = $this->dailyRevenues($accountId, $start, $end);
        $pcts = $this->dailyPercentages($accountId, $start, $end);

        if (empty($pcts)) {
            return [
                'pessimistic_pct' => null,
                'neutral_pct'     => null,
                'optimistic_pct'  => null,
                'days_observed'   => 0,
            ];
        }

        $values = array_values($pcts);
        sort($values);

        $worst = (float) $values[0];
        $best  = (float) end($values);
        $mid   = ($worst + $best) / 2.0;

        return [
            'pessimistic_pct' => number_format($worst, 6, '.', ''),
            'neutral_pct'     => number_format($mid, 6, '.', ''),
            'optimistic_pct'  => number_format($best, 6, '.', ''),
            'days_observed'   => count($pcts),
            'days_with_revenue' => count($revs),
        ];
    }

    /**
     * Aggregate daily wallet revenue for a date window. Revenue per day =
     * last snapshot's `total_wallet_balance` − first snapshot's
     * `total_wallet_balance` of the same day. Days with zero snapshots
     * are simply absent from the map — the frontend reads "no data".
     *
     * @return array<string, string>  Keyed by YYYY-MM-DD → string revenue.
     */
    private function dailyRevenues(int $accountId, Carbon $start, Carbon $end): array
    {
        $rows = DB::table('account_balance_history')
            ->select(DB::raw('DATE(created_at) AS d'))
            ->selectRaw('SUBSTRING_INDEX(GROUP_CONCAT(total_wallet_balance ORDER BY id ASC SEPARATOR ","), ",", 1) AS first_wallet')
            ->selectRaw('SUBSTRING_INDEX(GROUP_CONCAT(total_wallet_balance ORDER BY id DESC SEPARATOR ","), ",", 1) AS last_wallet')
            ->where('account_id', $accountId)
            ->whereBetween('created_at', [$start->toDateTimeString(), $end->toDateTimeString()])
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('d')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $first = (string) $row->first_wallet;
            $last  = (string) $row->last_wallet;
            if (! is_numeric($first) || ! is_numeric($last)) {
                continue;
            }
            $delta = bcsub($last, $first, 8);
            $out[$row->d] = number_format((float) $delta, 4, '.', '');
        }

        return $out;
    }

    /**
     * Daily percentage returns for the same window, used to derive the
     * worst / best / midpoint scenario rates. % = revenue ÷ first-of-day
     * wallet. A day with zero starting wallet is skipped (the % would be
     * undefined).
     *
     * @return array<string, string>
     */
    private function dailyPercentages(int $accountId, Carbon $start, Carbon $end): array
    {
        $rows = DB::table('account_balance_history')
            ->select(DB::raw('DATE(created_at) AS d'))
            ->selectRaw('SUBSTRING_INDEX(GROUP_CONCAT(total_wallet_balance ORDER BY id ASC SEPARATOR ","), ",", 1) AS first_wallet')
            ->selectRaw('SUBSTRING_INDEX(GROUP_CONCAT(total_wallet_balance ORDER BY id DESC SEPARATOR ","), ",", 1) AS last_wallet')
            ->where('account_id', $accountId)
            ->whereBetween('created_at', [$start->toDateTimeString(), $end->toDateTimeString()])
            ->groupBy(DB::raw('DATE(created_at)'))
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $first = (string) $row->first_wallet;
            $last  = (string) $row->last_wallet;
            if (! is_numeric($first) || ! is_numeric($last) || bccomp($first, '0', 8) <= 0) {
                continue;
            }
            $delta = bcsub($last, $first, 16);
            $pct = bcdiv($delta, $first, 8);
            $out[$row->d] = $pct;
        }

        return $out;
    }
}
