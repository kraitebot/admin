<?php

declare(strict_types=1);

namespace App\Nova\Filters;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

class HasResponseFilter extends Filter
{
    /**
     * @var string
     */
    public $component = 'select-filter';

    public $name = 'Has Response';

    public function apply(NovaRequest $request, Builder $query, mixed $value): Builder
    {
        return $value === 'yes'
            ? $query->whereNotNull('response')
            : $query->whereNull('response');
    }

    /**
     * @return array<string, string>
     */
    public function options(NovaRequest $request): array
    {
        return [
            'Yes' => 'yes',
            'No' => 'no',
        ];
    }
}
