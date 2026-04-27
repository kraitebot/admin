<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\MarketRegimeSnapshot;
use Kraite\Core\Support\MarketRegime\BlackSwanIndex;
use Throwable;

/**
 * BSCS — Black Swan Composite Score feature page.
 *
 * Educational + live-state surface for the regime detector. Shows the
 * current score, the 4 bands, all 5 sub-signals with plain-English
 * explanations, the 30-day score timeline, and the cooldown mechanics.
 * Pulls live state via BlackSwanIndex; pulls history via
 * MarketRegimeSnapshot.
 */
final class BscsController extends Controller
{
    private const TIMELINE_HOURS = 24 * 30;

    public function index(): View
    {
        return view('bscs.index');
    }

    public function data(): JsonResponse
    {
        $payload = BlackSwanIndex::current()->toArray();

        // BlackSwanIndex::toArray() doesn't surface the override reason —
        // pull it directly so the override-active panel can show the audit
        // string the operator submitted.
        $payload['override_reason'] = Kraite::query()->value('bscs_override_reason');

        $timeline = MarketRegimeSnapshot::query()
            ->orderByDesc('computed_at')
            ->limit(self::TIMELINE_HOURS)
            ->get([
                'computed_at',
                'bscs_score',
                'bscs_band',
                'vol_expansion_value', 'vol_expansion_fired',
                'range_blowout_value', 'range_blowout_fired',
                'corr_regime_value', 'corr_regime_fired',
                'rejection_pct_value', 'rejection_pct_fired',
                'fut_vol_value', 'fut_vol_fired',
            ])
            ->reverse()
            ->values();

        $payload['timeline'] = $timeline->map(fn ($s) => [
            't' => $s->computed_at?->toIso8601String(),
            'score' => (int) $s->bscs_score,
            'band' => $s->bscs_band,
            'signals' => [
                'vol_expansion' => ['value' => $s->vol_expansion_value, 'fired' => (bool) $s->vol_expansion_fired],
                'range_blowout' => ['value' => $s->range_blowout_value, 'fired' => (bool) $s->range_blowout_fired],
                'corr_regime' => ['value' => $s->corr_regime_value, 'fired' => (bool) $s->corr_regime_fired],
                'rejection_pct' => ['value' => $s->rejection_pct_value, 'fired' => (bool) $s->rejection_pct_fired],
                'fut_vol' => ['value' => $s->fut_vol_value, 'fired' => (bool) $s->fut_vol_fired],
            ],
        ])->all();

        return response()->json($payload);
    }

    /**
     * Engage a time-boxed manual override that bypasses the BSCS cooldown.
     * Spec defaults: 4h default, 24h max. Reason is required for the audit
     * trail — modelLog captures every engage so the operator can reconstruct
     * "who told the bot to keep trading during a high-score window".
     */
    public function engageOverride(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'hours' => ['required', 'numeric', 'min:0.5', 'max:24'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        try {
            $singleton = Kraite::query()->first();
            if (! $singleton) {
                return response()->json(['ok' => false, 'error' => 'Kraite singleton not found.'], 404);
            }

            $singleton->bscs_override_until = now()->addMinutes((int) round((float) $validated['hours'] * 60));
            $singleton->bscs_override_reason = (string) $validated['reason'];
            $singleton->save();
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage(), 'exception' => $e::class], 500);
        }

        return response()->json([
            'ok' => true,
            'override_until' => $singleton->bscs_override_until?->toIso8601String(),
            'override_reason' => $singleton->bscs_override_reason,
        ]);
    }

    /**
     * Clear an active override before its natural expiry. Same modelLog
     * audit hook fires on save — operator can see when the early-release
     * happened.
     */
    public function clearOverride(): JsonResponse
    {
        try {
            $singleton = Kraite::query()->first();
            if (! $singleton) {
                return response()->json(['ok' => false, 'error' => 'Kraite singleton not found.'], 404);
            }

            $singleton->bscs_override_until = null;
            $singleton->bscs_override_reason = null;
            $singleton->save();
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage(), 'exception' => $e::class], 500);
        }

        return response()->json(['ok' => true]);
    }
}
