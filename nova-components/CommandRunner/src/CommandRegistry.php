<?php

declare(strict_types=1);

namespace Kraite\CommandRunner;

class CommandRegistry
{
    /**
     * @return array<string, array{description: string, options: array<string, array{type: string, description: string, choices?: list<string>}>}>
     */
    public static function list(): array
    {
        return [
            'cronjobs:refresh-exchange-symbols' => [
                'description' => 'Refresh exchange symbols from exchange APIs and discover CMC tokens.',
                'options' => [
                    '--clean' => [
                        'type' => 'boolean',
                        'description' => 'Truncate all operational tables and start fresh.',
                    ],
                    '--exchange' => [
                        'type' => 'select',
                        'description' => 'Only refresh a specific exchange.',
                        'choices' => ['binance', 'bybit', 'kucoin', 'bitget'],
                    ],
                ],
            ],
            'cronjobs:fetch-klines' => [
                'description' => 'Create FetchKlinesJob steps for exchange symbols.',
                'options' => [
                    '--clean' => [
                        'type' => 'boolean',
                        'description' => 'Truncate candles, steps, and related operational tables.',
                    ],
                    '--only-active-positions' => [
                        'type' => 'boolean',
                        'description' => 'Fetch klines only for symbols with active positions.',
                    ],
                    '--output' => [
                        'type' => 'boolean',
                        'description' => 'Output verbose information.',
                    ],
                    '--canonical' => [
                        'type' => 'select',
                        'description' => 'Filter by API system canonical.',
                        'choices' => ['binance', 'bybit', 'kucoin', 'bitget'],
                    ],
                    '--timeframe' => [
                        'type' => 'text',
                        'description' => 'Candle timeframe (if not provided, uses timeframes from ApiSystem).',
                    ],
                    '--limit' => [
                        'type' => 'text',
                        'description' => 'Number of candles to fetch (default: 5).',
                    ],
                    '--exchange_symbol_id' => [
                        'type' => 'text',
                        'description' => 'Fetch klines for a specific exchange symbol ID.',
                    ],
                ],
            ],
            'cronjobs:conclude-symbols-direction' => [
                'description' => 'Triggers atomic workflow to conclude trading direction for all exchange symbols.',
                'options' => [
                    '--clean' => [
                        'type' => 'boolean',
                        'description' => 'Truncate steps, api_request_logs, application_logs, and indicator_histories tables.',
                    ],
                    '--preserve' => [
                        'type' => 'boolean',
                        'description' => 'Do not delete indicator histories (for debugging).',
                    ],
                    '--reset' => [
                        'type' => 'boolean',
                        'description' => 'Reset all exchange symbols to default state (direction=NULL, clear all flags).',
                    ],
                    '--output' => [
                        'type' => 'boolean',
                        'description' => 'Display command output (silent by default).',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array{description: string, options: array<string, array{type: string, description: string, choices?: list<string>}>}|null
     */
    public static function find(string $command): ?array
    {
        return self::list()[$command] ?? null;
    }

    public static function isAllowed(string $command): bool
    {
        return array_key_exists($command, self::list());
    }
}
