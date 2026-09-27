<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Models\Lifecycle\Scenario;
use App\Models\Lifecycle\ScenarioFrame;
use App\Models\Lifecycle\ScenarioFrameEvent;
use App\Models\Lifecycle\ScenarioToken;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
        $validated = $request->validate($this->storeRules());

        $account = Account::findOrFail((int) $validated['account_id']);

        try {
            $scenario = DB::transaction(fn (): Scenario => $this->createScenario($validated, $account));
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

        $this->deleteFrameAndReindex($scenario, $frame);

        $scenario->touch();

        return response()->json(['ok' => true]);
    }

    /**
     * Replace the events of a frame (per-token). Bulk update so the
     * client can debounce-save all edits in one round-trip.
     */
    public function saveFrameEvents(Request $request, int $scenarioId, int $frameId): JsonResponse
    {
        $request->validate($this->frameEventRules());

        $scenario = Scenario::findOrFail($scenarioId);
        $frame = ScenarioFrame::where('scenario_id', $scenario->id)->findOrFail($frameId);

        $this->replaceFrameEvents($frame, (array) $request->input('events'));

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
        $validated = $request->validate($this->branchRules());

        $parent = Scenario::with(['tokens', 'frames.events'])->findOrFail($scenarioId);
        $branchAt = (int) $validated['t_index'];
        $child = DB::transaction(fn (): Scenario => $this->createBranchedScenario(
            $parent,
            $branchAt,
            (string) $validated['name'],
        ));

        return $this->branchResponse($child);
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

    /** @return array<string, array<int, string>> */
    private function storeRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'side' => ['required', 'in:LONG,SHORT'],
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'tokens' => ['required', 'array', 'min:1', 'max:6'],
            'tokens.*.exchange_symbol_id' => ['required', 'integer', 'exists:exchange_symbols,id'],
            'tokens.*.entry_price' => ['required', 'numeric', 'gt:0'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function createScenario(array $validated, Account $account): Scenario
    {
        $scenario = Scenario::create([
            'name' => $validated['name'],
            'side' => $validated['side'],
            'account_id' => $account->id,
            'created_by' => Auth::id(),
        ]);

        $this->createScenarioTokens($scenario, (array) $validated['tokens'], $account, (string) $validated['side']);
        $this->createInitialFrame($scenario);

        return $scenario;
    }

    /**
     * @param  array<int, array<string, mixed>>  $tokens
     */
    private function createScenarioTokens(Scenario $scenario, array $tokens, Account $account, string $side): void
    {
        foreach ($tokens as $order => $tokenInput) {
            $this->createScenarioToken($scenario, $tokenInput, (int) $order, $account, $side);
        }
    }

    /**
     * @param  array<string, mixed>  $tokenInput
     */
    private function createScenarioToken(Scenario $scenario, array $tokenInput, int $order, Account $account, string $side): void
    {
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

    private function deleteFrameAndReindex(Scenario $scenario, ScenarioFrame $frame): void
    {
        DB::transaction(function () use ($scenario, $frame): void {
            $deletedIndex = $frame->t_index;
            $frame->events()->delete();
            $frame->delete();
            $this->reindexFramesAfter($scenario, $deletedIndex);
        });
    }

    private function reindexFramesAfter(Scenario $scenario, int $deletedIndex): void
    {
        ScenarioFrame::where('scenario_id', $scenario->id)
            ->where('t_index', '>', $deletedIndex)
            ->orderBy('t_index')
            ->each(function (ScenarioFrame $downstream): void {
                $downstream->t_index -= 1;
                $downstream->label = $this->autoLabelMatchesIndex($downstream->label, $downstream->t_index + 1)
                    ? 'T'.$downstream->t_index
                    : $downstream->label;
                $downstream->save();
            });
    }

    /** @return array<string, array<int, string>> */
    private function frameEventRules(): array
    {
        return [
            'events' => ['present', 'array'],
            'events.*.scenario_token_id' => ['required', 'integer', 'exists:lifecycle_scenario_tokens,id'],
            'events.*.event_type' => ['required', 'string', 'max:32'],
            'events.*.event_data' => ['required', 'array'],
        ];
    }

    /** @param array<int, array<string, mixed>> $events */
    private function replaceFrameEvents(ScenarioFrame $frame, array $events): void
    {
        DB::transaction(function () use ($frame, $events): void {
            $frame->events()->delete();
            foreach ($events as $event) {
                $this->createFrameEvent($frame, $event);
            }
        });
    }

    /** @param array<string, mixed> $event */
    private function createFrameEvent(ScenarioFrame $frame, array $event): void
    {
        ScenarioFrameEvent::create([
            'frame_id' => $frame->id,
            'scenario_token_id' => $event['scenario_token_id'],
            'event_type' => $event['event_type'],
            'event_data' => $event['event_data'],
        ]);
    }

    /** @return array<string, array<int, string>> */
    private function branchRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            't_index' => ['required', 'integer', 'min:0'],
        ];
    }

    private function createBranchedScenario(Scenario $parent, int $branchAt, string $name): Scenario
    {
        $child = Scenario::create([
            'name' => $name,
            'side' => $parent->side,
            'account_id' => $parent->account_id,
            'parent_scenario_id' => $parent->id,
            'branched_from_t_index' => $branchAt,
            'created_by' => Auth::id(),
        ]);

        $tokenIdMap = $this->copyScenarioTokens($parent, $child);
        $this->copyScenarioFrames($parent, $child, $branchAt, $tokenIdMap);

        return $child;
    }

    /** @param array<int, int> $tokenIdMap */
    private function copyScenarioFrames(Scenario $parent, Scenario $child, int $branchAt, array $tokenIdMap): void
    {
        foreach ($parent->frames as $frame) {
            if ($frame->t_index <= $branchAt) {
                $this->copyScenarioFrame($frame, $child, $tokenIdMap);
            }
        }
    }

    /** @param array<int, int> $tokenIdMap */
    private function copyScenarioFrame(ScenarioFrame $frame, Scenario $child, array $tokenIdMap): void
    {
        $newFrame = ScenarioFrame::create([
            'scenario_id' => $child->id,
            't_index' => $frame->t_index,
            'label' => $frame->label,
        ]);

        foreach ($frame->events as $event) {
            $this->copyScenarioEvent($newFrame, $event, $tokenIdMap);
        }
    }

    /** @param array<int, int> $tokenIdMap */
    private function copyScenarioEvent(ScenarioFrame $frame, ScenarioFrameEvent $event, array $tokenIdMap): void
    {
        ScenarioFrameEvent::create([
            'frame_id' => $frame->id,
            'scenario_token_id' => $tokenIdMap[$event->scenario_token_id] ?? $event->scenario_token_id,
            'event_type' => $event->event_type,
            'event_data' => $event->event_data,
        ]);
    }

    private function branchResponse(Scenario $child): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'scenario_id' => $child->id,
            'redirect' => route('system.lifecycle.show', $child->id),
        ]);
    }

    private function createInitialFrame(Scenario $scenario): void
    {
        ScenarioFrame::create([
            'scenario_id' => $scenario->id,
            't_index' => 0,
            'label' => 'T0',
        ]);
    }

    /** @return array<int, int> */
    private function copyScenarioTokens(Scenario $parent, Scenario $child): array
    {
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

        return $tokenIdMap;
    }

    /**
     * Snapshot every config value the lifecycle calculator depends on,
     * resolved for the scenario's side.
     */
    private function freezeConfig(ExchangeSymbol $symbol, Account $account, string $side, float $entryPrice): array
    {
        $isLong = $side === 'LONG';
        $leverage = (int) ($this->sideValue($isLong, $account->position_leverage_long, $account->position_leverage_short) ?? 1);
        $marginPercentage = (float) ($this->sideValue($isLong, $account->margin_percentage_long, $account->margin_percentage_short) ?? 0);
        $marginPerPosition = (float) ($account->margin ?? 0) * ($marginPercentage / 100.0);
        $gap = (float) ($this->sideValue($isLong, $symbol->percentage_gap_long, $symbol->percentage_gap_short) ?? 0);
        $multipliers = $symbol->limit_quantity_multipliers;
        $multipliers = array_values(array_map('floatval', (array) $multipliers));
        $baseQuantity = $entryPrice > 0
            ? ($marginPerPosition * $leverage) / $entryPrice
            : 0.0;

        return $this->frozenConfigPayload($symbol, $account, $side, $gap, $multipliers, $leverage, $marginPercentage, $marginPerPosition, $baseQuantity);
    }

    private function sideValue(bool $isLong, mixed $longValue, mixed $shortValue): mixed
    {
        return $isLong ? $longValue : $shortValue;
    }

    /**
     * @param  array<int, float>  $multipliers
     * @return array<string, mixed>
     */
    private function frozenConfigPayload(ExchangeSymbol $symbol, Account $account, string $side, float $gap, array $multipliers, int $leverage, float $marginPercentage, float $marginPerPosition, float $baseQuantity): array
    {
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
            'tokens' => $this->serialiseScenarioTokens($scenario->tokens),
            'frames' => $this->serialiseScenarioFrames($scenario->frames),
        ];
    }

    /**
     * @param  EloquentCollection<int, ScenarioToken>  $tokens
     * @return array<int, array<string, mixed>>
     */
    private function serialiseScenarioTokens(EloquentCollection $tokens): array
    {
        return $tokens->map(fn (ScenarioToken $token): array => [
            'id' => $token->id,
            'exchange_symbol_id' => $token->exchange_symbol_id,
            'token_label' => $token->token_label,
            'entry_price' => (float) $token->entry_price,
            'display_order' => $token->display_order,
            'frozen_config' => $token->frozen_config,
        ])->values()->all();
    }

    /**
     * @param  EloquentCollection<int, ScenarioFrame>  $frames
     * @return array<int, array<string, mixed>>
     */
    private function serialiseScenarioFrames(EloquentCollection $frames): array
    {
        return $frames->map(fn (ScenarioFrame $frame): array => $this->serialiseScenarioFrame($frame))->values()->all();
    }

    /** @return array<string, mixed> */
    private function serialiseScenarioFrame(ScenarioFrame $frame): array
    {
        return [
            'id' => $frame->id,
            't_index' => $frame->t_index,
            'label' => $frame->label,
            'events' => $this->serialiseScenarioEvents($frame->events),
        ];
    }

    /**
     * @param  EloquentCollection<int, ScenarioFrameEvent>  $events
     * @return array<int, array<string, mixed>>
     */
    private function serialiseScenarioEvents(EloquentCollection $events): array
    {
        return $events->map(fn (ScenarioFrameEvent $event): array => $this->serialiseScenarioEvent($event))->values()->all();
    }

    /** @return array<string, mixed> */
    private function serialiseScenarioEvent(ScenarioFrameEvent $event): array
    {
        return [
            'id' => $event->id,
            'scenario_token_id' => $event->scenario_token_id,
            'event_type' => $event->event_type,
            'event_data' => $event->event_data,
        ];
    }

    /**
     * Token universe for the create form. Active Binance pairs only,
     * grouped by quote.
     */
    private function availableTokens(): array
    {
        return $this->orderTokenBuckets($this->groupTokenBuckets($this->availableTokenRows()));
    }

    /**
     * @param  Collection<int, \stdClass>  $rows
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function groupTokenBuckets(Collection $rows): array
    {
        $buckets = [];
        $tokens = $rows->map(fn ($row) => $this->serialiseTokenRow($row))->values()->all();
        foreach ($tokens as $token) {
            $quote = mb_strtoupper((string) $token['quote']);
            $buckets[$quote][] = $token;
        }

        return $buckets;
    }

    /** @return array<string, mixed> */
    private function serialiseTokenRow(\stdClass $row): array
    {
        return [
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

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $buckets
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function orderTokenBuckets(array $buckets): array
    {
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

    /** @return Collection<int, \stdClass> */
    private function availableTokenRows(): Collection
    {
        return DB::table('exchange_symbols as es')
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
    }

    /**
     * Accounts the operator can drive. Scoped to the current user.
     */
    private function availableAccounts(): array
    {
        $userId = Auth::id();

        return $this->availableAccountRows($userId)
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

    /** @return Collection<int, \stdClass> */
    private function availableAccountRows(mixed $userId): Collection
    {
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
            ]);
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
