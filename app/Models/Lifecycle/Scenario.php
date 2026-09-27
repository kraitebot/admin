<?php

declare(strict_types=1);

namespace App\Models\Lifecycle;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int|null $account_id
 * @property int|null $branched_from_t_index
 * @property string $name
 * @property int|null $parent_scenario_id
 * @property string $side
 */
final class Scenario extends Model
{
    protected $table = 'lifecycle_scenarios';

    protected $guarded = [];

    /** @phpstan-return HasMany<ScenarioToken, $this> */
    public function tokens(): HasMany
    {
        return $this->hasMany(ScenarioToken::class, 'scenario_id')
            ->orderBy('display_order');
    }

    /** @phpstan-return HasMany<ScenarioFrame, $this> */
    public function frames(): HasMany
    {
        return $this->hasMany(ScenarioFrame::class, 'scenario_id')
            ->orderBy('t_index');
    }

    /** @phpstan-return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_scenario_id');
    }

    /** @phpstan-return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_scenario_id');
    }

    /** @phpstan-return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function casts(): array
    {
        return [
            'branched_from_t_index' => 'integer',
        ];
    }
}
