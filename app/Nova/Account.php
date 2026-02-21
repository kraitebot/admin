<?php

declare(strict_types=1);

namespace App\Nova;

use App\Nova\Fields\HumanDateTime;
use App\Nova\Fields\ID;
use Kraite\Core\Models\Account as AccountModel;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;

class Account extends Resource
{
    /**
     * The model the resource corresponds to.
     *
     * @var class-string<AccountModel>
     */
    public static $model = AccountModel::class;

    /**
     * The single value that should be used to represent the resource when being displayed.
     *
     * @var string
     */
    public static $title = 'name';

    /**
     * The columns that should be searched.
     *
     * @var array<int, string>
     */
    public static $search = [
        'id', 'name', 'uuid',
    ];

    /**
     * The relationships that should be eager loaded on index queries.
     *
     * @var array<int, string>
     */
    public static $with = ['user', 'apiSystem'];

    /**
     * Get the displayable subtitle of the resource.
     */
    public function subtitle(): ?string
    {
        return $this->user?->name;
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

            Text::make('Exchange', function () {
                $canonical = $this->apiSystem?->canonical;
                if (! $canonical) {
                    return null;
                }
                $url = asset("img/exchanges/{$canonical}.png");

                return "<img src=\"{$url}\" alt=\"{$canonical}\" style=\"width:24px;height:24px;border-radius:4px;\">";
            })->asHtml()->exceptOnForms(),

            Text::make('Name')
                ->sortable()
                ->rules('required', 'max:255'),

            Text::make('UUID')
                ->onlyOnDetail()
                ->readonly(),

            BelongsTo::make('User')
                ->searchable()
                ->sortable(),

            Panel::make('Trading Configuration', [
                Boolean::make('Can Trade', 'can_trade')
                    ->sortable()
                    ->filterable(),

                Boolean::make('Is Active', 'is_active')
                    ->sortable()
                    ->filterable(),

                Text::make('Disabled Reason', 'disabled_reason')
                    ->onlyOnDetail()
                    ->nullable(),

                HumanDateTime::make('Disabled At', 'disabled_at')
                    ->onlyOnDetail(),

                Select::make('Margin Mode', 'margin_mode')
                    ->options([
                        'crossed' => 'Crossed',
                        'isolated' => 'Isolated',
                    ])
                    ->onlyOnDetail()
                    ->filterable()
                    ->rules('required'),

                Text::make('Portfolio Quote', 'portfolio_quote')
                    ->onlyOnDetail()
                    ->nullable(),

                Text::make('Trading Quote', 'trading_quote')
                    ->onlyOnDetail()
                    ->nullable(),

                Number::make('Margin', 'margin')
                    ->step('0.00000001')
                    ->onlyOnDetail()
                    ->nullable()
                    ->help('If filled, overrides the trade configuration default margin percentage'),
            ]),

            Panel::make('Position Limits', [
                Number::make('Max Positions Long', 'total_positions_long')
                    ->onlyOnDetail()
                    ->rules('required', 'integer', 'min:0')
                    ->help('Max active positions LONG'),

                Number::make('Max Positions Short', 'total_positions_short')
                    ->onlyOnDetail()
                    ->rules('required', 'integer', 'min:0')
                    ->help('Max active positions SHORT'),

                Number::make('Leverage Long', 'position_leverage_long')
                    ->onlyOnDetail()
                    ->rules('required', 'integer', 'min:1'),

                Number::make('Leverage Short', 'position_leverage_short')
                    ->onlyOnDetail()
                    ->rules('required', 'integer', 'min:1'),

                Number::make('Max Position %', 'max_position_percentage')
                    ->step('0.01')
                    ->onlyOnDetail()
                    ->rules('required')
                    ->help('Max % of account balance for a single position total margin'),
            ]),

            Panel::make('Order Settings', [
                Number::make('Market Margin % Long', 'market_order_margin_percentage_long')
                    ->step('0.01')
                    ->onlyOnDetail()
                    ->rules('required'),

                Number::make('Market Margin % Short', 'market_order_margin_percentage_short')
                    ->step('0.01')
                    ->onlyOnDetail()
                    ->rules('required'),

                Number::make('Profit %', 'profit_percentage')
                    ->step('0.001')
                    ->onlyOnDetail()
                    ->rules('required'),

                Number::make('Stop Market Initial %', 'stop_market_initial_percentage')
                    ->step('0.01')
                    ->onlyOnDetail()
                    ->rules('required'),

                Number::make('Stop Market Wait (min)', 'stop_market_wait_minutes')
                    ->onlyOnDetail()
                    ->rules('required', 'integer')
                    ->help('Delay in minutes before placing market stop-loss'),

                Number::make('Limit Orders to Notify', 'total_limit_orders_filled_to_notify')
                    ->onlyOnDetail()
                    ->rules('required', 'integer', 'min:0')
                    ->help('After how many limit orders should we notify the user'),

                Number::make('Margin Ratio Threshold', 'margin_ratio_threshold_to_notify')
                    ->step('0.01')
                    ->onlyOnDetail()
                    ->rules('required')
                    ->help('Minimum margin ratio to start notifying'),
            ]),

            HasMany::make('Positions'),

            Panel::make('Timestamps', [
                HumanDateTime::make('Created At')
                    ->sortable()
                    ->onlyOnDetail(),

                HumanDateTime::make('Updated At')
                    ->onlyOnDetail(),

                HumanDateTime::make('Deleted At')
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
