<?php

declare(strict_types=1);

namespace App\Support\Registration;

use Illuminate\Support\Facades\Log;
use Kraite\Core\Abstracts\BaseExceptionHandler;
use Kraite\Core\Models\Account;
use Throwable;

class RegistrationConnectivityTester
{
    /**
     * Test the entered credentials with a signed, read-only open-orders call.
     */
    public function test(string $exchange, string $apiKey, string $apiSecret, ?string $passphrase = null): RegistrationConnectivityResult
    {
        $account = Account::temporary($exchange, $this->credentialColumns($exchange, $apiKey, $apiSecret, $passphrase));
        $account->name = 'Registration connectivity test';
        $account->portfolio_quote = 'USDT';
        $account->trading_quote = 'USDT';

        try {
            $response = $account->apiQueryOpenOrders();

            return new RegistrationConnectivityResult(
                connected: true,
                message: 'Connectivity verified, all good!',
                ordersCount: count($response->result ?? []),
            );
        } catch (Throwable $exception) {
            Log::warning('[Registration] exchange connectivity test failed', [
                'exchange' => $exchange,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return new RegistrationConnectivityResult(
                connected: false,
                message: $this->failureMessage($exchange, $exception),
            );
        }
    }

    /**
     * @return array<string, string|null>
     */
    private function credentialColumns(string $exchange, string $apiKey, string $apiSecret, ?string $passphrase): array
    {
        return match ($exchange) {
            'binance' => [
                'binance_api_key' => $apiKey,
                'binance_api_secret' => $apiSecret,
            ],
            'bybit' => [
                'bybit_api_key' => $apiKey,
                'bybit_api_secret' => $apiSecret,
            ],
            'kucoin' => [
                'kucoin_api_key' => $apiKey,
                'kucoin_api_secret' => $apiSecret,
                'kucoin_passphrase' => $passphrase,
            ],
            'bitget' => [
                'bitget_api_key' => $apiKey,
                'bitget_api_secret' => $apiSecret,
                'bitget_passphrase' => $passphrase,
            ],
            default => [],
        };
    }

    private function failureMessage(string $exchange, Throwable $exception): string
    {
        $handler = BaseExceptionHandler::make($exchange);

        if ($handler->isForbidden($exception)) {
            return 'Credentials rejected or this server IP is not whitelisted.';
        }

        if ($handler->isRateLimited($exception)) {
            return 'The exchange rate limited the test. Try again in a moment.';
        }

        return 'Connectivity failed. Check the credentials and API permissions.';
    }
}
