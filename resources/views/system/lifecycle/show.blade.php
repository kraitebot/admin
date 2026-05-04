<x-app-layout :activeSection="'system'" :activeHighlight="'lifecycle'" :flush="true">
    <div class="h-full flex flex-col"
         x-data="lifecycleGrid({
            state: @js($state),
            urls: {
                addFrame: @js(route('system.lifecycle.frame.add', $scenario)),
                deleteFrame: @js(route('system.lifecycle.frame.delete', ['scenario' => $scenario, 'frame' => 0])),
                saveEvents: @js(route('system.lifecycle.frame.events', ['scenario' => $scenario, 'frame' => 0])),
                branch: @js(route('system.lifecycle.branch', $scenario)),
                data: @js(route('system.lifecycle.data', ['scenario' => 0])),
                show: @js(route('system.lifecycle.show', ['scenario' => 0])),
                index: @js(route('system.lifecycle')),
            },
         })">

        {{-- Top bar --}}
        <div class="flex items-center justify-between gap-3 px-4 py-3 ui-border-b flex-wrap">
            <div class="flex items-center gap-3 flex-wrap">
                <a href="{{ route('system.lifecycle') }}"
                   wire:navigate
                   class="ui-btn ui-btn-ghost ui-btn-sm">←</a>
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-base font-semibold ui-text" x-text="state.name"></h1>
                        <x-hub-ui::badge :type="$scenario->side === 'LONG' ? 'success' : 'danger'">
                            {{ $scenario->side }}
                        </x-hub-ui::badge>
                        <template x-if="state.parent_name">
                            <span class="text-[11px] ui-text-subtle">
                                ↳ branched from
                                <a :href="urls.show.replace('/0', '/' + state.parent_scenario_id)"
                                   class="hover:ui-text-primary"
                                   x-text="state.parent_name"></a>
                                @ T<span x-text="state.branched_from_t_index"></span>
                            </span>
                        </template>
                    </div>
                    <div class="text-[11px] ui-text-subtle">
                        <span x-text="state.tokens.length"></span> token<span x-show="state.tokens.length !== 1">s</span> ·
                        <span x-text="state.frames.length"></span> frame<span x-show="state.frames.length !== 1">s</span>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" @click="showCompare = !showCompare" class="ui-btn ui-btn-ghost ui-btn-sm" :class="showCompare ? 'ui-text-primary' : ''">
                    Compare
                </button>
                <button type="button" @click="branchModalOpen = true" class="ui-btn ui-btn-ghost ui-btn-sm">
                    Branch
                </button>
                <button type="button" @click="addFrame()" :disabled="busy" class="ui-btn ui-btn-primary ui-btn-sm">
                    + T
                </button>
            </div>
        </div>

        {{-- Portfolio summary ribbon --}}
        <div class="px-4 py-2 ui-bg-elevated ui-border-b">
            <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-3">
                <template x-for="frame in state.frames" :key="frame.id">
                    <div class="text-center">
                        <div class="text-[10px] uppercase tracking-wider ui-text-subtle" x-text="frame.label"></div>
                        <div class="text-sm font-semibold ui-tabular"
                             :style="portfolioColor(frame.t_index)"
                             x-text="formatUsdt(portfolioPnl(frame.t_index))"></div>
                        <div class="text-[10px] ui-text-subtle ui-tabular"
                             x-text="portfolioPercent(frame.t_index)"></div>
                    </div>
                </template>
            </div>
        </div>

        {{-- The grid --}}
        <div class="flex-1 overflow-auto">
            <div :class="showCompare ? 'grid grid-cols-2 gap-px ui-bg-elevated' : ''">
                <div class="ui-bg-body">
                    <table class="w-full" style="border-collapse: separate; border-spacing: 0;">
                        <thead>
                            <tr>
                                <th class="text-left px-3 py-2 sticky left-0 ui-bg-card z-20 ui-border-b ui-border-r" style="min-width: 180px;">
                                    <span class="text-[11px] uppercase tracking-wider ui-text-subtle">Token</span>
                                </th>
                                <template x-for="frame in state.frames" :key="frame.id">
                                    <th class="px-3 py-2 ui-border-b ui-bg-card" style="min-width: 200px;">
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="text-xs font-semibold ui-text" x-text="frame.label"></span>
                                            <button type="button"
                                                    x-show="frame.t_index > 0"
                                                    @click="deleteFrame(frame)"
                                                    class="text-[10px] ui-text-subtle hover:ui-text-danger transition-colors"
                                                    title="Delete this frame">×</button>
                                        </div>
                                    </th>
                                </template>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="token in state.tokens" :key="token.id">
                                <tr class="ui-border-b">
                                    <td class="sticky left-0 ui-bg-card z-10 px-3 py-3 align-top ui-border-r">
                                        <div class="text-sm font-semibold ui-text" x-text="token.token_label"></div>
                                        <div class="text-[10px] ui-text-subtle" x-text="token.frozen_config.quote"></div>
                                        <div class="mt-2 space-y-0.5 text-[10px] ui-text-muted ui-tabular">
                                            <div>entry · <span x-text="formatPrice(token.entry_price, token)"></span></div>
                                            <div>gap · <span x-text="token.frozen_config.percentage_gap.toFixed(2) + '%'"></span></div>
                                            <div>lev · <span x-text="token.frozen_config.leverage + 'x'"></span></div>
                                            <div>marg · <span x-text="formatUsdt(token.frozen_config.margin_per_position_usdt)"></span></div>
                                            <div>TP · <span x-text="token.frozen_config.profit_percentage.toFixed(2) + '%'"></span></div>
                                            <div>SL · <span x-text="token.frozen_config.stop_market_percentage.toFixed(2) + '%'"></span></div>
                                        </div>
                                    </td>

                                    <template x-for="frame in state.frames" :key="frame.id">
                                        <td class="align-top p-2 ui-border-r" :class="cellBackground(token.id, frame.t_index)">
                                            <div class="space-y-1">
                                                {{-- Price (editable) --}}
                                                <div class="flex items-center justify-between gap-1">
                                                    <span class="text-[10px] ui-text-subtle uppercase tracking-wider">Price</span>
                                                    <input type="number"
                                                           step="any"
                                                           :value="getPrice(token.id, frame.t_index)"
                                                           @change="setPrice(token.id, frame, $event.target.value)"
                                                           class="ui-input ui-input-sm w-24 text-right text-xs ui-tabular" />
                                                </div>

                                                {{-- Fills row --}}
                                                <div class="flex items-center justify-between gap-1">
                                                    <span class="text-[10px] ui-text-subtle uppercase tracking-wider">Fills</span>
                                                    <div class="flex gap-0.5">
                                                        <template x-for="lvl in [0, 1, 2, 3, 4]" :key="lvl">
                                                            <button type="button"
                                                                    @click="toggleLimit(token.id, frame, lvl)"
                                                                    :class="isFilled(token.id, frame.t_index, lvl) ? 'ui-bg-primary ui-text-on-primary' : 'ui-bg-elevated ui-text-muted'"
                                                                    :disabled="lvl === 0"
                                                                    class="w-5 h-5 text-[9px] font-mono rounded transition-colors hover:ui-text"
                                                                    :title="'Limit ' + lvl">
                                                                <span x-text="lvl === 0 ? 'M' : ('L' + lvl)"></span>
                                                            </button>
                                                        </template>
                                                    </div>
                                                </div>

                                                {{-- WAP --}}
                                                <div class="flex items-center justify-between gap-1 text-xs ui-tabular">
                                                    <span class="text-[10px] ui-text-subtle uppercase tracking-wider">WAP</span>
                                                    <span x-text="formatPrice(stateAt(token.id, frame.t_index).wap, token)"></span>
                                                </div>

                                                {{-- TP --}}
                                                <div class="flex items-center justify-between gap-1 text-xs ui-tabular">
                                                    <span class="text-[10px] ui-text-subtle uppercase tracking-wider">TP</span>
                                                    <span x-text="formatPrice(stateAt(token.id, frame.t_index).tp, token)"></span>
                                                </div>

                                                {{-- SL --}}
                                                <div class="flex items-center justify-between gap-1 text-xs ui-tabular">
                                                    <span class="text-[10px] ui-text-subtle uppercase tracking-wider">SL</span>
                                                    <span x-text="formatPrice(stateAt(token.id, frame.t_index).sl, token)"></span>
                                                </div>

                                                {{-- PnL --}}
                                                <div class="flex items-center justify-between gap-1 pt-1 ui-border-t">
                                                    <span class="text-[10px] ui-text-subtle uppercase tracking-wider">PnL</span>
                                                    <span class="text-xs font-semibold ui-tabular"
                                                          :style="pnlColor(token.id, frame.t_index)"
                                                          x-text="formatUsdt(stateAt(token.id, frame.t_index).total_pnl)"></span>
                                                </div>

                                                {{-- Status --}}
                                                <div class="flex items-center justify-between gap-1">
                                                    <span class="text-[10px] ui-text-subtle uppercase tracking-wider">Status</span>
                                                    <span class="text-[10px] font-mono" :style="statusColor(token.id, frame.t_index)"
                                                          x-text="stateAt(token.id, frame.t_index).status"></span>
                                                </div>

                                                {{-- Quick actions --}}
                                                <div class="pt-1 flex gap-1">
                                                    <button type="button"
                                                            @click="openClose(token.id, frame)"
                                                            :disabled="stateAt(token.id, frame.t_index).status !== 'open'"
                                                            class="text-[10px] px-1.5 py-0.5 ui-text-muted hover:ui-text-danger transition-colors disabled:opacity-30">
                                                        Close
                                                    </button>
                                                </div>
                                            </div>
                                        </td>
                                    </template>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                {{-- Compare pane --}}
                <div x-show="showCompare" class="ui-bg-body p-4">
                    <div class="mb-3">
                        <label class="block text-[11px] uppercase tracking-wider ui-text-subtle mb-1">Compare with</label>
                        <select x-model.number="compareScenarioId" @change="loadCompare()" class="ui-input ui-input-sm w-full">
                            <option :value="null">— pick a scenario —</option>
                        </select>
                    </div>
                    <div x-show="compareState" class="text-xs ui-text-muted">
                        Compare view loads in this pane. (Side-by-side render of compareState.frames will populate here.)
                    </div>
                </div>
            </div>
        </div>

        {{-- Branch modal --}}
        <div x-show="branchModalOpen"
             @keydown.escape.window="branchModalOpen = false"
             class="fixed inset-0 z-50 flex items-center justify-center p-4"
             style="background: rgba(0,0,0,0.5)"
             x-cloak>
            <div class="ui-card p-5 w-full max-w-md space-y-4">
                <h3 class="text-base font-semibold ui-text">Branch scenario</h3>
                <p class="text-xs ui-text-muted">
                    Forks the current scenario at a chosen frame. Frames T0 through the branch point are copied
                    fully — edits to this scenario after that won't affect the branch.
                </p>
                <div>
                    <label class="block text-[11px] uppercase tracking-wider ui-text-subtle mb-1">New name</label>
                    <input type="text" x-model="branchForm.name" class="ui-input w-full" placeholder="e.g. Cascade — force-flat at T7" />
                </div>
                <div>
                    <label class="block text-[11px] uppercase tracking-wider ui-text-subtle mb-1">Branch at</label>
                    <select x-model.number="branchForm.t_index" class="ui-input w-full">
                        <template x-for="frame in state.frames" :key="frame.id">
                            <option :value="frame.t_index" x-text="frame.label"></option>
                        </template>
                    </select>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="branchModalOpen = false" class="ui-btn ui-btn-ghost ui-btn-sm">Cancel</button>
                    <button type="button" @click="submitBranch()" :disabled="!branchForm.name || busy" class="ui-btn ui-btn-primary ui-btn-sm">
                        Create branch
                    </button>
                </div>
            </div>
        </div>

    </div>

</x-app-layout>
