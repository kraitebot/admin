<?php

declare(strict_types=1);

namespace App\Nova\Lenses;

use App\Nova\Fields\HumanDateTime;
use App\Nova\Fields\ID;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\Paginator;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\LensRequest;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Lenses\Lens;

class ApiRequestLogWithErrors extends Lens
{
    public $name = 'Requests with errors';

    /**
     * @var array<int, string>
     */
    public static $search = [
        'id', 'path', 'error_message',
    ];

    public static function query(LensRequest $request, Builder $query): Builder|Paginator
    {
        return $request->withOrdering(
            $request->withFilters(
                $query->whereNotNull('error_message')->with('account')
            ),
            fn ($query) => $query->latest()
        );
    }

    /**
     * @return array<int, \Laravel\Nova\Fields\Field>
     */
    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            Text::make('HTTP Method', 'http_method')->sortable(),

            Text::make('Path')->sortable(),

            Number::make('Response Code', 'http_response_code')->sortable(),

            Number::make('Duration (ms)', 'duration')->sortable(),

            BelongsTo::make('Account')->nullable(),

            Text::make('Error Message', function () {
                $msg = $this->error_message;
                if (! $msg) {
                    return null;
                }

                $str = is_array($msg) ? json_encode($msg) : (string) $msg;

                return mb_strlen($str) > 100 ? mb_substr($str, 0, 100).'…' : $str;
            }),

            HumanDateTime::make('Created At')->sortable(),
        ];
    }

    /**
     * @return array<int, \Laravel\Nova\Card>
     */
    public function cards(NovaRequest $request): array
    {
        return [];
    }

    /**
     * @return array<int, \Laravel\Nova\Filters\Filter>
     */
    public function filters(NovaRequest $request): array
    {
        return [
            new \App\Nova\Filters\HttpMethodFilter,
            new \App\Nova\Filters\HttpResponseCodeFilter,
            new \App\Nova\Filters\RelatableTypeFilter('api_request_logs'),
            new \App\Nova\Filters\RelatableModelFilter('api_request_logs'),
            new \App\Nova\Filters\HostnameFilter,
        ];
    }

    /**
     * @return array<int, \Laravel\Nova\Actions\Action>
     */
    public function actions(NovaRequest $request): array
    {
        return parent::actions($request);
    }

    public function uriKey(): string
    {
        return 'api-request-log-with-errors';
    }
}
