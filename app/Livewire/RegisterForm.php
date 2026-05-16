<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Http\Controllers\RegistrationController;
use App\Support\Registration\PasswordStrength;
use App\Support\Registration\RegistrationCompleter;
use App\Support\Registration\RegistrationExchange;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Kraite\Core\Models\User;
use Livewire\Component;

final class RegisterForm extends Component
{
    public string $uuid = '';

    public string $name = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $exchange = 'binance';

    public string $api_key = '';

    public string $api_secret = '';

    public ?string $passphrase = null;

    public ?int $subscription_id = null;

    public bool $terms = false;

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
    }

    public function register(RegistrationCompleter $registrationCompleter): mixed
    {
        $user = $this->registrationUser();

        if ($user === null) {
            abort(404);
        }

        if ($user->status === 'active') {
            return redirect()->route('login', ['email' => $user->email]);
        }

        if ($user->status !== 'confirmed') {
            abort(404);
        }

        $data = $this->validate();

        $registrationCompleter->complete($user, $data);

        $user->refresh();

        auth()->login($user);
        session()->regenerate();

        return redirect()
            ->route('dashboard')
            ->with('status', 'Account created! Welcome to Kraite.');
    }

    public function updatedExchange(string $exchange): void
    {
        $this->exchange = str($exchange)->lower()->toString();

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
            'exchange' => ['required', 'string', Rule::in(RegistrationExchange::enabled())],
            'api_key' => ['required', 'string', 'max:2000'],
            'api_secret' => ['required', 'string', 'max:2000'],
            'passphrase' => ['nullable', 'required_if:exchange,kucoin,bitget', 'string', 'max:2000'],
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
}
