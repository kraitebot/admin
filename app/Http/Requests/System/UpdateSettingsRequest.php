<?php

declare(strict_types=1);

namespace App\Http\Requests\System;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateSettingsRequest extends FormRequest
{
    /** @var list<int> */
    public const BSCS_FRESHNESS_OPTIONS = [900, 1800, 3600, 5400, 6900, 7200, 10800, 21600];

    /** @var list<int> */
    public const BSCS_COOLDOWN_OPTIONS = [1, 2, 4, 6, 12, 24, 48, 72, 168];

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
            'bscs_freshness_max_seconds' => ['required', 'integer', Rule::in(self::BSCS_FRESHNESS_OPTIONS)],
            'bscs_cooldown_hours' => ['nullable', 'integer', Rule::in(self::BSCS_COOLDOWN_OPTIONS)],
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
            'bscs_cooldown_hours' => 'BSCS cooldown window',
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

        if ($this->input('bscs_cooldown_hours') === 'inherit') {
            $normalized['bscs_cooldown_hours'] = null;
        }

        $this->merge($normalized);
    }
}
