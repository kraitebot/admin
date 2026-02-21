<?php

declare(strict_types=1);

namespace App\Nova\Metrics;

use Kraite\Core\Models\Position;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Metrics\Partition;
use Laravel\Nova\Metrics\PartitionResult;

class PositionsByStatus extends Partition
{
    public $name = 'Positions by Status';

    public function calculate(NovaRequest $request): PartitionResult
    {
        return $this->count($request, Position::class, 'status')
            ->colors([
                'new' => '#3b82f6',
                'active' => '#22c55e',
                'closed' => '#f59e0b',
                'cancelled' => '#ef4444',
            ]);
    }
}
