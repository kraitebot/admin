<?php

declare(strict_types=1);

namespace App\Nova;

use App\Nova\Fields\HumanDateTime;
use App\Nova\Fields\ID;
use Kraite\Core\Models\Symbol as SymbolModel;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;

class Symbol extends Resource
{
    /**
     * @var class-string<SymbolModel>
     */
    public static $model = SymbolModel::class;

    /**
     * @var string
     */
    public static $title = 'token';

    /**
     * @var array<int, string>
     */
    public static $search = [
        'id', 'token', 'name',
    ];

    public function subtitle(): ?string
    {
        return $this->name;
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

            Text::make('Name')
                ->sortable()
                ->rules('required', 'max:255'),

            Text::make('Description')
                ->nullable()
                ->onlyOnDetail(),

            Boolean::make('Is Stable Coin', 'is_stable_coin')
                ->sortable()
                ->filterable(),

            Panel::make('CoinMarketCap', [
                Number::make('CMC ID', 'cmc_id')
                    ->nullable()
                    ->onlyOnDetail(),

                Number::make('CMC Ranking', 'cmc_ranking')
                    ->sortable()
                    ->nullable(),

                Text::make('CMC Category', 'cmc_category')
                    ->nullable()
                    ->filterable()
                    ->onlyOnDetail(),
            ]),

            Panel::make('Links', [
                Text::make('Site URL', 'site_url')
                    ->nullable()
                    ->onlyOnDetail(),

                Text::make('Image URL', 'image_url')
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
