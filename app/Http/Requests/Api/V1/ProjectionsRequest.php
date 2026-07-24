<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProjectionsRequest extends FormRequest
{
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
            'account_id' => ['nullable', 'integer'],
            'year' => ['nullable', 'required_with:month', 'integer', 'min:2000', 'max:2100'],
            'month' => ['nullable', 'required_with:year', 'integer', 'min:1', 'max:12'],
        ];
    }
}
