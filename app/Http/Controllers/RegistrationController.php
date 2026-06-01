<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\RegistrationConnectivityRequest;
use App\Http\Requests\RegistrationRequest;
use App\Support\Registration\RegistrationCompleter;
use App\Support\Registration\RegistrationConnectivityWorkflow;
use App\Support\Registration\RegistrationExchange;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\Subscription;
use Kraite\Core\Models\User;

final class RegistrationController extends Controller
{
    public function show(string $uuid): View|RedirectResponse
    {
        $user = $this->findRegistrationUser($uuid);

        if ($user === null) {
            abort(404);
        }

        if ($user->status === 'active') {
            return $this->redirectToLogin($user);
        }

        if ($user->status !== 'confirmed') {
            abort(404);
        }

        return view('register.show', [
            'user' => $user,
        ]);
    }

    public function store(
        RegistrationRequest $request,
        string $uuid,
        RegistrationCompleter $registrationCompleter,
        RegistrationConnectivityWorkflow $connectivity,
    ): RedirectResponse {
        $user = $this->findRegistrationUser($uuid);

        if ($user === null) {
            abort(404);
        }

        if ($user->status === 'active') {
            return $this->redirectToLogin($user);
        }

        if ($user->status !== 'confirmed') {
            abort(404);
        }

        $data = $request->validated();
        $connectivityResult = $connectivity->evaluate($user, $user->current_connectivity_test_uuid);
        $hasRequiredApiKeys = filled($data['api_key'] ?? null)
            && filled($data['api_secret'] ?? null)
            && (! in_array((string) ($data['exchange'] ?? ''), ['kucoin', 'bitget'], true) || filled($data['passphrase'] ?? null));
        $completeWithoutApiSetup = $request->boolean('complete_without_api_setup');
        $canTrade = (bool) ($hasRequiredApiKeys && $connectivityResult['is_complete'] && $connectivityResult['all_connected']);

        if (! $hasRequiredApiKeys) {
            if (! $completeWithoutApiSetup) {
                return back()->withErrors([
                    'api_key' => 'Add API credentials or confirm you want to create the account without them.',
                ])->withInput();
            }

            $data['api_key'] = null;
            $data['api_secret'] = null;
            $data['passphrase'] = null;
        }

        if (! $connectivityResult['is_complete']) {
            if ($completeWithoutApiSetup) {
                $registrationCompleter->complete(
                    user: $user,
                    data: $data,
                    draftAccount: null,
                    canTrade: false,
                    disabledReason: $hasRequiredApiKeys
                        ? 'Registration connectivity was not verified.'
                        : 'API credentials were not configured during registration.',
                );

                $user->refresh();

                Auth::login($user);
                $request->session()->regenerate();

                return redirect()
                    ->route('dashboard')
                    ->with('status', 'Account created! Welcome to Kraite.');
            }

            return back()->withErrors([
                'connectivity_verified' => 'Test connectivity before continuing.',
            ])->withInput();
        }

        if (! $connectivityResult['all_connected'] && ! $request->boolean('continue_without_connectivity')) {
            if (! $completeWithoutApiSetup) {
                return back()->withErrors([
                    'connectivity_verified' => 'Some servers could not connect. Confirm that trading will stay disabled until you add the IP addresses to your exchange account.',
                ])->withInput();
            }
        }

        $registrationCompleter->complete(
            user: $user,
            data: $data,
            draftAccount: $hasRequiredApiKeys ? $connectivityResult['draft_account'] : null,
            canTrade: $canTrade,
            disabledReason: $canTrade
                ? null
                : ($hasRequiredApiKeys ? 'Registration connectivity test failed on one or more required servers.' : 'API credentials were not configured during registration.'),
        );

        $user->refresh();

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()
            ->route('dashboard')
            ->with('status', 'Account created! Welcome to Kraite.');
    }

    public function testConnectivity(
        RegistrationConnectivityRequest $request,
        string $uuid,
        RegistrationConnectivityWorkflow $connectivity,
    ): JsonResponse {
        $user = $request->registrationUser();

        if ($user === null) {
            abort(404);
        }

        $data = $request->validated();

        return response()->json($connectivity->start($user, $data));
    }

    public function connectivityStatus(
        string $uuid,
        string $blockUuid,
        RegistrationConnectivityWorkflow $connectivity,
    ): JsonResponse {
        $user = $this->findRegistrationUser($uuid);

        if ($user === null || $user->status !== 'confirmed') {
            abort(404);
        }

        return response()->json($connectivity->status($user, $blockUuid));
    }

    private function findRegistrationUser(string $uuid): ?User
    {
        return User::where('uuid', $uuid)->first();
    }

    private function redirectToLogin(User $user): RedirectResponse
    {
        return redirect()->route('login', ['email' => $user->email]);
    }

    public static function proposedName(User $user): string
    {
        $localPart = Str::before($user->email, '@');
        $storedName = trim((string) $user->name);

        if ($storedName !== '' && $storedName !== $localPart) {
            return $storedName;
        }

        return Str::of($localPart)
            ->replaceMatches('/[._+\-]+/', ' ')
            ->squish()
            ->title()
            ->toString();
    }

    /**
     * @return Collection<int, Subscription>
     */
    public static function registrationSubscriptions(): Collection
    {
        return Subscription::query()
            ->whereIn('canonical', ['basic', 'unlimited'])
            ->where('is_active', true)
            ->orderByRaw("CASE canonical WHEN 'basic' THEN 0 WHEN 'unlimited' THEN 1 ELSE 2 END")
            ->get();
    }

    /**
     * @return Collection<int, ApiSystem>
     */
    public static function registrationExchanges(): Collection
    {
        return ApiSystem::query()
            ->whereIn('canonical', RegistrationExchange::all())
            ->where('is_exchange', true)
            ->orderBy('name')
            ->get();
    }
}
