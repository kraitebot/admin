<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Kraite\Core\Support\Financial\ReportingDay;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
            // The trading-day basis: which hour the trader's day rolls over,
            // mirroring the exchange's own setting. Bounded to the list the
            // picker offers, so the form and the rule can never disagree.
            'utc_offset_minutes' => [
                'sometimes',
                'integer',
                Rule::in(array_keys(ReportingDay::selectableOffsets())),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'utc_offset_minutes.in' => 'Choose a trading day basis from the list.',
            'utc_offset_minutes.integer' => 'Choose a trading day basis from the list.',
        ];
    }
}
