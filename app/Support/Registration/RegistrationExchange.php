<?php

declare(strict_types=1);

namespace App\Support\Registration;

final class RegistrationExchange
{
    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return ['binance', 'bybit', 'kucoin', 'bitget'];
    }

    /**
     * @return array<int, string>
     */
    public static function enabled(): array
    {
        return ['binance', 'bitget'];
    }

    public static function isEnabled(string $canonical): bool
    {
        return in_array($canonical, self::enabled(), true);
    }
}
