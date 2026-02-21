<?php

declare(strict_types=1);

namespace App\Nova;

use App\Nova\Fields\HumanDateTime;
use App\Nova\Fields\ID;
use Kraite\Core\Models\ExchangeSymbol as ExchangeSymbolModel;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;

class ExchangeSymbol extends Resource
{
    /**
     * @var class-string<ExchangeSymbolModel>
     */
    public static $model = ExchangeSymbolModel::class;

    /**
     * @var string
     */
    public static $title = 'token';

    /**
     * @var array<int, string>
     */
    public static $search = [
        'id', 'token', 'quote', 'asset',
    ];

    /**
     * @var array<int, string>
     */
    public static $with = ['apiSystem'];

    public function subtitle(): ?string
    {
        return $this->apiSystem?->canonical.' — '.$this->quote;
    }

    /**
     * @return array<int, \Laravel\Nova\Fields\Field|\Laravel\Nova\Panel>
     */
    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            Text::make('Token')
                ->sortable()
                ->rules('required', 'max:255'),

            Text::make('Quote')
                ->sortable()
                ->rules('required', 'max:255'),

            Text::make('Asset')
                ->nullable()
                ->onlyOnDetail(),

            BelongsTo::make('API System', 'apiSystem', ApiSystem::class)
                ->searchable()
                ->sortable(),

            Text::make('Direction')
                ->sortable()
                ->filterable()
                ->nullable(),

            Panel::make('Precision & Limits', [
                Number::make('Price Precision', 'price_precision')
                    ->onlyOnDetail(),

                Number::make('Quantity Precision', 'quantity_precision')
                    ->onlyOnDetail(),

                Text::make('Tick Size', 'tick_size')
                    ->onlyOnDetail(),

                Text::make('Min Notional', 'min_notional')
                    ->onlyOnDetail()
                    ->nullable(),
            ]),

            Panel::make('Flags', [
                Boolean::make('Overlaps With Binance', 'overlaps_with_binance')
                    ->filterable()
                    ->onlyOnDetail(),

                Boolean::make('Marked for Delisting', 'is_marked_for_delisting')
                    ->filterable()
                    ->onlyOnDetail(),
            ]),

            Panel::make('API Statuses', [
                Code::make('API Statuses', 'api_statuses')
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
        return [];
    }

    /**
     * @return array<int, \Laravel\Nova\Lenses\Lens>
     */
    public function lenses(NovaRequest $request): array
    {
        return [];
    }

    /**
     * @return array<int, \Laravel\Nova\Actions\Action>
     */
    public function actions(NovaRequest $request): array
    {
        return [];
    }
}
