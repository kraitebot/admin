<?php

declare(strict_types=1);

namespace App\Models\Lifecycle;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ScenarioFrameEvent extends Model
{
    protected $table = 'lifecycle_scenario_frame_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'event_data' => 'array',
        ];
    }

    public function frame(): BelongsTo
    {
        return $this->belongsTo(ScenarioFrame::class, 'frame_id');
    }

    public function scenarioToken(): BelongsTo
    {
        return $this->belongsTo(ScenarioToken::class, 'scenario_token_id');
    }
}
