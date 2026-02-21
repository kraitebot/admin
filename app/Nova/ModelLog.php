<?php

declare(strict_types=1);

namespace App\Nova;

use App\Nova\Fields\HumanDateTime;
use App\Nova\Fields\ID;
use Kraite\Core\Models\ModelLog as ModelLogModel;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;

class ModelLog extends Resource
{
    /**
     * The model the resource corresponds to.
     *
     * @var class-string<ModelLogModel>
     */
    public static $model = ModelLogModel::class;

    /**
     * The single value that should be used to represent the resource when being displayed.
     *
     * @var string
     */
    public static $title = 'event_type';

    /**
     * The columns that should be searched.
     *
     * @var array<int, string>
     */
    public static $search = [
        'id', 'event_type', 'attribute_name', 'message', 'loggable_type',
    ];

    /**
     * Get the displayable subtitle of the resource.
     */
    public function subtitle(): ?string
    {
        return $this->attribute_name;
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

            Text::make('Event Type', 'event_type')
                ->sortable()
                ->filterable()
                ->rules('required', 'max:255'),

            Text::make('Attribute', 'attribute_name')
                ->sortable()
                ->filterable()
                ->nullable(),

            Textarea::make('Message')
                ->nullable()
                ->alwaysShow(),

            Panel::make('Loggable Model', [
                Text::make('Loggable Type', 'loggable_type')
                    ->sortable()
                    ->filterable()
                    ->readonly(),

                Text::make('Loggable ID', 'loggable_id')
                    ->sortable()
                    ->readonly(),
            ]),

            Panel::make('Related Model', [
                Text::make('Relatable Type', 'relatable_type')
                    ->sortable()
                    ->filterable()
                    ->nullable()
                    ->readonly(),

                Text::make('Relatable ID', 'relatable_id')
                    ->sortable()
                    ->nullable()
                    ->readonly(),
            ]),

            Panel::make('Changed Values', [
                Textarea::make('Previous Value', 'previous_value')
                    ->onlyOnDetail()
                    ->nullable(),

                Textarea::make('New Value', 'new_value')
                    ->onlyOnDetail()
                    ->nullable(),
            ]),

            Panel::make('Metadata', [
                Code::make('Metadata')
                    ->json()
                    ->nullable()
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
