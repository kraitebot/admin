<?php

declare(strict_types=1);

namespace App\Http\Requests\System;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateSettingsRequest extends FormRequest
{
    /**
     * @var list<string>
     */
    private const NULLABLE_BOOLEAN_SETTINGS = [
        'can_trade',
        'notifications_enabled',
        'corr_enabled',
        'elast_enabled',
    ];

    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'allow_opening_positions' => ['required', 'boolean'],
            'can_trade' => ['nullable', 'boolean'],
            'notifications_enabled' => ['nullable', 'boolean'],
            'td_correlation_type' => ['nullable', Rule::in(['rolling', 'pearson', 'spearman'])],
            'corr_enabled' => ['nullable', 'boolean'],
            'elast_enabled' => ['nullable', 'boolean'],
            'trail_retention_hours' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'bscs_freshness_max_seconds' => ['required', 'integer', 'min:0', 'max:4294967295'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'allow_opening_positions' => 'new-position opening switch',
            'can_trade' => 'master trading switch',
            'notifications_enabled' => 'notification switch',
            'td_correlation_type' => 'correlation series',
            'corr_enabled' => 'correlation computation switch',
            'elast_enabled' => 'elasticity computation switch',
            'trail_retention_hours' => 'trail retention',
            'bscs_freshness_max_seconds' => 'BSCS freshness window',
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (self::NULLABLE_BOOLEAN_SETTINGS as $setting) {
            if ($this->input($setting) === 'inherit') {
                $normalized[$setting] = null;
            }
        }

        if ($this->input('td_correlation_type') === 'inherit') {
            $normalized['td_correlation_type'] = null;
        }

        $this->merge($normalized);
    }
}
