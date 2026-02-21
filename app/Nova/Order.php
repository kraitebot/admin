<?php

declare(strict_types=1);

namespace App\Nova;

use App\Nova\Fields\HumanDateTime;
use App\Nova\Fields\ID;
use Kraite\Core\Models\Order as OrderModel;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;

class Order extends Resource
{
    /**
     * The model the resource corresponds to.
     *
     * @var class-string<OrderModel>
     */
    public static $model = OrderModel::class;

    /**
     * The single value that should be used to represent the resource when being displayed.
     *
     * @var string
     */
    public static $title = 'client_order_id';

    /**
     * The columns that should be searched.
     *
     * @var array<int, string>
     */
    public static $search = [
        'id', 'uuid', 'client_order_id', 'exchange_order_id',
    ];

    /**
     * The relationships that should be eager loaded on index queries.
     *
     * @var array<int, string>
     */
    public static $with = ['position'];

    /**
     * Get the fields displayed by the resource.
     *
     * @return array<int, \Laravel\Nova\Fields\Field|\Laravel\Nova\Panel>
     */
    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            Text::make('Client Order ID', 'client_order_id')
                ->onlyOnDetail()
                ->readonly(),

            Text::make('UUID')
                ->onlyOnDetail()
                ->readonly(),

            BelongsTo::make('Position')
                ->searchable()
                ->sortable(),

            Badge::make('Type', 'type')
                ->map([
                    'MARKET' => 'info',
                    'LIMIT' => 'warning',
                    'PROFIT' => 'success',
                    'CANCEL-MARKET' => 'danger',
                ])
                ->sortable()
                ->filterable(),

            Badge::make('Status')
                ->map([
                    'NEW' => 'info',
                    'FILLED' => 'success',
                    'CANCELED' => 'danger',
                    'PARTIALLY_FILLED' => 'warning',
                    'EXPIRED' => 'danger',
                ])
                ->sortable()
                ->filterable(),

            Text::make('Side')
                ->sortable()
                ->filterable()
                ->readonly(),

            Panel::make('Order Details', [
                Text::make('Position Side', 'position_side')
                    ->readonly()
                    ->onlyOnDetail(),

                Text::make('Exchange Order ID', 'exchange_order_id')
                    ->readonly()
                    ->onlyOnDetail(),

                Boolean::make('Is Algo', 'is_algo')
                    ->filterable()
                    ->onlyOnDetail(),

                Text::make('Reference Status', 'reference_status')
                    ->readonly()
                    ->onlyOnDetail(),
            ]),

            Panel::make('Pricing & Quantity', [
                Number::make('Price', 'price')
                    ->step('0.00000001')
                    ->readonly()
                    ->onlyOnDetail(),

                Number::make('Quantity', 'quantity')
                    ->step('0.00000001')
                    ->readonly()
                    ->onlyOnDetail(),

                Number::make('Reference Price', 'reference_price')
                    ->step('0.00000001')
                    ->readonly()
                    ->onlyOnDetail(),

                Number::make('Reference Quantity', 'reference_quantity')
                    ->step('0.00000001')
                    ->readonly()
                    ->onlyOnDetail(),
            ]),

            Panel::make('Timestamps', [
                HumanDateTime::make('Opened At', 'opened_at')
                    ->sortable()
                    ->onlyOnDetail(),

                HumanDateTime::make('Filled At', 'filled_at')
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
