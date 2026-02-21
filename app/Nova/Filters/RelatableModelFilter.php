<?php

declare(strict_types=1);

namespace App\Nova\Filters;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

class RelatableModelFilter extends Filter
{
    /**
     * @var string
     */
    public $component = 'dependent-select-filter';

    public $name = 'Relatable Model';

    protected string $table;

    public function __construct(string $table = 'api_request_logs')
    {
        $this->table = $table;
    }

    public function apply(NovaRequest $request, Builder $query, mixed $value): Builder
    {
        return $query->where('relatable_id', $value);
    }

    /**
     * @return array<string, string>
     */
    public function options(NovaRequest $request): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_merge(parent::jsonSerialize(), [
            'dependsOnKey' => RelatableTypeFilter::class,
            'table' => $this->table,
        ]);
    }
}
