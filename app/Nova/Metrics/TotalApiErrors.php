<?php

declare(strict_types=1);

namespace App\Nova\Metrics;

use Kraite\Core\Models\ApiRequestLog;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Metrics\Value;
use Laravel\Nova\Metrics\ValueResult;

class TotalApiErrors extends Value
{
    public $name = 'Total API Errors';

    public function calculate(NovaRequest $request): ValueResult
    {
        return $this->count(
            $request,
            ApiRequestLog::query()
                ->whereNotNull('error_message')
                ->join('api_systems', 'api_request_logs.api_system_id', '=', 'api_systems.id')
                ->where('api_systems.is_exchange', true),
        );
    }

    /**
     * @return array<int|string, string>
     */
    public function ranges(): array
    {
        return [
            1 => '24 Hours',
            7 => '7 Days',
            30 => '30 Days',
            60 => '60 Days',
            'TODAY' => 'Today',
            'MTD' => 'Month To Date',
            'YTD' => 'Year To Date',
        ];
    }

    public function uriKey(): string
    {
        return 'total-api-errors';
    }
}
