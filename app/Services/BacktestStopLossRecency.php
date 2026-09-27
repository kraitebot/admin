<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Throwable;

final class BacktestStopLossRecency
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{LONG: string|null, SHORT: string|null}
     */
    public function latestByDirection(array $rows): array
    {
        $latest = ['LONG' => null, 'SHORT' => null];

        foreach ($rows as $row) {
            if (! $this->isStoppedPosition($row)) {
                continue;
            }

            $candidate = $this->stoppedAt($row['sl_candle']);
            if ($candidate === null) {
                continue;
            }

            $direction = $row['direction'];
            $latest[$direction] = $this->newerStop($latest[$direction], $candidate);
        }

        return [
            'LONG' => $latest['LONG']?->toIso8601String(),
            'SHORT' => $latest['SHORT']?->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $row */
    private function isStoppedPosition(array $row): bool
    {
        return ($row['status'] ?? null) === 'stopped_out'
            && in_array($row['direction'] ?? null, ['LONG', 'SHORT'], true)
            && is_string($row['sl_candle'] ?? null)
            && $row['sl_candle'] !== '';
    }

    private function stoppedAt(string $value): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value, 'UTC')->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function newerStop(?CarbonImmutable $current, CarbonImmutable $candidate): CarbonImmutable
    {
        return $current === null || $candidate->isAfter($current) ? $candidate : $current;
    }
}
