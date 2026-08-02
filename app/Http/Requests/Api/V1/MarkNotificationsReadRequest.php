<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class MarkNotificationsReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'event_ids' => ['required', 'array', 'min:1', 'max:30'],
            'event_ids.*' => ['required', 'string', 'max:100', 'distinct'],
        ];
    }
}
