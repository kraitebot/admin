<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Http\Controllers\RegistrationController;
use App\Support\Registration\PasswordStrength;
use App\Support\Registration\RegistrationCompleter;
use App\Support\Registration\RegistrationConnectivityWorkflow;
use App\Support\Registration\RegistrationExchange;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\Rule;
use Kraite\Core\Models\User;
use Livewire\Component;

final class RegisterForm extends Component
{
    public string $uuid = '';

    public string $step = 'profile';

    public string $name = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $exchange = 'binance';

    public string $api_key = '';

    public string $api_secret = '';

    public ?string $passphrase = null;

    public ?int $subscription_id = null;

    public bool $terms = false;

    public bool $connectivity_verified = false;

    public bool $connectivity_complete = false;

    public bool $connectivity_passed = false;

    public ?string $connectivity_test_uuid = null;

    public bool $continue_without_connectivity = false;

    public function mount(string $uuid): void
    {
        $this->uuid = $uuid;

        $user = $this->registrationUser();

        if ($user === null) {
            abort(404);
        }

        if ($user->status === 'active') {
            $this->redirectRoute('login', ['email' => $user->email]);

            return;
        }

        if ($user->status !== 'confirmed') {
            abort(404);
        }

        $this->name = RegistrationController::proposedName($user);
        $this->subscription_id = RegistrationController::registrationSubscriptions()->first()?->id;
        $this->connectivity_test_uuid = $user->current_connectivity_test_uuid;
    }

    public function continueToCredentials(): mixed
    {
        if ($redirect = $this->redirectIfRegistrationUnavailable($this->registrationUser())) {
            return $redirect;
        }

        $this->validate($this->profileRules());

        $this->resetValidation();
        $this->step = 'credentials';

        return null;
    }

    public function backToProfile(): void
    {
        $this->resetValidation();
        $this->step = 'profile';
    }

    public function register(
        RegistrationCompleter $registrationCompleter,
        RegistrationConnectivityWorkflow $connectivity,
    ): mixed {
        $user = $this->registrationUser();

        if ($redirect = $this->redirectIfRegistrationUnavailable($user)) {
            return $redirect;
        }

        assert($user instanceof User);

        $data = $this->validate();

        try {
            $connectivityResult = $connectivity->evaluate($user, $this->connectivity_test_uuid ?? '');
        } catch (ModelNotFoundException) {
            $connectivityResult = [
                'is_complete' => false,
                'all_connected' => false,
                'draft_account' => null,
            ];
        }

        if (! $connectivityResult['is_complete']) {
            $this->connectivity_complete = false;
            $this->connectivity_passed = false;
            $this->connectivity_verified = false;
            $this->addError('connectivity_verified', 'Test connectivity before continuing.');

            return null;
        }

        if (! $connectivityResult['all_connected'] && ! $this->continue_without_connectivity) {
            $this->connectivity_complete = true;
            $this->connectivity_passed = false;
            $this->connectivity_verified = false;
            $this->addError('connectivity_verified', 'Some servers could not connect. You can still create the account but the trading will be disabled.');

            return null;
        }

        $this->connectivity_complete = true;
        $this->connectivity_passed = (bool) $connectivityResult['all_connected'];
        $this->connectivity_verified = $this->connectivity_passed;

        $registrationCompleter->complete(
            user: $user,
            data: $data,
            draftAccount: $connectivityResult['draft_account'],
            canTrade: (bool) $connectivityResult['all_connected'],
        );

        $user->refresh();

        auth()->login($user);
        session()->regenerate();

        session()->flash('status', 'Account created! Welcome to Kraite.');

        $this->step = 'confirmation';

        return null;
    }

    public function updatedExchange(string $exchange): void
    {
        $this->exchange = str($exchange)->lower()->toString();
        $this->resetConnectivityState();

        if (! in_array($this->exchange, ['kucoin', 'bitget'], true)) {
            $this->passphrase = null;
        }

        $this->resetValidation(['exchange', 'api_key', 'api_secret', 'passphrase']);
    }

    public function render(): View
    {
        $user = $this->registrationUser();

        if ($user === null) {
            abort(404);
        }

        return view('livewire.register-form', [
            'user' => $user,
            'subscriptions' => RegistrationController::registrationSubscriptions(),
            'exchanges' => RegistrationController::registrationExchanges(),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return array_merge($this->profileRules(), $this->credentialRules());
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function profileRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'password' => [
                'required',
                'confirmed',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! PasswordStrength::passes((string) $value)) {
                        $fail('Increase password strength until the meter reaches Strong enough.');
                    }
                },
            ],
            'subscription_id' => [
                'required',
                'integer',
                Rule::exists('subscriptions', 'id')
                    ->whereIn('canonical', ['basic', 'unlimited'])
                    ->where('is_active', true),
            ],
            'terms' => ['accepted'],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function credentialRules(): array
    {
        return [
            'exchange' => ['required', 'string', Rule::in(RegistrationExchange::enabled())],
            'api_key' => ['required', 'string', 'max:2000'],
            'api_secret' => ['required', 'string', 'max:2000'],
            'passphrase' => ['nullable', 'required_if:exchange,kucoin,bitget', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'api_key' => 'API key',
            'api_secret' => 'API secret',
            'subscription_id' => 'plan',
        ];
    }

    private function registrationUser(): ?User
    {
        return User::where('uuid', $this->uuid)->first();
    }

    private function redirectIfRegistrationUnavailable(?User $user): mixed
    {
        if ($user === null) {
            abort(404);
        }

        if ($user->status === 'active') {
            return redirect()->route('login', ['email' => $user->email]);
        }

        if ($user->status !== 'confirmed') {
            abort(404);
        }

        return null;
    }

    private function resetConnectivityState(): void
    {
        $this->connectivity_verified = false;
        $this->connectivity_complete = false;
        $this->connectivity_passed = false;
        $this->connectivity_test_uuid = null;
        $this->continue_without_connectivity = false;
    }
}
