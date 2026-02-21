<?php

declare(strict_types=1);

namespace App\Nova\Metrics;

use Kraite\Core\Models\Account;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Metrics\Value;
use Laravel\Nova\Metrics\ValueResult;

class TotalAccounts extends Value
{
    public $name = 'Total Accounts';

    public function calculate(NovaRequest $request): ValueResult
    {
        return $this->count($request, Account::class);
    }

    public function ranges(): array
    {
        return [
            'ALL' => 'All Time',
        ];
    }
}
