<?php

declare(strict_types=1);

namespace App\Nova\Filters;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Kraite\Core\Models\ApiSystem;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

class ApiSystemFilter extends Filter
{
    /**
     * @var string
     */
    public $component = 'select-filter';

    public $name = 'API System';

    public function apply(NovaRequest $request, Builder $query, mixed $value): Builder
    {
        return $query->where('api_system_id', $value);
    }

    /**
     * @return array<string, string>
     */
    public function options(NovaRequest $request): array
    {
        return ApiSystem::query()
            ->whereIn('id', function ($sub) {
                $sub->select('api_system_id')
                    ->from('api_request_logs')
                    ->whereNotNull('api_system_id')
                    ->distinct();
            })
            ->orderBy('name')
            ->pluck('id', 'name')
            ->all();
    }
}
