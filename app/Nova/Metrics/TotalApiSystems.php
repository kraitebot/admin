<?php

declare(strict_types=1);

namespace App\Nova\Metrics;

use Kraite\Core\Models\ApiSystem;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Metrics\Value;
use Laravel\Nova\Metrics\ValueResult;

class TotalApiSystems extends Value
{
    public $name = 'API Systems';

    public function calculate(NovaRequest $request): ValueResult
    {
        return $this->count($request, ApiSystem::class);
    }

    public function ranges(): array
    {
        return [
            'ALL' => 'All Time',
        ];
    }
}
