<?php

declare(strict_types=1);

namespace App\Nova\Metrics;

use Kraite\Core\Models\User;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Metrics\Value;
use Laravel\Nova\Metrics\ValueResult;

class TotalUsers extends Value
{
    public $name = 'Total Users';

    public function calculate(NovaRequest $request): ValueResult
    {
        return $this->count($request, User::class);
    }

    public function ranges(): array
    {
        return [
            'ALL' => 'All Time',
        ];
    }
}
