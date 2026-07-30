<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use JsonException;

final class NotificationHistoryRequest extends FormRequest
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
            'cursor' => [
                'nullable',
                'string',
                'max:512',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! $this->isValidCursor($value)) {
                        $fail('The cursor is invalid.');
                    }
                },
            ],
        ];
    }

    private function isValidCursor(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        if ($decoded === false) {
            return false;
        }

        try {
            $parameters = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        return is_array($parameters)
            && is_string($parameters['sent_at'] ?? null)
            && is_numeric($parameters['id'] ?? null)
            && is_bool($parameters['_pointsToNextItems'] ?? null);
    }
}
