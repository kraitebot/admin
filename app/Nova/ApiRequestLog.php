<?php

declare(strict_types=1);

namespace App\Nova;

use App\Nova\Fields\HumanDateTime;
use App\Nova\Fields\ID;
use App\Nova\Filters\ApiSystemFilter;
use App\Nova\Filters\HasErrorMessageFilter;
use App\Nova\Filters\HasResponseFilter;
use App\Nova\Filters\HostnameFilter;
use App\Nova\Filters\HttpMethodFilter;
use App\Nova\Filters\HttpResponseCodeFilter;
use App\Nova\Filters\RelatableModelFilter;
use App\Nova\Filters\RelatableTypeFilter;
use Illuminate\Http\Request;
use Kraite\Core\Models\ApiRequestLog as ApiRequestLogModel;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\MorphTo;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;

class ApiRequestLog extends Resource
{
    public static $canCreateResource = false;

    /**
     * Determine if the current user can update the given resource.
     */
    public function authorizedToUpdate(Request $request): bool
    {
        return false;
    }

    /**
     * Determine if the current user can delete the given resource.
     */
    public function authorizedToDelete(Request $request): bool
    {
        return false;
    }

    public function authorizedToReplicate(Request $request): bool
    {
        return false;
    }

    /**
     * The model the resource corresponds to.
     *
     * @var class-string<ApiRequestLogModel>
     */
    public static $model = ApiRequestLogModel::class;

    /**
     * The single value that should be used to represent the resource when being displayed.
     *
     * @var string
     */
    public static $title = 'path';

    /**
     * The columns that should be searched.
     *
     * @var array<int, string>
     */
    public static $search = [
        'id', 'path', 'http_method', 'hostname', 'error_message',
    ];

    /**
     * The relationships that should be eager loaded on index queries.
     *
     * @var array<int, string>
     */
    public static $with = ['account', 'apiSystem'];

    /**
     * Get the displayable subtitle of the resource.
     */
    public function subtitle(): ?string
    {
        return $this->http_method ? "{$this->http_method} ({$this->http_response_code})" : null;
    }

    /**
     * Get the fields displayed by the resource.
     *
     * @return array<int, \Laravel\Nova\Fields\Field|\Laravel\Nova\Panel>
     */
    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            Text::make('HTTP Method', 'http_method')
                ->sortable()
                ->readonly(),

            Text::make('Path')
                ->sortable()
                ->readonly(),

            Number::make('Response Code', 'http_response_code')
                ->sortable()
                ->readonly(),

            Number::make('Duration (ms)', 'duration')
                ->sortable()
                ->readonly()
                ->onlyOnDetail(),

            BelongsTo::make('API System', 'apiSystem', ApiSystem::class)
                ->sortable()
                ->readonly(),

            BelongsTo::make('Account')
                ->searchable()
                ->nullable()
                ->readonly(),

            MorphTo::make('Relatable')
                ->types([
                    ApiSystem::class,
                    ExchangeSymbol::class,
                ])
                ->searchable()
                ->nullable(),

            Text::make('Hostname')
                ->nullable()
                ->onlyOnDetail(),

            Panel::make('Request Details', [
                Code::make('Payload')
                    ->json()
                    ->nullable()
                    ->onlyOnDetail(),

                Code::make('HTTP Headers Sent', 'http_headers_sent')
                    ->json()
                    ->nullable()
                    ->onlyOnDetail(),
            ]),

            Text::make('Response', function () {
                $response = $this->response;
                if (! $response) {
                    return null;
                }

                $str = is_array($response) ? json_encode($response) : (string) $response;

                return mb_strlen($str) > 80 ? mb_substr($str, 0, 80).'…' : $str;
            })->onlyOnIndex(),

            Text::make('Error Message', function () {
                $msg = $this->error_message;
                if (! $msg) {
                    return null;
                }

                $str = is_array($msg) ? json_encode($msg) : (string) $msg;

                return mb_strlen($str) > 80 ? mb_substr($str, 0, 80).'…' : $str;
            })->onlyOnIndex(),

            Panel::make('Response Details', [
                Code::make('Response')
                    ->json()
                    ->nullable()
                    ->onlyOnDetail(),

                Code::make('HTTP Headers Returned', 'http_headers_returned')
                    ->json()
                    ->nullable()
                    ->onlyOnDetail(),

                Textarea::make('Error Message', 'error_message')
                    ->nullable()
                    ->onlyOnDetail(),

                Code::make('Debug Data', 'debug_data')
                    ->json()
                    ->nullable()
                    ->onlyOnDetail(),
            ]),

            Panel::make('Timing', [
                HumanDateTime::make('Started At', 'started_at')
                    ->sortable()
                    ->onlyOnDetail(),

                HumanDateTime::make('Completed At', 'completed_at')
                    ->sortable()
                    ->onlyOnDetail(),
            ]),

            Panel::make('Timestamps', [
                HumanDateTime::make('Created At')
                    ->sortable()
                    ->onlyOnDetail(),

                HumanDateTime::make('Updated At')
                    ->onlyOnDetail(),
            ]),
        ];
    }

    /**
     * Get the cards available for the request.
     *
     * @return array<int, \Laravel\Nova\Card>
     */
    public function cards(NovaRequest $request): array
    {
        return [
            (new Metrics\ApiRequestErrorsByExchange),
        ];
    }

    /**
     * Get the filters available for the resource.
     *
     * @return array<int, \Laravel\Nova\Filters\Filter>
     */
    public function filters(NovaRequest $request): array
    {
        return [
            new ApiSystemFilter,
            new HttpMethodFilter,
            new HttpResponseCodeFilter,
            new RelatableTypeFilter('api_request_logs'),
            new RelatableModelFilter('api_request_logs'),
            new HostnameFilter,
            new HasResponseFilter,
            new HasErrorMessageFilter,
        ];
    }

    /**
     * Get the lenses available for the resource.
     *
     * @return array<int, \Laravel\Nova\Lenses\Lens>
     */
    public function lenses(NovaRequest $request): array
    {
        return [
            new Lenses\ApiRequestLogWithErrors,
        ];
    }

    /**
     * Get the actions available for the resource.
     *
     * @return array<int, \Laravel\Nova\Actions\Action>
     */
    public function actions(NovaRequest $request): array
    {
        return [];
    }
}
