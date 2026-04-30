<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\TopUpCoin;
use Throwable;

/**
 * Sysadmin CRUD for the curated top-up coin list. Each row maps a
 * NOWPayments currency canonical (e.g. usdttrc20) to a display name
 * + sort order + active flag, with an optional admin override of the
 * gateway-derived minimum amount.
 *
 * Mounted under /system/billing/coins as a sibling tab of Users +
 * Plans.
 */
final class BillingCoinsController extends Controller
{
    public function index(): View
    {
        $coins = TopUpCoin::orderBy('sort_order')->orderBy('display_name')->get();
        $engine = Kraite::find(1);

        $liveMinByCanonical = [];
        foreach ($coins as $coin) {
            $liveMinByCanonical[$coin->canonical] = $this->liveMinAmount($coin->canonical);
        }

        return view('system.billing.coins', [
            'coins' => $coins,
            'engine' => $engine,
            'liveMinByCanonical' => $liveMinByCanonical,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        TopUpCoin::create($data);

        return redirect()
            ->route('system.billing.coins')
            ->with('status', 'Coin added.');
    }

    public function update(Request $request, TopUpCoin $coin): RedirectResponse
    {
        $data = $this->validateData($request, ignoreId: $coin->id);

        $coin->update($data);

        return redirect()
            ->route('system.billing.coins')
            ->with('status', 'Coin updated.');
    }

    public function destroy(TopUpCoin $coin): RedirectResponse
    {
        $coin->delete();

        return redirect()
            ->route('system.billing.coins')
            ->with('status', 'Coin removed.');
    }

    public function updateEngine(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'top_up_minimum_when_covered_usdt' => 'required|numeric|min:0',
        ]);

        $engine = Kraite::findOrFail(1);
        $engine->top_up_minimum_when_covered_usdt = (float) $data['top_up_minimum_when_covered_usdt'];
        $engine->save();

        return redirect()
            ->route('system.billing.coins')
            ->with('status', 'Engine top-up floor updated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        $canonicalRule = 'required|string|max:64|alpha_dash|unique:top_up_coins,canonical';
        if ($ignoreId !== null) {
            $canonicalRule .= ','.$ignoreId;
        }

        $data = $request->validate([
            'canonical' => $canonicalRule,
            'display_name' => 'required|string|max:128',
            'sort_order' => 'required|integer|min:0',
            'is_active' => 'sometimes|boolean',
            'min_amount_override' => 'nullable|numeric|min:0',
        ]);

        $data['canonical'] = Str::lower($data['canonical']);
        $data['is_active'] = (bool) ($data['is_active'] ?? false);

        return $data;
    }

    /**
     * Quietly fetch the live gateway minimum so the admin sees how
     * the override (if any) compares to the current floor. Failures
     * surface as null — sysadmin still sees the row, just not the
     * live number.
     */
    private function liveMinAmount(string $canonical): ?array
    {
        return Cache::remember(
            "nowpayments.min_amount.{$canonical}",
            now()->addMinutes(5),
            function () use ($canonical) {
                $apiKey = (string) config('services.nowpayments.api_key', '');
                $baseUrl = (string) config('services.nowpayments.base_url', '');

                if ($apiKey === '' || $baseUrl === '') {
                    return null;
                }

                try {
                    $response = Http::withHeaders(['x-api-key' => $apiKey])
                        ->timeout(8)
                        ->get(rtrim($baseUrl, '/').'/min-amount', [
                            'currency_from' => $canonical,
                            'currency_to' => 'usdttrc20',
                        ])
                        ->json();

                    if (! is_array($response) || ! isset($response['min_amount'])) {
                        return null;
                    }

                    return [
                        'min_amount' => (float) $response['min_amount'],
                        'unit' => $canonical,
                    ];
                } catch (Throwable) {
                    return null;
                }
            },
        );
    }
}
