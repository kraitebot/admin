<?php

declare(strict_types=1);

namespace App\Nova;

use App\Nova\Fields\HumanDateTime;
use App\Nova\Fields\ID;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;
use StepDispatcher\Models\Step as StepModel;

class Step extends Resource
{
    /**
     * The model the resource corresponds to.
     *
     * @var class-string<StepModel>
     */
    public static $model = StepModel::class;

    /**
     * The single value that should be used to represent the resource when being displayed.
     *
     * @var string
     */
    public static $title = 'class';

    /**
     * The columns that should be searched.
     *
     * @var array<int, string>
     */
    public static $search = [
        'id', 'block_uuid', 'class', 'label', 'canonical', 'workflow_id', 'hostname',
    ];

    /**
     * Get the displayable subtitle of the resource.
     */
    public function subtitle(): ?string
    {
        return $this->label;
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

            Text::make('Class')
                ->sortable()
                ->readonly(),

            Text::make('Label')
                ->sortable()
                ->nullable(),

            Text::make('State', function () {
                return $this->state ? class_basename($this->state) : null;
            })->sortable()->filterable(),

            Text::make('Type')
                ->sortable()
                ->filterable(),

            Panel::make('Block Identifiers', [
                Text::make('Block UUID', 'block_uuid')
                    ->sortable()
                    ->readonly(),

                Text::make('Child Block UUID', 'child_block_uuid')
                    ->nullable()
                    ->readonly(),

                Text::make('Workflow ID', 'workflow_id')
                    ->nullable()
                    ->readonly(),

                Text::make('Canonical')
                    ->nullable()
                    ->readonly(),

                Number::make('Index')
                    ->sortable()
                    ->readonly(),

                Text::make('Group')
                    ->nullable()
                    ->filterable()
                    ->readonly(),
            ]),

            Panel::make('Execution', [
                Text::make('Execution Mode', 'execution_mode')
                    ->nullable()
                    ->filterable()
                    ->readonly(),

                Text::make('Queue')
                    ->sortable()
                    ->filterable()
                    ->readonly(),

                Text::make('Priority')
                    ->sortable()
                    ->filterable()
                    ->readonly(),

                Number::make('Retries')
                    ->sortable()
                    ->readonly(),

                Boolean::make('Double Check', 'double_check')
                    ->readonly(),

                Boolean::make('Was Throttled', 'was_throttled')
                    ->filterable()
                    ->readonly(),

                Boolean::make('Is Throttled', 'is_throttled')
                    ->filterable()
                    ->readonly(),

                Boolean::make('Was Notified', 'was_notified')
                    ->filterable()
                    ->readonly(),

                Text::make('Hostname')
                    ->nullable()
                    ->filterable()
                    ->onlyOnDetail(),

                Number::make('Duration (ms)', 'duration')
                    ->sortable()
                    ->readonly(),

                Number::make('Tick ID', 'tick_id')
                    ->nullable()
                    ->readonly(),
            ]),

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
            ]),

            Panel::make('Response & Errors', [
                Code::make('Arguments')
                    ->json()
                    ->nullable()
                    ->onlyOnDetail(),

                Code::make('Response')
                    ->json()
                    ->nullable()
                    ->onlyOnDetail(),

                Textarea::make('Error Message', 'error_message')
                    ->nullable()
                    ->onlyOnDetail(),

                Textarea::make('Error Stack Trace', 'error_stack_trace')
                    ->nullable()
                    ->onlyOnDetail(),

                Textarea::make('Step Log', 'step_log')
                    ->nullable()
                    ->onlyOnDetail(),
            ]),

            Panel::make('Timing', [
                HumanDateTime::make('Dispatch After', 'dispatch_after')
                    ->sortable()
                    ->onlyOnDetail(),

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
