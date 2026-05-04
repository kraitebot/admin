<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Models\Lifecycle\Scenario;
use App\Models\Lifecycle\ScenarioFrame;
use App\Models\Lifecycle\ScenarioFrameEvent;
use App\Models\Lifecycle\ScenarioToken;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ExchangeSymbol;
use Throwable;

/**
 * LifecycleController
 *
 * Powers the manual position-lifecycle configurator at /system/lifecycle.
 *
 * Concept: an Excel-style scenario where each column is a frame (T0,
 * T1, T2, ...) and each row is a token-position. The operator drives
 * the world by adding events at each frame ("price moved to X", "L2
 * filled", "force close at Y"). The calculator is client-side (Alpine
 * + JS) — this controller only handles persistence and config-freeze.
 *
 * The controller is intentionally thin. The interesting logic
 * (recompute WAP / TP / SL / PnL frame-by-frame) lives in the JS
 * engine. The server's job is:
 *
 *   - List / create / branch / delete scenarios
 *   - At creation, freeze each token's config from live DB state
 *   - Persist frames + events as the operator edits them
 *
 * Computed state is never stored — it's always derived from events.
 */
final class LifecycleController extends Controller
{
    /**
     * Landing page. Lists existing scenarios with their branch
     * lineage; lets the operator open or create one.
     */
    public function index(): View
    {
        $scenarios = Scenario::query()
            ->with(['parent:id,name'])
            ->withCount('frames', 'tokens')
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get();

        return view('system.lifecycle.index', [
            'scenarios' => $scenarios,
        ]);
    }

    /**
     * Scenario-creation form. Returns the data needed to build the
     * "pick side / pick account / pick tokens with entry prices"
     * wizard.
     */
    public function create(): View
    {
        return view('system.lifecycle.create', [
            'accounts' => $this->availableAccounts(),
            'tokens' => $this->availableTokens(),
        ]);
    }

    /**
     * Persist a new scenario. Freezes per-token config from the live
     * DB state at this moment so the scenario stays reproducible
     * regardless of later config tuning.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'side' => ['required', 'in:LONG,SHORT'],
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'tokens' => ['required', 'array', 'min:1', 'max:6'],
            'tokens.*.exchange_symbol_id' => ['required', 'integer', 'exists:exchange_symbols,id'],
            'tokens.*.entry_price' => ['required', 'numeric', 'gt:0'],
        ]);

        $account = Account::findOrFail($validated['account_id']);
        $side = $validated['side'];

        try {
            $scenario = DB::transaction(function () use ($validated, $account, $side) {
                $scenario = Scenario::create([
                    'name' => $validated['name'],
                    'side' => $side,
                    'account_id' => $account->id,
                    'created_by' => Auth::id(),
                ]);

                foreach ($validated['tokens'] as $order => $tokenInput) {
                    $exchangeSymbol = ExchangeSymbol::findOrFail($tokenInput['exchange_symbol_id']);

                    ScenarioToken::create([
                        'scenario_id' => $scenario->id,
                        'exchange_symbol_id' => $exchangeSymbol->id,
                        'token_label' => $exchangeSymbol->token,
                        'entry_price' => $tokenInput['entry_price'],
                        'display_order' => $order,
                        'frozen_config' => $this->freezeConfig($exchangeSymbol, $account, $side, (float) $tokenInput['entry_price']),
                    ]);
                }

                ScenarioFrame::create([
                    'scenario_id' => $scenario->id,
                    't_index' => 0,
                    'label' => 'T0',
                ]);

                return $scenario;
            });
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }

        return response()->json([
            'ok' => true,
            'scenario_id' => $scenario->id,
            'redirect' => route('system.lifecycle.show', $scenario->id),
        ]);
    }

    /**
     * Render the spreadsheet grid for a scenario. The Blade view
     * hydrates an Alpine component with the full state JSON.
     */
    public function show(int $scenarioId): View
    {
        $scenario = Scenario::with([
            'tokens',
            'frames.events',
            'parent:id,name',
        ])->findOrFail($scenarioId);

        return view('system.lifecycle.show', [
            'scenario' => $scenario,
            'state' => $this->serialiseScenario($scenario),
        ]);
    }

    /**
     * JSON dump of a scenario's full state. Used by the side-by-side
     * compare view to load a second scenario into the page without a
     * full reload.
     */
    public function data(int $scenarioId): JsonResponse
    {
        $scenario = Scenario::with(['tokens', 'frames.events'])->findOrFail($scenarioId);

        return response()->json($this->serialiseScenario($scenario));
    }

    /**
     * Append a new frame to the right of the existing tail.
     */
    public function addFrame(Request $request, int $scenarioId): JsonResponse
    {
        $request->validate([
            'label' => ['nullable', 'string', 'max:200'],
        ]);

        $scenario = Scenario::findOrFail($scenarioId);
        $nextIndex = ($scenario->frames()->max('t_index') ?? -1) + 1;

        $frame = ScenarioFrame::create([
            'scenario_id' => $scenario->id,
            't_index' => $nextIndex,
            'label' => $request->input('label') ?: 'T'.$nextIndex,
        ]);

        $scenario->touch();

        return response()->json([
            'ok' => true,
            'frame' => [
                'id' => $frame->id,
                't_index' => $frame->t_index,
                'label' => $frame->label,
                'events' => [],
            ],
        ]);
    }

    /**
     * Delete a frame. Reindexes downstream frames so t_index stays
     * contiguous from 0.
     */
    public function deleteFrame(int $scenarioId, int $frameId): JsonResponse
    {
        $scenario = Scenario::findOrFail($scenarioId);
        $frame = ScenarioFrame::where('scenario_id', $scenario->id)->findOrFail($frameId);

        if ($frame->t_index === 0) {
            return response()->json(['error' => 'Cannot delete T0 — it is the initial state.'], 422);
        }

        DB::transaction(function () use ($scenario, $frame): void {
            $deletedIndex = $frame->t_index;
            $frame->events()->delete();
            $frame->delete();

            // Re-densify: every frame whose t_index was greater than
            // the deleted one shifts down by one.
            ScenarioFrame::where('scenario_id', $scenario->id)
                ->where('t_index', '>', $deletedIndex)
                ->orderBy('t_index')
                ->each(function (ScenarioFrame $downstream): void {
                    $downstream->t_index = $downstream->t_index - 1;
                    $downstream->label = $this->autoLabelMatchesIndex($downstream->label, $downstream->t_index + 1)
                        ? 'T'.$downstream->t_index
                        : $downstream->label;
                    $downstream->save();
                });
        });

        $scenario->touch();

        return response()->json(['ok' => true]);
    }

    /**
     * Replace the events of a frame (per-token). Bulk update so the
     * client can debounce-save all edits in one round-trip.
     */
    public function saveFrameEvents(Request $request, int $scenarioId, int $frameId): JsonResponse
    {
        $request->validate([
            'events' => ['present', 'array'],
            'events.*.scenario_token_id' => ['required', 'integer', 'exists:lifecycle_scenario_tokens,id'],
            'events.*.event_type' => ['required', 'string', 'max:32'],
            'events.*.event_data' => ['required', 'array'],
        ]);

        $scenario = Scenario::findOrFail($scenarioId);
        $frame = ScenarioFrame::where('scenario_id', $scenario->id)->findOrFail($frameId);

        DB::transaction(function () use ($frame, $request): void {
            $frame->events()->delete();
            foreach ($request->input('events') as $event) {
                ScenarioFrameEvent::create([
                    'frame_id' => $frame->id,
                    'scenario_token_id' => $event['scenario_token_id'],
                    'event_type' => $event['event_type'],
                    'event_data' => $event['event_data'],
                ]);
            }
        });

        $scenario->touch();

        return response()->json(['ok' => true]);
    }

    /**
     * Fork a scenario at a given t_index. The child gets a full copy
     * of every frame from T0 through the branch point. Frames after
     * the branch point are NOT copied — the child starts at the branch
     * point and grows from there independently.
     */
    public function branch(Request $request, int $scenarioId): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:200'],
            't_index' => ['required', 'integer', 'min:0'],
        ]);

        $parent = Scenario::with(['tokens', 'frames.events'])->findOrFail($scenarioId);
        $branchAt = (int) $request->input('t_index');

        $child = DB::transaction(function () use ($parent, $branchAt, $request) {
            $child = Scenario::create([
                'name' => $request->input('name'),
                'side' => $parent->side,
                'account_id' => $parent->account_id,
                'parent_scenario_id' => $parent->id,
                'branched_from_t_index' => $branchAt,
                'created_by' => Auth::id(),
            ]);

            // Map old token id → new token id so we can remap events.
            $tokenIdMap = [];
            foreach ($parent->tokens as $token) {
                $copy = ScenarioToken::create([
                    'scenario_id' => $child->id,
                    'exchange_symbol_id' => $token->exchange_symbol_id,
                    'token_label' => $token->token_label,
                    'entry_price' => $token->entry_price,
                    'display_order' => $token->display_order,
                    'frozen_config' => $token->frozen_config,
                ]);
                $tokenIdMap[$token->id] = $copy->id;
            }

            foreach ($parent->frames as $frame) {
                if ($frame->t_index > $branchAt) {
                    continue;
                }

                $newFrame = ScenarioFrame::create([
                    'scenario_id' => $child->id,
                    't_index' => $frame->t_index,
                    'label' => $frame->label,
                ]);

                foreach ($frame->events as $event) {
                    ScenarioFrameEvent::create([
                        'frame_id' => $newFrame->id,
                        'scenario_token_id' => $tokenIdMap[$event->scenario_token_id] ?? $event->scenario_token_id,
                        'event_type' => $event->event_type,
                        'event_data' => $event->event_data,
                    ]);
                }
            }

            return $child;
        });

        return response()->json([
            'ok' => true,
            'scenario_id' => $child->id,
            'redirect' => route('system.lifecycle.show', $child->id),
        ]);
    }

    /**
     * Delete a scenario. Cascades through frames + events + tokens
     * via the restrictOnDelete rules in the migrations, so we have
     * to walk children manually.
     */
    public function destroy(int $scenarioId): JsonResponse
    {
        $scenario = Scenario::findOrFail($scenarioId);

        DB::transaction(function () use ($scenario): void {
            ScenarioFrameEvent::whereIn('frame_id', $scenario->frames()->pluck('id'))->delete();
            $scenario->frames()->delete();
            $scenario->tokens()->delete();
            $scenario->delete();
        });

        return response()->json(['ok' => true]);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Snapshot every config value the lifecycle calculator depends on,
     * resolved for the scenario's side.
     */
    private function freezeConfig(ExchangeSymbol $symbol, Account $account, string $side, float $entryPrice): array
    {
        $isLong = $side === 'LONG';

        $leverage = $isLong
            ? (int) ($account->position_leverage_long ?? 1)
            : (int) ($account->position_leverage_short ?? 1);

        $marginPercentage = $isLong
            ? (float) ($account->margin_percentage_long ?? 0)
            : (float) ($account->margin_percentage_short ?? 0);

        $marginPerPosition = (float) ($account->margin ?? 0) * ($marginPercentage / 100.0);

        $gap = $isLong
            ? (float) ($symbol->percentage_gap_long ?? 0)
            : (float) ($symbol->percentage_gap_short ?? 0);

        $multipliers = $symbol->limit_quantity_multipliers;
        if (is_string($multipliers)) {
            $decoded = json_decode($multipliers, true);
            $multipliers = is_array($decoded) ? $decoded : [];
        }
        $multipliers = array_values(array_map('floatval', (array) $multipliers));

        $baseQuantity = $entryPrice > 0
            ? ($marginPerPosition * $leverage) / $entryPrice
            : 0.0;

        return [
            'side' => $side,
            'percentage_gap' => $gap,
            'total_limit_orders' => (int) ($symbol->total_limit_orders ?? 0),
            'limit_quantity_multipliers' => $multipliers,
            'profit_percentage' => (float) ($symbol->profit_percentage ?? $account->profit_percentage ?? 0),
            'stop_market_percentage' => (float) ($symbol->stop_market_percentage ?? $account->stop_market_initial_percentage ?? 0),
            'leverage' => $leverage,
            'margin_percentage' => $marginPercentage,
            'margin_per_position_usdt' => $marginPerPosition,
            'base_quantity' => $baseQuantity,
            'price_precision' => (int) ($symbol->price_precision ?? 4),
            'quantity_precision' => (int) ($symbol->quantity_precision ?? 4),
            'token_label' => $symbol->token,
            'quote' => $symbol->quote,
        ];
    }

    /**
     * Render the scenario as an array for client-side hydration. No
     * computed state — only events. The JS engine derives everything.
     */
    private function serialiseScenario(Scenario $scenario): array
    {
        return [
            'id' => $scenario->id,
            'name' => $scenario->name,
            'side' => $scenario->side,
            'account_id' => $scenario->account_id,
            'parent_scenario_id' => $scenario->parent_scenario_id,
            'branched_from_t_index' => $scenario->branched_from_t_index,
            'parent_name' => $scenario->parent?->name,
            'tokens' => $scenario->tokens->map(fn (ScenarioToken $t) => [
                'id' => $t->id,
                'exchange_symbol_id' => $t->exchange_symbol_id,
                'token_label' => $t->token_label,
                'entry_price' => (float) $t->entry_price,
                'display_order' => $t->display_order,
                'frozen_config' => $t->frozen_config,
            ])->values()->all(),
            'frames' => $scenario->frames->map(fn (ScenarioFrame $f) => [
                'id' => $f->id,
                't_index' => $f->t_index,
                'label' => $f->label,
                'events' => $f->events->map(fn (ScenarioFrameEvent $e) => [
                    'id' => $e->id,
                    'scenario_token_id' => $e->scenario_token_id,
                    'event_type' => $e->event_type,
                    'event_data' => $e->event_data,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * Token universe for the create form. Active Binance pairs only,
     * grouped by quote.
     */
    private function availableTokens(): array
    {
        $rows = DB::table('exchange_symbols as es')
            ->join('symbols as s', 's.id', '=', 'es.symbol_id')
            ->join('api_systems as ap', 'ap.id', '=', 'es.api_system_id')
            ->where('ap.canonical', 'binance')
            ->where('es.is_manually_enabled', true)
            ->orderByRaw('UPPER(s.token) ASC')
            ->get([
                'es.id',
                's.token',
                'es.quote',
                'es.percentage_gap_long',
                'es.percentage_gap_short',
                'es.total_limit_orders',
                'es.limit_quantity_multipliers',
                'es.profit_percentage',
                'es.stop_market_percentage',
                'es.was_backtesting_approved',
                'es.mark_price',
            ]);

        $buckets = [];
        foreach ($rows as $row) {
            $q = mb_strtoupper((string) $row->quote);
            $buckets[$q][] = [
                'id' => (int) $row->id,
                'token' => $row->token,
                'quote' => $row->quote,
                'percentage_gap_long' => (float) ($row->percentage_gap_long ?? 0),
                'percentage_gap_short' => (float) ($row->percentage_gap_short ?? 0),
                'total_limit_orders' => (int) ($row->total_limit_orders ?? 0),
                'profit_percentage' => (float) ($row->profit_percentage ?? 0),
                'stop_market_percentage' => (float) ($row->stop_market_percentage ?? 0),
                'was_backtesting_approved' => (bool) $row->was_backtesting_approved,
                'mark_price' => (float) ($row->mark_price ?? 0),
            ];
        }

        $ordered = [];
        foreach (['USDT', 'USDC'] as $primary) {
            if (! empty($buckets[$primary])) {
                $ordered[$primary] = $buckets[$primary];
                unset($buckets[$primary]);
            }
        }
        ksort($buckets);

        return $ordered + $buckets;
    }

    /**
     * Accounts the operator can drive. Scoped to the current user.
     */
    private function availableAccounts(): array
    {
        $userId = Auth::id();

        return DB::table('accounts')
            ->whereNull('deleted_at')
            ->where(function ($q) use ($userId): void {
                $q->where('user_id', $userId)->orWhereNull('user_id');
            })
            ->orderBy('id')
            ->get([
                'id',
                'name',
                'margin',
                'position_leverage_long',
                'position_leverage_short',
                'margin_percentage_long',
                'margin_percentage_short',
            ])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'margin' => (float) ($row->margin ?? 0),
                'position_leverage_long' => (int) ($row->position_leverage_long ?? 1),
                'position_leverage_short' => (int) ($row->position_leverage_short ?? 1),
                'margin_percentage_long' => (float) ($row->margin_percentage_long ?? 0),
                'margin_percentage_short' => (float) ($row->margin_percentage_short ?? 0),
            ])
            ->all();
    }

    /**
     * "T7" matches index 7 → auto-label, safe to renumber on delete.
     * Custom labels ("BTC dump") are left alone.
     */
    private function autoLabelMatchesIndex(?string $label, int $expectedIndex): bool
    {
        return $label === ('T'.$expectedIndex);
    }
}
