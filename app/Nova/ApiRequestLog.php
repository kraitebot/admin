<?php

declare(strict_types=1);

namespace App\Nova;

use App\Nova\Fields\HumanDateTime;
use App\Nova\Fields\ID;
use Kraite\Core\Models\ApiRequestLog as ApiRequestLogModel;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;

class ApiRequestLog extends Resource
{
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
    public static $with = ['account'];

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
                ->filterable()
                ->readonly(),

            Text::make('Path')
                ->sortable()
                ->readonly(),

            Number::make('Response Code', 'http_response_code')
                ->sortable()
                ->filterable()
                ->readonly(),

            Number::make('Duration (ms)', 'duration')
                ->sortable()
                ->readonly(),

            BelongsTo::make('Account')
                ->searchable()
                ->nullable()
                ->readonly(),

            Panel::make('Polymorphic Owner', [
                Text::make('Relatable Type', 'relatable_type')
                    ->sortable()
                    ->filterable()
                    ->nullable()
                    ->readonly(),

                Text::make('Relatable ID', 'relatable_id')
                    ->sortable()
                    ->nullable()
                    ->readonly(),

                Text::make('Hostname')
                    ->nullable()
                    ->filterable()
                    ->onlyOnDetail(),
            ]),

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
        return [];
    }

    /**
     * Get the filters available for the resource.
     *
     * @return array<int, \Laravel\Nova\Filters\Filter>
     */
    public function filters(NovaRequest $request): array
    {
        return [];
    }

    /**
     * Get the lenses available for the resource.
     *
     * @return array<int, \Laravel\Nova\Lenses\Lens>
     */
    public function lenses(NovaRequest $request): array
    {
        return [];
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
