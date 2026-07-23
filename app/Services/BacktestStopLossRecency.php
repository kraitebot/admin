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
        $latest = [
            'LONG' => null,
            'SHORT' => null,
        ];

        foreach ($rows as $row) {
            $direction = $row['direction'] ?? null;
            $stoppedAt = $row['sl_candle'] ?? null;

            if (($row['status'] ?? null) !== 'stopped_out'
                || ! array_key_exists($direction, $latest)
                || ! is_string($stoppedAt)
                || $stoppedAt === '') {
                continue;
            }

            try {
                $candidate = CarbonImmutable::parse($stoppedAt, 'UTC')->utc();
            } catch (Throwable) {
                continue;
            }

            if ($latest[$direction] === null || $candidate->isAfter($latest[$direction])) {
                $latest[$direction] = $candidate;
            }
        }

        return [
            'LONG' => $latest['LONG']?->toIso8601String(),
            'SHORT' => $latest['SHORT']?->toIso8601String(),
        ];
    }
}
