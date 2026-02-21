<?php

declare(strict_types=1);

namespace App\Nova\Dashboards;

use App\Nova\Metrics\AccountsPerExchange;
use App\Nova\Metrics\PositionsByStatus;
use App\Nova\Metrics\TotalAccounts;
use App\Nova\Metrics\TotalApiSystems;
use App\Nova\Metrics\TotalOrders;
use App\Nova\Metrics\TotalPositions;
use App\Nova\Metrics\TotalUsers;
use Laravel\Nova\Dashboards\Main as Dashboard;

class Main extends Dashboard
{
    /**
     * Get the cards for the dashboard.
     *
     * @return array<int, \Laravel\Nova\Card>
     */
    public function cards(): array
    {
        return [
            TotalUsers::make()->width('1/3'),
            TotalAccounts::make()->width('1/3'),
            TotalApiSystems::make()->width('1/3'),

            TotalPositions::make()->width('1/2'),
            TotalOrders::make()->width('1/2'),

            AccountsPerExchange::make()->width('1/2'),
            PositionsByStatus::make()->width('1/2'),
        ];
    }
}
