<?php

declare(strict_types=1);

namespace App\Nova\Metrics;

use Kraite\Core\Models\Position;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Metrics\Value;
use Laravel\Nova\Metrics\ValueResult;

class TotalPositions extends Value
{
    public $name = 'Total Positions';

    public function calculate(NovaRequest $request): ValueResult
    {
        return $this->count($request, Position::class);
    }

    public function ranges(): array
    {
        return [
            'ALL' => 'All Time',
            30 => '30 Days',
            60 => '60 Days',
            'TODAY' => 'Today',
        ];
    }
}
