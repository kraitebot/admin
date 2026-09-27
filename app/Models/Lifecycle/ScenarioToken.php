<?php

declare(strict_types=1);

namespace App\Models\Lifecycle;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $display_order
 * @property string $entry_price
 * @property int $exchange_symbol_id
 * @property string $token_label
 */
final class ScenarioToken extends Model
{
    protected $table = 'lifecycle_scenario_tokens';

    protected $guarded = [];

    /** @phpstan-return BelongsTo<Scenario, $this> */
    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class, 'scenario_id');
    }

    /** @phpstan-return HasMany<ScenarioFrameEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ScenarioFrameEvent::class, 'scenario_token_id');
    }

    protected function casts(): array
    {
        return [
            'frozen_config' => 'array',
            'entry_price' => 'string',
            'display_order' => 'integer',
        ];
    }
}
