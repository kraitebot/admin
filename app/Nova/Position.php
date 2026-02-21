<?php

declare(strict_types=1);

namespace App\Nova;

use App\Nova\Fields\HumanDateTime;
use App\Nova\Fields\ID;
use Kraite\Core\Models\Position as PositionModel;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;

class Position extends Resource
{
    /**
     * The model the resource corresponds to.
     *
     * @var class-string<PositionModel>
     */
    public static $model = PositionModel::class;

    /**
     * The single value that should be used to represent the resource when being displayed.
     *
     * @var string
     */
    public static $title = 'parsed_trading_pair';

    /**
     * The columns that should be searched.
     *
     * @var array<int, string>
     */
    public static $search = [
        'id', 'uuid', 'parsed_trading_pair',
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
        return $this->account?->name;
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

            Text::make('Trading Pair', 'parsed_trading_pair')
                ->sortable()
                ->readonly(),

            Text::make('UUID')
                ->onlyOnDetail()
                ->readonly(),

            BelongsTo::make('Account')
                ->searchable()
                ->sortable(),

            Badge::make('Status')
                ->map([
                    'new' => 'info',
                    'active' => 'success',
                    'closed' => 'warning',
                    'cancelled' => 'danger',
                ])
                ->sortable()
                ->filterable(),

            Badge::make('Direction')
                ->map([
                    'LONG' => 'success',
                    'SHORT' => 'danger',
                ])
                ->filterable(),

            Panel::make('Pricing', [
                Number::make('Opening Price', 'opening_price')
                    ->step('0.00000001')
                    ->readonly()
                    ->onlyOnDetail(),

                Number::make('Closing Price', 'closing_price')
                    ->step('0.00000001')
                    ->readonly()
                    ->onlyOnDetail(),

                Number::make('First Profit Price', 'first_profit_price')
                    ->step('0.00000001')
                    ->readonly()
                    ->onlyOnDetail(),

                Number::make('Margin', 'margin')
                    ->step('0.00000001')
                    ->readonly()
                    ->onlyOnDetail(),

                Number::make('Quantity', 'quantity')
                    ->step('0.00000001')
                    ->readonly()
                    ->onlyOnDetail(),

                Number::make('Leverage', 'leverage')
                    ->readonly()
                    ->onlyOnDetail(),

                Number::make('Profit %', 'profit_percentage')
                    ->step('0.001')
                    ->readonly()
                    ->onlyOnDetail(),
            ]),

            Panel::make('Order Info', [
                Number::make('Total Limit Orders', 'total_limit_orders')
                    ->readonly()
                    ->onlyOnDetail(),

                Boolean::make('Was Fast Traded', 'was_fast_traded')
                    ->onlyOnDetail(),

                Boolean::make('Was WAPed', 'was_waped')
                    ->onlyOnDetail(),

                HumanDateTime::make('WAPed At', 'waped_at')
                    ->onlyOnDetail(),
            ]),

            Panel::make('Indicators', [
                Code::make('Indicator Values', 'indicators_values')
                    ->json()
                    ->onlyOnDetail(),

                Text::make('Indicator Timeframe', 'indicators_timeframe')
                    ->onlyOnDetail()
                    ->readonly(),
            ]),

            Panel::make('Error', [
                Textarea::make('Error Message', 'error_message')
                    ->onlyOnDetail()
                    ->readonly(),
            ]),

            HasMany::make('Orders'),

            Panel::make('Timestamps', [
                HumanDateTime::make('Opened At', 'opened_at')
                    ->sortable()
                    ->onlyOnDetail(),

                HumanDateTime::make('Closed At', 'closed_at')
                    ->sortable()
                    ->onlyOnDetail(),

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
