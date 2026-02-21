<?php

declare(strict_types=1);

namespace App\Nova\Filters;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Kraite\Core\Models\ApiRequestLog;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

class HttpMethodFilter extends Filter
{
    /**
     * @var string
     */
    public $component = 'select-filter';

    public $name = 'HTTP Method';

    public function apply(NovaRequest $request, Builder $query, mixed $value): Builder
    {
        return $query->where('http_method', $value);
    }

    /**
     * @return array<string, string>
     */
    public function options(NovaRequest $request): array
    {
        return ApiRequestLog::query()
            ->select('http_method')
            ->distinct()
            ->orderBy('http_method')
            ->pluck('http_method')
            ->mapWithKeys(fn ($method) => [$method => $method])
            ->all();
    }
}
