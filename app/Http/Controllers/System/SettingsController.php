<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Http\Requests\System\UpdateSettingsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\ModelLog;
use Kraite\Core\Support\MarketRegime\Bscs;

final class SettingsController extends Controller
{
    /**
     * @var list<string>
     */
    private const EDITABLE_SETTINGS = [
        'allow_opening_positions',
        'can_trade',
        'notifications_enabled',
        'td_correlation_type',
        'corr_enabled',
        'elast_enabled',
        'trail_retention_hours',
        'bscs_freshness_max_seconds',
    ];

    public function index(): View
    {
        $engine = Kraite::findOrFail(1);
        $bscs = Bscs::current();
        $bscsBlocksOpens = $bscs->shouldBlockOpens();
        $canTrade = Kraite::canTrade();

        return view('system.settings', [
            'engine' => $engine,
            'effective' => [
                'can_trade' => $canTrade,
                'notifications_enabled' => Kraite::notificationsEnabled(),
                'td_correlation_type' => Kraite::correlationType(),
                'corr_enabled' => Kraite::correlationComputationEnabled(),
                'elast_enabled' => Kraite::elasticityComputationEnabled(),
                'trail_retention_hours' => Kraite::trailRetentionHours(),
            ],
            'bscsBlocksOpens' => $bscsBlocksOpens,
            'newOpensAllowed' => $canTrade
                && (bool) $engine->allow_opening_positions
                && ! $bscsBlocksOpens,
            'history' => ModelLog::query()
                ->where('loggable_type', Kraite::class)
                ->where('loggable_id', $engine->getKey())
                ->where('event_type', 'runtime_settings_updated')
                ->latest('id')
                ->limit(5)
                ->get(),
        ]);
    }

    public function update(UpdateSettingsRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($request, $validated): void {
            $engine = Kraite::query()->lockForUpdate()->findOrFail(1);
            $before = $this->settingsSnapshot($engine);

            $engine->forceFill($validated);
            $after = $this->settingsSnapshot($engine);

            if ($before === $after) {
                return;
            }

            $engine->save();

            $engine->modelLog(
                eventType: 'runtime_settings_updated',
                metadata: [
                    'actor_id' => $request->user()?->getAuthIdentifier(),
                    'actor_name' => $request->user()?->name,
                    'before' => $before,
                    'after' => $after,
                ],
                relatable: $request->user(),
                message: 'Runtime settings updated from the sysadmin console.',
            );
        });

        return redirect()
            ->route('system.settings')
            ->with('status', 'Runtime settings saved.');
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsSnapshot(Kraite $engine): array
    {
        return collect(self::EDITABLE_SETTINGS)
            ->mapWithKeys(fn (string $setting): array => [$setting => $engine->getAttribute($setting)])
            ->all();
    }
}
