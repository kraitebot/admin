<?php

declare(strict_types=1);

namespace App\Models\Lifecycle;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Scenario extends Model
{
    protected $table = 'lifecycle_scenarios';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'branched_from_t_index' => 'integer',
        ];
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(ScenarioToken::class, 'scenario_id')
            ->orderBy('display_order');
    }

    public function frames(): HasMany
    {
        return $this->hasMany(ScenarioFrame::class, 'scenario_id')
            ->orderBy('t_index');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_scenario_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_scenario_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
