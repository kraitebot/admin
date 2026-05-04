<?php

declare(strict_types=1);

namespace App\Models\Lifecycle;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ScenarioToken extends Model
{
    protected $table = 'lifecycle_scenario_tokens';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'frozen_config' => 'array',
            'entry_price' => 'string',
            'display_order' => 'integer',
        ];
    }

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class, 'scenario_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ScenarioFrameEvent::class, 'scenario_token_id');
    }
}
