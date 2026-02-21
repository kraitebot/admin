<?php

declare(strict_types=1);

namespace App\Nova\Filters;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Kraite\Core\Models\ApiRequestLog;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

class HostnameFilter extends Filter
{
    /**
     * @var string
     */
    public $component = 'select-filter';

    public $name = 'Hostname';

    public function apply(NovaRequest $request, Builder $query, mixed $value): Builder
    {
        return $query->where('hostname', $value);
    }

    /**
     * @return array<string, string>
     */
    public function options(NovaRequest $request): array
    {
        return ApiRequestLog::query()
            ->select('hostname')
            ->whereNotNull('hostname')
            ->distinct()
            ->orderBy('hostname')
            ->pluck('hostname')
            ->mapWithKeys(fn ($hostname) => [$hostname => $hostname])
            ->all();
    }
}
