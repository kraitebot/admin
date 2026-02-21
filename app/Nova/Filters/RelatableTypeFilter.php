<?php

declare(strict_types=1);

namespace App\Nova\Filters;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

class RelatableTypeFilter extends Filter
{
    /**
     * @var string
     */
    public $component = 'select-filter';

    public $name = 'Relatable Type';

    protected string $table;

    public function __construct(string $table = 'api_request_logs')
    {
        $this->table = $table;
    }

    public function apply(NovaRequest $request, Builder $query, mixed $value): Builder
    {
        return $query->where('relatable_type', $value);
    }

    /**
     * @return array<string, string>
     */
    public function options(NovaRequest $request): array
    {
        return DB::table($this->table)
            ->select('relatable_type')
            ->whereNotNull('relatable_type')
            ->distinct()
            ->orderBy('relatable_type')
            ->pluck('relatable_type')
            ->mapWithKeys(fn ($type) => [class_basename($type) => $type])
            ->all();
    }
}
