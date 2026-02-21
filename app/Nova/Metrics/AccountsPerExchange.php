<?php

declare(strict_types=1);

namespace App\Nova\Metrics;

use Kraite\Core\Models\Account;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Metrics\Partition;
use Laravel\Nova\Metrics\PartitionResult;

class AccountsPerExchange extends Partition
{
    public $name = 'Accounts per Exchange';

    public function calculate(NovaRequest $request): PartitionResult
    {
        return $this->count($request, Account::class, 'api_system_id')
            ->label(function ($value) {
                return match ((int) $value) {
                    1 => 'Binance',
                    2 => 'Bybit',
                    3 => 'KuCoin',
                    4 => 'BitGet',
                    default => "System #{$value}",
                };
            });
    }
}
