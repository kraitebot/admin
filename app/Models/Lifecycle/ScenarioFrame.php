<?php

declare(strict_types=1);

namespace App\Models\Lifecycle;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ScenarioFrame extends Model
{
    protected $table = 'lifecycle_scenario_frames';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            't_index' => 'integer',
        ];
    }

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class, 'scenario_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ScenarioFrameEvent::class, 'frame_id')
            ->orderBy('id');
    }
}
