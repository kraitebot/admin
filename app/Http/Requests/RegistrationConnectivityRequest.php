<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Registration\RegistrationExchange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Kraite\Core\Models\User;

final class RegistrationConnectivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->registrationUser();

        if ($user === null || $user->status !== 'confirmed') {
            abort(404);
        }

        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'exchange' => ['required', 'string', Rule::in(RegistrationExchange::enabled())],
            'api_key' => ['required', 'string', 'max:2000'],
            'api_secret' => ['required', 'string', 'max:2000'],
            'passphrase' => ['nullable', 'required_if:exchange,kucoin,bitget', 'string', 'max:2000'],
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
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'exchange' => Str::lower((string) $this->input('exchange')),
        ]);
    }
}
