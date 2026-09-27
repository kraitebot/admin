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

        $outlook = [];

        foreach ($this->scenarioRates($scenarios) as $scenario => $dailyPct) {
            $outlook[$scenario] = $this->scenarioOutlook(
                $currentWallet,
                $dailyPct,
                $scenarios['days_observed'],
                $now,
                $years,
            );
        }

        return [
            'years' => $years,
            'scenarios' => $outlook,
        ];
    }

    /**
     * @param  array{pessimistic_pct: ?string, neutral_pct: ?string, optimistic_pct: ?string, days_observed: int}  $scenarios
     * @return array{pessimistic: ?string, neutral: ?string, optimistic: ?string}
     */
    private function scenarioRates(array $scenarios): array
    {
        return [
            'pessimistic' => $scenarios['pessimistic_pct'],
            'neutral' => $scenarios['neutral_pct'],
            'optimistic' => $scenarios['optimistic_pct'],
        ];
    }

    /**
     * @return array{daily_pct: ?string, available: bool, reason: ?string, milestones: array<int, array{year: int, label: string, end_date: string, days: int, end_wallet: ?string, projected_profit: ?string, growth_pct: ?string, multiple: ?string}>}
     */
    private function scenarioOutlook(
        ?string $currentWallet,
        ?string $dailyPct,
        int $daysObserved,
        CarbonImmutable $now,
        int $years,
    ): array {
        $reason = $this->unavailableReason($currentWallet, $dailyPct, $daysObserved);
        $available = $reason === null;

        return [
            'daily_pct' => $dailyPct,
            'available' => $available,
            'reason' => $reason,
            'milestones' => $this->milestones($currentWallet, $dailyPct, $now, $years, $available),
        ];
    }

    private function unavailableReason(?string $currentWallet, ?string $dailyPct, int $daysObserved): ?string
    {
        if ($this->hasNoWallet($currentWallet)) {
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

    private function hasNoWallet(?string $currentWallet): bool
    {
        return $currentWallet === null || bccomp($currentWallet, '0', 8) <= 0;
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
            $milestones[] = $this->milestoneFor($currentWallet, $dailyPct, $now, $offset, $available);
        }

        return $milestones;
    }

    /**
     * @return array{year: int, label: string, end_date: string, days: int, end_wallet: ?string, projected_profit: ?string, growth_pct: ?string, multiple: ?string}
     */
    private function milestoneFor(
        ?string $currentWallet,
        ?string $dailyPct,
        CarbonImmutable $now,
        int $offset,
        bool $available,
    ): array {
        $year = $now->year + $offset;
        $target = $now->setYear($year)->endOfYear();
        $milestone = [
            'year' => $year,
            'label' => $offset === 0 ? 'Until end of year' : $year.' end',
            'end_date' => $target->toDateString(),
            'days' => (int) $now->startOfDay()->diffInDays($target->startOfDay()),
            'end_wallet' => null,
            'projected_profit' => null,
            'growth_pct' => null,
            'multiple' => null,
        ];

        if (! $available || $currentWallet === null || $dailyPct === null) {
            return $milestone;
        }

        return array_replace($milestone, $this->projectionFor($currentWallet, $dailyPct, $now, $target));
    }

    /** @return array{end_wallet: string, projected_profit: string, growth_pct: string, multiple: string} */
    private function projectionFor(string $currentWallet, string $dailyPct, CarbonImmutable $now, CarbonImmutable $target): array
    {
        $endWallet = ProjectionCompounder::compoundWallet(
            currentWallet: $currentWallet,
            dailyPct: $dailyPct,
            from: $now,
            until: $target,
        );
        $projectedProfit = bcsub($endWallet, $currentWallet, 8);

        return [
            'end_wallet' => $endWallet,
            'projected_profit' => $projectedProfit,
            'growth_pct' => bcmul(bcdiv($projectedProfit, $currentWallet, 16), '100', 8),
            'multiple' => bcdiv($endWallet, $currentWallet, 8),
        ];
    }
}
