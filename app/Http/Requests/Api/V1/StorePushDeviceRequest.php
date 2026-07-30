<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class StorePushDeviceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'expo_push_token' => [
                'required',
                'string',
                'max:255',
                'regex:/\A(?:Expo|Exponent)PushToken\[[A-Za-z0-9_-]+\]\z/',
            ],
            'platform' => ['required', 'string', 'in:ios'],
            'device_name' => ['required', 'string', 'max:100'],
            'app_version' => ['nullable', 'string', 'max:32'],
        ];
    }
}
