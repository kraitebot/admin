<?php

declare(strict_types=1);

namespace App\Nova\Metrics;

use Illuminate\Support\Facades\DB;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Metrics\Partition;
use Laravel\Nova\Metrics\PartitionResult;

class ApiRequestErrorsByExchange extends Partition
{
    public $name = 'API Errors by Exchange';

    public function calculate(NovaRequest $request): PartitionResult
    {
        $results = DB::table('api_request_logs')
            ->join('api_systems', 'api_request_logs.api_system_id', '=', 'api_systems.id')
            ->where('api_systems.is_exchange', true)
            ->where('api_request_logs.created_at', '>=', now()->subHour())
            ->whereNotNull('api_request_logs.error_message')
            ->select('api_systems.name', DB::raw('COUNT(*) as total'))
            ->groupBy('api_systems.name')
            ->pluck('total', 'name')
            ->all();

        return $this->result($results);
    }

    public function uriKey(): string
    {
        return 'api-request-errors-by-exchange';
    }
}
