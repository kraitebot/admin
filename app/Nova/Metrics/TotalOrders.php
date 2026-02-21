<?php

declare(strict_types=1);

namespace App\Nova\Metrics;

use Kraite\Core\Models\Order;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Metrics\Value;
use Laravel\Nova\Metrics\ValueResult;

class TotalOrders extends Value
{
    public $name = 'Total Orders';

    public function calculate(NovaRequest $request): ValueResult
    {
        return $this->count($request, Order::class);
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
