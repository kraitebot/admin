<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies the HMAC signature on a NOWPayments IPN webhook.
 *
 * NOWPayments computes the signature as HMAC-SHA512 of the
 * recursively-key-sorted JSON body, using the merchant's IPN secret.
 * The hex digest is sent in the `x-nowpayments-sig` request header.
 *
 * Without a valid signature this is treated as an unauthenticated
 * request and aborts with 401 — never reaching the controller.
 */
final class VerifyNowPaymentsSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.nowpayments.ipn_secret', '');

        if ($secret === '') {
            abort(503, 'NOWPayments IPN secret is not configured.');
        }

        $signature = (string) $request->header('x-nowpayments-sig', '');

        if ($signature === '') {
            abort(401, 'Missing NOWPayments signature.');
        }

        $body = $request->getContent();
        $data = json_decode($body, associative: true);

        if (! is_array($data)) {
            abort(400, 'Invalid NOWPayments payload.');
        }

        $this->ksortRecursive($data);
        $sortedJson = json_encode($data, JSON_UNESCAPED_SLASHES);

        if ($sortedJson === false) {
            abort(400, 'Could not re-serialise NOWPayments payload.');
        }

        $expected = hash_hmac('sha512', $sortedJson, $secret);

        if (! hash_equals(strtolower($expected), strtolower($signature))) {
            Log::warning('[NOWPayments] signature mismatch', [
                'received_sig_prefix' => substr($signature, 0, 16),
                'expected_sig_prefix' => substr($expected, 0, 16),
                'secret_prefix' => substr($secret, 0, 6),
                'body_first_500' => substr($body, 0, 500),
                'sorted_first_500' => substr($sortedJson, 0, 500),
                'order_id' => $data['order_id'] ?? null,
                'payment_id' => $data['payment_id'] ?? null,
                'payment_status' => $data['payment_status'] ?? null,
            ]);

            abort(401, 'Invalid NOWPayments signature.');
        }

        return $next($request);
    }

    /**
     * @param  array<mixed>  $data
     */
    private function ksortRecursive(array &$data): void
    {
        ksort($data);

        foreach ($data as &$value) {
            if (is_array($value)) {
                $this->ksortRecursive($value);
            }
        }
    }
}
