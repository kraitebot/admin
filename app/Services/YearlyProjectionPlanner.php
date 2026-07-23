<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Kraite\Core\Support\Financial\ProjectionCompounder;

final class YearlyProjectionPlanner
{
    /**
     * @param  array{
     *     pessimistic_pct: ?string,
     *     neutral_pct: ?string,
     *     optimistic_pct: ?string,
     *     days_observed: int
     * }  $scenarios
     * @return array{
     *     years: int,
     *     scenarios: array<string, array{
     *         daily_pct: ?string,
     *         available: bool,
     *         reason: ?string,
     *         milestones: array<int, array{
     *             year: int,
     *             label: string,
     *             end_date: string,
     *             days: int,
     *             end_wallet: ?string,
     *             projected_profit: ?string,
     *             growth_pct: ?string,
     *             multiple: ?string
     *         }>
     *     }>
     * }
     */
    public function plan(
        ?string $currentWallet,
        array $scenarios,
        CarbonImmutable $now,
        int $years = 5,
    ): array {
        if ($years < 1) {
            throw new InvalidArgumentException('Yearly projection needs at least one year.');
        }

        $scenarioRates = [
            'pessimistic' => $scenarios['pessimistic_pct'],
            'neutral' => $scenarios['neutral_pct'],
            'optimistic' => $scenarios['optimistic_pct'],
        ];

        $outlook = [];

        foreach ($scenarioRates as $scenario => $dailyPct) {
            $reason = $this->unavailableReason(
                currentWallet: $currentWallet,
                dailyPct: $dailyPct,
                daysObserved: $scenarios['days_observed'],
            );
            $available = $reason === null;

            $outlook[$scenario] = [
                'daily_pct' => $dailyPct,
                'available' => $available,
                'reason' => $reason,
                'milestones' => $this->milestones(
                    currentWallet: $currentWallet,
                    dailyPct: $dailyPct,
                    now: $now,
                    years: $years,
                    available: $available,
                ),
            ];
        }

        return [
            'years' => $years,
            'scenarios' => $outlook,
        ];
    }

    private function unavailableReason(?string $currentWallet, ?string $dailyPct, int $daysObserved): ?string
    {
        if ($currentWallet === null || bccomp($currentWallet, '0', 8) <= 0) {
            return 'no_wallet';
        }

        if ($daysObserved < 1 || $dailyPct === null) {
            return 'no_observations';
        }

        if (bccomp($dailyPct, '-1', 16) <= 0) {
            return 'invalid_rate';
        }

        return null;
    }

    /**
     * @return array<int, array{
     *     year: int,
     *     label: string,
     *     end_date: string,
     *     days: int,
     *     end_wallet: ?string,
     *     projected_profit: ?string,
     *     growth_pct: ?string,
     *     multiple: ?string
     * }>
     */
    private function milestones(
        ?string $currentWallet,
        ?string $dailyPct,
        CarbonImmutable $now,
        int $years,
        bool $available,
    ): array {
        $milestones = [];

        for ($offset = 0; $offset < $years; $offset++) {
            $year = $now->year + $offset;
            $target = $now->setYear($year)->endOfYear();
            $days = (int) $now->startOfDay()->diffInDays($target->startOfDay());

            $milestone = [
                'year' => $year,
                'label' => $offset === 0 ? 'Until end of year' : $year.' end',
                'end_date' => $target->toDateString(),
                'days' => $days,
                'end_wallet' => null,
                'projected_profit' => null,
                'growth_pct' => null,
                'multiple' => null,
            ];

            if ($available && $currentWallet !== null && $dailyPct !== null) {
                $endWallet = ProjectionCompounder::compoundWallet(
                    currentWallet: $currentWallet,
                    dailyPct: $dailyPct,
                    from: $now,
                    until: $target,
                );
                $projectedProfit = bcsub($endWallet, $currentWallet, 8);

                $milestone['end_wallet'] = $endWallet;
                $milestone['projected_profit'] = $projectedProfit;
                $milestone['growth_pct'] = bcmul(
                    bcdiv($projectedProfit, $currentWallet, 16),
                    '100',
                    8,
                );
                $milestone['multiple'] = bcdiv($endWallet, $currentWallet, 8);
            }

            $milestones[] = $milestone;
        }

        return $milestones;
    }
}
