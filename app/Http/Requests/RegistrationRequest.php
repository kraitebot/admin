<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Registration\PasswordStrength;
use App\Support\Registration\RegistrationExchange;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Kraite\Core\Models\User;

final class RegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->registrationUser();

        if ($user === null || ! in_array($user->status, ['confirmed', 'active'], true)) {
            abort(404);
        }

        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        if ($this->registrationUser()?->status === 'active') {
            return [];
        }

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
                Rule::exists('subscriptions', 'id')->where(static function ($query): void {
                    $query->whereIn('canonical', ['basic', 'unlimited'])
                        ->where('is_active', true);
                }),
            ],
            'terms' => ['accepted'],
            'continue_without_connectivity' => ['nullable', 'boolean'],
        ];
    }

    public function registrationUser(): ?User
    {
        $uuid = $this->route('uuid');

        if (! is_string($uuid) || ! Str::isUuid($uuid)) {
            return null;
        }

        return User::where('uuid', $uuid)->first();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'api_key' => 'API key',
            'api_secret' => 'API secret',
            'subscription_id' => 'plan',
            'terms' => 'Terms & Conditions',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'exchange' => Str::lower((string) $this->input('exchange')),
        ]);
    }
}
