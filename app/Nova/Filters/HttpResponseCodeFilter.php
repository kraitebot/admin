<?php

declare(strict_types=1);

namespace App\Nova\Filters;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Kraite\Core\Models\ApiRequestLog;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

class HttpResponseCodeFilter extends Filter
{
    /**
     * @var string
     */
    public $component = 'select-filter';

    public $name = 'HTTP Response Code';

    public function apply(NovaRequest $request, Builder $query, mixed $value): Builder
    {
        return $query->where('http_response_code', $value);
    }

    /**
     * @return array<string, string>
     */
    public function options(NovaRequest $request): array
    {
        return ApiRequestLog::query()
            ->select('http_response_code')
            ->distinct()
            ->orderBy('http_response_code')
            ->pluck('http_response_code')
            ->mapWithKeys(fn ($code) => [(string) $code => (string) $code])
            ->all();
    }
}
