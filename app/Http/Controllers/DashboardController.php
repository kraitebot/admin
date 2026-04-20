<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

class DashboardController extends Controller
{
    private const INGESTION_PATH = '/home/waygou/ingestion.kraite.com';
    public function index()
    {
        $isAdmin = (bool) Auth::user()->is_admin;

        return view($isAdmin ? 'dashboard-admin' : 'dashboard');
    }

    public function data(): JsonResponse
    {
        $exchanges = DB::table('api_systems')
            ->where('is_exchange', true)
            ->select('id', 'name', 'canonical')
            ->get();

        $exchangeIds = $exchanges->pluck('id');

        $symbolStats = DB::table('exchange_symbols')
            ->whereIn('api_system_id', $exchangeIds)
            ->select(
                'api_system_id',
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN direction = 'LONG' THEN 1 ELSE 0 END) as longs"),
                DB::raw("SUM(CASE WHEN direction = 'SHORT' THEN 1 ELSE 0 END) as shorts"),
            )
            ->groupBy('api_system_id')
            ->get()
            ->keyBy('api_system_id');

        // Tradeable counts — mirrors scopeTradeable conditions including Binance cross-check
        $correlationType = config('kraite.token_discovery.correlation_type', 'rolling');
        $correlationColumn = 'btc_correlation_'.$correlationType;

        $tradeableConditions = function ($query, string $table) use ($correlationColumn) {
            $query->where("{$table}.api_statuses->has_taapi_data", true)
                ->where("{$table}.has_no_indicator_data", false)
                ->where("{$table}.is_marked_for_delisting", false)
                ->where("{$table}.has_price_trend_misalignment", false)
                ->where("{$table}.has_early_direction_change", false)
                ->where("{$table}.has_invalid_indicator_direction", false)
                ->whereNotNull("{$table}.symbol_id")
                ->whereNotNull("{$table}.leverage_brackets")
                ->where(fn ($q) => $q->whereNull("{$table}.is_manually_enabled")->orWhere("{$table}.is_manually_enabled", true))
                ->whereNotNull("{$table}.direction")
                ->where(fn ($q) => $q->whereNull("{$table}.tradeable_at")->orWhere("{$table}.tradeable_at", '<=', now()))
                ->whereNotNull("{$table}.indicators_timeframe")
                ->whereRaw("JSON_EXTRACT({$table}.{$correlationColumn}, CONCAT('$.\"', {$table}.indicators_timeframe, '\"')) IS NOT NULL");
        };

        $tradeableCounts = DB::table('exchange_symbols')
            ->whereIn('exchange_symbols.api_system_id', $exchangeIds)
            ->where(fn ($q) => $tradeableConditions($q, 'exchange_symbols'))
            ->whereExists(function ($sub) use ($tradeableConditions, $correlationColumn) {
                $sub->from('exchange_symbols as binance_es')
                    ->join('api_systems', 'api_systems.id', '=', 'binance_es.api_system_id')
                    ->where('api_systems.canonical', 'binance')
                    ->whereColumn('binance_es.token', 'exchange_symbols.token')
                    ->whereColumn('binance_es.quote', 'exchange_symbols.quote');

                $tradeableConditions($sub, 'binance_es');
            })
            ->select(
                'exchange_symbols.api_system_id',
                DB::raw('COUNT(*) as tradeable'),
                DB::raw("SUM(CASE WHEN exchange_symbols.direction = 'LONG' THEN 1 ELSE 0 END) as tradeable_longs"),
                DB::raw("SUM(CASE WHEN exchange_symbols.direction = 'SHORT' THEN 1 ELSE 0 END) as tradeable_shorts"),
            )
            ->groupBy('exchange_symbols.api_system_id')
            ->get()
            ->keyBy('api_system_id');

        $totalSymbols = DB::table('symbols')->count();

        $result = $exchanges->map(function ($exchange) use ($symbolStats, $tradeableCounts) {
            $stats = $symbolStats->get($exchange->id);
            $tradeable = $tradeableCounts->get($exchange->id);

            return [
                'name' => $exchange->name,
                'canonical' => $exchange->canonical,
                'total' => $stats->total ?? 0,
                'longs' => $stats->longs ?? 0,
                'shorts' => $stats->shorts ?? 0,
                'tradeable' => $tradeable->tradeable ?? 0,
                'tradeable_longs' => $tradeable->tradeable_longs ?? 0,
                'tradeable_shorts' => $tradeable->tradeable_shorts ?? 0,
                'non_tradeable' => ($stats->total ?? 0) - ($tradeable->tradeable ?? 0),
            ];
        });

        return response()->json([
            'exchanges' => $result,
            'total_exchanges' => $exchanges->count(),
            'total_symbols' => $totalSymbols,
            'total_exchange_symbols' => $result->sum('total'),
            'total_tradeable' => $result->sum('tradeable'),
            'total_non_tradeable' => $result->sum('non_tradeable'),
            'total_longs' => $result->sum('tradeable_longs'),
            'total_shorts' => $result->sum('tradeable_shorts'),
            'schedule' => $this->getSchedule(),
        ]);
    }

    private function getSchedule(): array
    {
        $result = Process::path(self::INGESTION_PATH)
            ->run(['php', 'artisan', 'schedule:list', '--no-ansi']);

        if (! $result->successful()) {
            return [];
        }

        $lines = explode("\n", trim($result->output()));
        $schedule = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            // Parse: "cron_expression  command  Next Due: time_string"
            if (preg_match('/^(.+?)\s+php artisan\s+(\S+)(?:\s+(.+?))?\s+Next Due:\s+(.+)$/i', $line, $matches)) {
                $cron = trim($matches[1]);
                $command = trim($matches[2]);
                $arguments = isset($matches[3]) ? trim($matches[3]) : '';
                $nextDue = trim($matches[4]);

                // Remove trailing dot from arguments if present
                $arguments = rtrim($arguments, ' .');

                $schedule[] = [
                    'cron' => $cron,
                    'command' => $command,
                    'arguments' => $arguments,
                    'next_due' => $nextDue,
                    'frequency' => $this->cronToHuman($cron),
                ];
            }
        }

        return $schedule;
    }

    private function cronToHuman(string $cron): string
    {
        // Remove any trailing modifiers like "1s"
        $cron = preg_replace('/\s+\d+[smh]$/', '', $cron);
        $parts = preg_split('/\s+/', trim($cron));

        if (count($parts) < 5) {
            return $cron;
        }

        [$min, $hour, $day, $month, $dow] = $parts;

        // Every minute
        if ($min === '*' && $hour === '*' && $day === '*' && $month === '*' && $dow === '*') {
            return 'Every minute';
        }

        // Every N minutes
        if (preg_match('/^\*\/(\d+)$/', $min, $m) && $hour === '*') {
            return "Every {$m[1]} minutes";
        }

        // Hourly at specific minute
        if (is_numeric($min) && $hour === '*' && $day === '*') {
            return "Hourly at :{$min}";
        }

        // Every N hours
        if (is_numeric($min) && preg_match('/^\*\/(\d+)$/', $hour, $m)) {
            return "Every {$m[1]} hours at :{$min}";
        }

        // Daily at specific time
        if (is_numeric($min) && is_numeric($hour) && $day === '*' && $month === '*' && $dow === '*') {
            return sprintf('Daily at %02d:%02d', (int) $hour, (int) $min);
        }

        return $cron;
    }
}
