{{--
    System → Backtracking
    Admin-only ladder backtester. Three buttons:
      • Fetch Candles    → Binance Vision (bulk) + TAAPI (recency top-up)
      • Verify Coverage  → counts, holes, contiguity %, staleness
      • Backtest         → runs BacktestSimulator and renders outcome totals + row table

    Defaults pre-filled from Account #1 (TP, SL, leverage) and the selected
    ExchangeSymbol (gap long/short, multipliers, total_limit_orders). Tune
    the three load-bearing knobs (TP %, gap %, SL %) in the form, re-run,
    compare non-rebound / stopped-out rates, land on per-token config.
--}}
<x-app-layout :activeSection="'system'" :activeHighlight="'backtracking'" :flush="true">
    <div class="flex flex-col h-full"
         x-data="backtracking(@js($symbols), @js($defaults), @js($timeframes))"
         x-init="init()">

        <x-hub-ui::live-header
            title="Backtracking"
            description="Ladder backtest over historical candles — tune TP / gap / SL per token to find survivable config."
        >
            <x-slot:actions>
                <x-hub-ui::badge type="warning" size="sm" :dot="true">Admin</x-hub-ui::badge>
            </x-slot:actions>
        </x-hub-ui::live-header>

        <div class="flex-1 overflow-auto p-4 sm:p-6 space-y-4 sm:space-y-6">

            {{-- Form card --}}
            <x-hub-ui::card title="Configuration" subtitle="Ladder, rebound and filter knobs — tune per token, re-run.">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                    <x-hub-ui::select name="exchange_symbol_id" label="Token (grouped by quote)" placeholder="— select —"
                                       x-model="form.exchange_symbol_id" @change="onSymbolChange()">
                        <option value="">— select —</option>
                        <template x-for="(group, quote) in symbols" :key="quote">
                            <optgroup :label="quote">
                                <template x-for="sym in group" :key="sym.id">
                                    <option :value="sym.id" x-text="sym.label"></option>
                                </template>
                            </optgroup>
                        </template>
                    </x-hub-ui::select>

                    <x-hub-ui::select name="timeframe" label="Timeframe" :placeholder="null" x-model="form.timeframe">
                        <template x-for="tf in timeframes" :key="tf">
                            <option :value="tf" x-text="tf"></option>
                        </template>
                    </x-hub-ui::select>

                    <x-hub-ui::input name="margin" label="Margin (quote)" type="number" step="0.01" x-model="form.margin" />

                    <x-hub-ui::input name="leverage" label="Leverage" type="number" step="1" x-model.number="form.leverage" />

                    <x-hub-ui::input name="total_limit_orders" label="Total Limit Orders (N)" type="number" step="1"
                                      x-model.number="form.total_limit_orders" />

                    <x-hub-ui::input name="multipliers" label="Multipliers (comma)" placeholder="e.g. 2,2,2,2"
                                      x-model="form.multipliers" />

                    <x-hub-ui::input name="tp_percent" label="TP % (rebound knob)" type="number" step="0.01"
                                      x-model="form.tp_percent" />

                    <x-hub-ui::input name="sl_percent" label="SL % (rebound knob)" type="number" step="0.01"
                                      x-model="form.sl_percent" />

                    <x-hub-ui::input name="gap_long_percent" label="Gap LONG % (rebound knob)" type="number" step="0.01"
                                      x-model="form.gap_long_percent" />

                    <x-hub-ui::input name="gap_short_percent" label="Gap SHORT % (rebound knob)" type="number" step="0.01"
                                      x-model="form.gap_short_percent" />

                    <x-hub-ui::input name="days_to_ignore" label="Days to Ignore (display)" type="number" step="1"
                                      x-model.number="form.days_to_ignore" />

                    <x-hub-ui::input name="candle" label="Specific Candle" placeholder="YYYY-MM-DD HH:MM"
                                      x-model="form.candle" />

                    <x-hub-ui::input name="candles_back" label="Candles back"
                                      type="number" step="1" placeholder="leave empty = all history"
                                      hint="Applies to fetch AND backtest window."
                                      x-model.number="form.candles_back" />

                    <x-hub-ui::input name="since" label="Since date"
                                      placeholder="YYYY-MM-DD"
                                      hint="Overrides “candles back” when filled."
                                      x-model="form.since" />
                </div>

                <div class="mt-5 flex flex-wrap items-center gap-x-6 gap-y-3">
                    <x-hub-ui::checkbox name="skip_stop_loss" label="Skip SL evaluation" x-model="form.skip_stop_loss" />
                </div>

                <p class="mt-5 text-[11px] ui-text-subtle italic">
                    Start candles inside the last <span class="font-semibold">15 days</span> are still simulated — rebounds, TP-market-only and stop-outs are counted as usual. Only their <span class="font-semibold">non-reboundable</span> verdicts get dropped, since "no rebound yet" inside that buffer just means the walker ran out of data.
                </p>

                <div class="mt-3 flex flex-wrap gap-2">
                    <x-hub-ui::button variant="primary" size="sm"
                                       @click="runBacktest()"
                                       x-bind:disabled="!form.exchange_symbol_id || busy">
                        <span x-show="!busy">Backtest</span>
                        <span x-show="busy" class="inline-flex items-center gap-1.5">
                            <x-hub-ui::spinner size="sm" /> <span x-text="busyLabel"></span>
                        </span>
                    </x-hub-ui::button>
                </div>

                <div x-show="statusMessage" class="mt-4">
                    <div x-show="!statusError">
                        <x-hub-ui::alert type="info">
                            <span x-text="statusMessage"></span>
                        </x-hub-ui::alert>
                    </div>
                    <div x-show="statusError">
                        <x-hub-ui::alert type="error">
                            <span x-text="statusMessage"></span>
                        </x-hub-ui::alert>
                    </div>
                </div>
            </x-hub-ui::card>

            {{-- Coverage panel --}}
            <div x-show="coverage" class="ui-card p-4 sm:p-5 ui-selectable">
                <h2 class="text-sm font-semibold ui-text mb-3">Coverage</h2>

                <div x-show="(coverage?.holes_count ?? 0) > 0" class="mb-3 p-3 rounded bg-amber-50 text-amber-900 text-xs">
                    ⚠ <span class="font-semibold"><span x-text="coverage?.holes_count ?? 0"></span> hole(s)</span> remain after Vision + Binance REST + TAAPI. The simulator will still run but skips over missing candles — results may under-count outcomes for those ranges.
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-3 text-xs">
                    <div><span class="ui-text-muted">Present</span><div class="font-mono" x-text="coverage?.total_present ?? '—'"></div></div>
                    <div><span class="ui-text-muted">Earliest</span><div class="font-mono text-[10px]" x-text="coverage?.earliest ?? '—'"></div></div>
                    <div><span class="ui-text-muted">Latest</span><div class="font-mono text-[10px]" x-text="coverage?.latest ?? '—'"></div></div>
                    <div><span class="ui-text-muted">Contiguity</span><div class="font-mono"><span x-text="coverage?.contiguity_percent ?? '—'"></span>%</div></div>
                    <div><span class="ui-text-muted">Holes</span><div class="font-mono" x-text="coverage?.holes_count ?? '—'"></div></div>
                    <div><span class="ui-text-muted">Fresh</span><div class="font-mono" x-text="coverage?.is_fresh ? 'Yes' : 'No'"></div></div>
                </div>
                <div x-show="coverage?.holes_sample?.length" class="mt-3">
                    <span class="ui-text-muted text-xs">Hole runs (first 25):</span>
                    <ul class="text-[11px] font-mono mt-1 space-y-0.5 max-h-40 overflow-auto">
                        <template x-for="(h, i) in coverage?.holes_sample ?? []" :key="i">
                            <li><span x-text="h.from"></span> → <span x-text="h.to"></span> (missing <span x-text="h.missing"></span>)</li>
                        </template>
                    </ul>
                </div>
            </div>

            {{-- Backtest result panel --}}
            <div x-show="result" class="ui-card p-4 sm:p-5 ui-selectable">
                <div class="flex items-baseline justify-between gap-2 mb-3">
                    <h2 class="text-sm font-semibold ui-text">
                        Backtest Result — <span x-text="resultPair"></span>
                    </h2>
                    <button type="button"
                            @click="copyResultSummary()"
                            class="text-[11px] font-medium ui-text-muted hover:ui-text underline-offset-2 hover:underline">
                        <span x-show="!resultCopied">Copy full result</span>
                        <span x-show="resultCopied">Copied ✓</span>
                    </button>
                </div>

                {{-- Hero — single comparable score + grade + verdict --}}
                <div x-show="result?.totals?.overall_score !== null && result?.totals?.overall_score !== undefined"
                     class="mb-4 p-4 rounded border"
                     :class="gradeClass(result?.totals?.grade)">
                    <div class="flex items-center gap-4">
                        <div class="text-5xl font-bold font-mono leading-none" x-text="result?.totals?.grade ?? '—'"></div>
                        <div class="flex-1">
                            <div class="flex items-baseline gap-2">
                                <span class="text-xs uppercase tracking-wider font-semibold opacity-70">Overall score</span>
                                <span class="font-mono font-bold text-2xl"><span x-text="result?.totals?.overall_score ?? 0"></span> / 100</span>
                            </div>
                            <div class="text-xs italic opacity-80 mt-1" x-text="result?.totals?.verdict ?? ''"></div>
                            <div class="text-[10px] opacity-60 mt-1 italic">
                                Single comparable score across tokens (same timeframe + window). Higher = better. A ≥90, B ≥80, C ≥70, D ≥60, F &lt;60.
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-5 gap-3 text-xs mb-4">
                    <div class="p-2 rounded bg-gray-50 text-gray-900">
                        <div class="text-gray-600">Candles analysed</div>
                        <div class="font-mono font-semibold text-gray-900" x-text="result?.totals?.candles ?? 0"></div>
                        <div class="text-[10px] text-gray-500 italic mt-0.5">Bigger sample — more reliable.</div>
                    </div>
                    <div class="p-2 rounded bg-emerald-50 text-emerald-900">
                        <div class="text-emerald-700">TP from market-only</div>
                        <div class="font-mono font-semibold text-emerald-900"><span x-text="result?.totals?.tp_market_only ?? 0"></span> (<span x-text="pct('tp_market_only')"></span>%)</div>
                        <div class="text-[10px] text-emerald-700/70 italic mt-0.5">Closed at profit without any limit. Higher = better.</div>
                    </div>
                    <div class="p-2 rounded bg-emerald-50 text-emerald-900">
                        <div class="text-emerald-700">Reboundable</div>
                        <div class="font-mono font-semibold text-emerald-900"><span x-text="result?.totals?.reboundable ?? 0"></span> (<span x-text="pct('reboundable')"></span>%)</div>
                        <div class="text-[10px] text-emerald-700/70 italic mt-0.5">Ladder averaged down, then recovered. Higher = ladder works.</div>
                    </div>
                    <div class="p-2 rounded bg-red-50 text-red-900">
                        <div class="text-red-700">Stopped out</div>
                        <div class="font-mono font-semibold text-red-900"><span x-text="result?.totals?.stops ?? 0"></span> (<span x-text="pct('stops')"></span>%)</div>
                        <div class="text-[10px] text-red-700/70 italic mt-0.5">Real losses. Lower = safer.</div>
                    </div>
                    <div class="p-2 rounded bg-amber-50 text-amber-900">
                        <div class="text-amber-700">Non-reboundable</div>
                        <div class="font-mono font-semibold text-amber-900"><span x-text="result?.totals?.non_reboundable ?? 0"></span> (<span x-text="pct('non_reboundable')"></span>%)</div>
                        <div class="text-[10px] text-amber-700/70 italic mt-0.5">Never recovered within window. Lower = safer.</div>
                    </div>
                </div>

                <div x-show="(result?.totals?.dropped_inconclusive ?? 0) > 0" class="text-[11px] ui-text-subtle italic mb-2">
                    <span x-text="result?.totals?.dropped_inconclusive ?? 0"></span> sim(s) inside the 15-day buffer dropped as inconclusive (non-reboundable verdict without enough forward data).
                </div>

                {{-- AI Insights --}}
                <div class="mb-4 flex flex-wrap items-center gap-2">
                    <x-hub-ui::button variant="secondary" size="sm"
                                       @click="fetchAiInsights()"
                                       x-bind:disabled="aiBusy || !result">
                        <span x-show="!aiBusy" class="inline-flex items-center gap-1.5">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                            </svg>
                            AI Insights
                        </span>
                        <span x-show="aiBusy" class="inline-flex items-center gap-1.5">
                            <x-hub-ui::spinner size="sm" /> Thinking…
                        </span>
                    </x-hub-ui::button>
                    <span class="text-[10px] ui-text-subtle italic">
                        Sends this result to Claude Haiku for config tuning suggestions. Advisory only — nothing writes back to the symbol.
                    </span>
                </div>

                <div x-show="aiInsights" class="mb-4 p-4 rounded bg-emerald-50 border border-emerald-200 text-emerald-900 ui-selectable">
                    <div class="flex items-baseline justify-between mb-2 gap-2">
                        <span class="text-xs font-semibold uppercase tracking-wider opacity-70">AI Insights</span>
                        <div class="flex items-center gap-3">
                            <span class="text-[10px] font-mono opacity-60" x-text="aiInsightsModel"></span>
                            <button type="button"
                                    @click="copyAiInsights()"
                                    class="text-[11px] font-medium underline-offset-2 hover:underline opacity-80 hover:opacity-100">
                                <span x-show="!aiCopied">Copy</span>
                                <span x-show="aiCopied">Copied ✓</span>
                            </button>
                        </div>
                    </div>
                    <div class="text-xs leading-relaxed whitespace-pre-wrap font-sans select-text" x-text="aiInsights"></div>
                </div>

                <div x-show="aiError" class="mb-4 p-3 rounded bg-red-50 border border-red-200 text-red-900 text-xs">
                    <span class="font-semibold">AI Insights error:</span> <span x-text="aiError"></span>
                </div>

                {{-- Risk score + stability stats --}}
                <div class="grid grid-cols-2 md:grid-cols-5 gap-3 text-xs mb-4">
                    <div class="p-2 rounded" :class="metricTileClass('risk_score')">
                        <div>Risk score</div>
                        <div class="font-mono font-semibold" x-text="result?.totals?.risk_score ?? '—'"></div>
                        <div class="text-[10px] italic mt-0.5 opacity-70">Composite = stops%×3 + non-reb%×2 + avg rung. Lower = safer.</div>
                    </div>
                    <div class="p-2 rounded" :class="metricTileClass('rung_depth')">
                        <div>Avg rung depth / N</div>
                        <div class="font-mono font-semibold">
                            <span x-text="result?.totals?.avg_rung_depth ?? '—'"></span>
                            <span class="opacity-60"> / <span x-text="result?.meta?.total_limit_orders ?? '—'"></span></span>
                        </div>
                        <div class="text-[10px] italic mt-0.5 opacity-70">How deep the ladder typically goes. Lower = less capital at risk.</div>
                    </div>
                    <div class="p-2 rounded" :class="metricTileClass('max_mae')">
                        <div>Avg / Max MAE %</div>
                        <div class="font-mono font-semibold">
                            <span x-text="result?.totals?.avg_mae_pct ?? '—'"></span>
                            <span class="opacity-60"> / <span x-text="result?.totals?.max_mae_pct ?? '—'"></span></span>
                        </div>
                        <div class="text-[10px] italic mt-0.5 opacity-70">Max drawdown vs entry. Lower = safer (watch Max for liquidation risk).</div>
                    </div>
                    <div class="p-2 rounded" :class="metricTileClass('avg_ctp')">
                        <div>Avg / p95 candles to profit</div>
                        <div class="font-mono font-semibold">
                            <span x-text="result?.totals?.avg_candles_to_profit ?? '—'"></span>
                            <span class="opacity-60"> / <span x-text="result?.totals?.p95_candles_to_profit ?? '—'"></span></span>
                        </div>
                        <div class="text-[10px] italic mt-0.5 opacity-70">How fast trades resolve. Lower = faster capital rotation.</div>
                    </div>
                    <div class="p-2 rounded" :class="metricTileClass('worst_bucket')">
                        <div>Worst-bucket pass rate</div>
                        <div class="font-mono font-semibold">
                            <span x-text="result?.totals?.worst_bucket_pass_rate ?? '—'"></span>%
                        </div>
                        <div class="text-[10px] italic mt-0.5 opacity-70">Lowest pass rate across regime chunks. Higher = stable over time.</div>
                    </div>
                </div>

                {{-- Rung depth distribution --}}
                <div x-show="Object.keys(result?.totals?.rung_distribution ?? {}).length" class="mb-4">
                    <div class="text-xs ui-text-muted mb-1">Rung depth distribution — where simulations terminated</div>
                    <div class="overflow-auto border rounded bg-white text-gray-900">
                        <table class="min-w-full text-[11px] font-mono text-gray-900">
                            <thead class="bg-gray-100 text-gray-900">
                                <tr>
                                    <th class="text-left px-2 py-1">rung</th>
                                    <th class="text-left px-2 py-1">count</th>
                                    <th class="text-left px-2 py-1 w-full">share</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(count, rung) in result?.totals?.rung_distribution ?? {}" :key="rung">
                                    <tr class="border-t">
                                        <td class="px-2 py-0.5" x-text="rung"></td>
                                        <td class="px-2 py-0.5" x-text="count"></td>
                                        <td class="px-2 py-0.5">
                                            <div class="h-2 bg-emerald-400 rounded"
                                                 :style="`width: ${rungBarPct(count)}%`"></div>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- Regime buckets --}}
                <div x-show="(result?.regimes ?? []).length" class="mb-4">
                    <div class="text-xs ui-text-muted mb-1">Regime stability — window sliced into equal time chunks</div>
                    <div class="overflow-auto border rounded bg-white text-gray-900">
                        <table class="min-w-full text-[11px] font-mono text-gray-900">
                            <thead class="bg-gray-100 text-gray-900">
                                <tr>
                                    <th class="text-left px-2 py-1">from</th>
                                    <th class="text-left px-2 py-1">to</th>
                                    <th class="text-left px-2 py-1">candles</th>
                                    <th class="text-left px-2 py-1">pass</th>
                                    <th class="text-left px-2 py-1">stops</th>
                                    <th class="text-left px-2 py-1">non-reb</th>
                                    <th class="text-left px-2 py-1">pass rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(r, i) in result?.regimes ?? []" :key="i">
                                    <tr class="border-t" :class="r.pass_rate < 80 ? 'bg-red-50' : (r.pass_rate < 95 ? 'bg-amber-50' : 'bg-emerald-50')">
                                        <td class="px-2 py-0.5" x-text="r.from"></td>
                                        <td class="px-2 py-0.5" x-text="r.to"></td>
                                        <td class="px-2 py-0.5" x-text="r.candles"></td>
                                        <td class="px-2 py-0.5" x-text="(r.tp_market_only + r.reboundable)"></td>
                                        <td class="px-2 py-0.5" x-text="r.stops"></td>
                                        <td class="px-2 py-0.5" x-text="r.non_reboundable"></td>
                                        <td class="px-2 py-0.5"><span x-text="r.pass_rate"></span>%</td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div x-show="rowsTruncated" class="text-[11px] text-amber-700 mb-2">
                    ⚠ Rows truncated to 500. Use the <code>kraite:backtest-token</code> CLI for full rows.
                </div>

                <div x-show="result?.rows?.length" class="flex flex-wrap items-center gap-4 mb-2 text-xs">
                    <label class="inline-flex items-center gap-1.5 cursor-pointer">
                        <input type="checkbox" x-model="filterOnlySlHits" class="rounded">
                        <span class="ui-text-muted">Only SL hits</span>
                    </label>
                    <label class="inline-flex items-center gap-1.5 cursor-pointer">
                        <input type="checkbox" x-model="filterOnlyNonRebounds" class="rounded">
                        <span class="ui-text-muted">Only non-rebounds</span>
                    </label>
                    <span class="ui-text-subtle italic">
                        Showing <span x-text="visibleRows().length"></span> of <span x-text="result?.rows?.length ?? 0"></span>
                    </span>
                </div>

                <div x-show="visibleRows().length" class="overflow-auto max-h-[500px] border rounded bg-white text-gray-900">
                    <table class="min-w-full text-[11px] font-mono text-gray-900">
                        <thead class="bg-gray-100 text-gray-900 sticky top-0">
                            <tr>
                                <th class="text-left px-2 py-1">direction</th>
                                <th class="text-left px-2 py-1">start</th>
                                <th class="text-left px-2 py-1">entry</th>
                                <th class="text-left px-2 py-1">rung</th>
                                <th class="text-left px-2 py-1">last_touch</th>
                                <th class="text-left px-2 py-1">tp</th>
                                <th class="text-left px-2 py-1">tp_hit</th>
                                <th class="text-left px-2 py-1">bars</th>
                                <th class="text-left px-2 py-1">status</th>
                                <th class="text-left px-2 py-1">note</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-900">
                            <template x-for="(row, i) in visibleRows()" :key="i">
                                <tr class="border-t text-gray-900" :class="rowClass(row.status)">
                                    <td class="px-2 py-0.5" x-text="row.direction"></td>
                                    <td class="px-2 py-0.5" x-text="row.start_candle"></td>
                                    <td class="px-2 py-0.5" x-text="row.entry_ref_price"></td>
                                    <td class="px-2 py-0.5" x-text="row.last_rung"></td>
                                    <td class="px-2 py-0.5" x-text="row.last_touch_candle ?? '—'"></td>
                                    <td class="px-2 py-0.5" x-text="row.tp_price"></td>
                                    <td class="px-2 py-0.5" x-text="row.tp_hit_candle ?? '—'"></td>
                                    <td class="px-2 py-0.5" x-text="row.candles_to_profit ?? '—'"></td>
                                    <td class="px-2 py-0.5" x-text="row.status"></td>
                                    <td class="px-2 py-0.5 whitespace-normal" x-text="row.message"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <div x-show="result?.rows?.length && !visibleRows().length" class="text-xs ui-text-muted italic">
                    No rows match the current filters — uncheck a box or widen the criteria.
                </div>

                <div x-show="!result?.rows?.length" class="text-xs ui-text-muted italic">
                    No problems found — every sim either rebounded or hit TP from the market order. This config is clean for the window.
                </div>
            </div>

        </div>
    </div>

    <script>
    function backtracking(symbols, defaults, timeframes) {
        return {
            symbols: symbols,
            timeframes: timeframes,

            form: {
                exchange_symbol_id: '',
                timeframe: '1d',
                margin: defaults.margin,
                leverage: defaults.leverage,
                total_limit_orders: defaults.total_limit_orders,
                multipliers: '',
                tp_percent: defaults.tp_percent,
                gap_long_percent: '',
                gap_short_percent: '',
                sl_percent: defaults.sl_percent,
                skip_stop_loss: false,
                days_to_ignore: defaults.days_to_ignore,
                limit_hit: '',
                candle: '',
                candles_back: 365,
                since: '',
            },

            coverage: null,
            result: null,
            resultPair: '',
            rowsTruncated: false,
            filterOnlySlHits: false,
            filterOnlyNonRebounds: false,
            statusMessage: '',
            statusError: false,

            busy: false,
            busyLabel: 'Running…',
            aiBusy: false,
            aiInsights: '',
            aiInsightsModel: '',
            aiError: '',
            aiCopied: false,
            resultCopied: false,

            init() {
                // Alpine's x-model on a <select> can't bind to <template x-for>
                // options at initial mount because the options don't exist yet.
                // Re-assert the default after the next tick — by then the
                // template has rendered all <option>s and the model can sync.
                this.$nextTick(() => {
                    this.form.timeframe = this.form.timeframe || '1d';
                });
            },

            onSymbolChange() {
                // Hard reset to defaults — picking a new token clears every prior
                // override so a previous token's tuning never leaks into the next
                // test. Then layer the symbol's stored values where present.
                const symId = this.form.exchange_symbol_id;
                this.form.margin = defaults.margin;
                this.form.leverage = defaults.leverage;
                this.form.total_limit_orders = defaults.total_limit_orders;
                this.form.multipliers = '';
                this.form.tp_percent = defaults.tp_percent;
                this.form.gap_long_percent = '';
                this.form.gap_short_percent = '';
                this.form.sl_percent = defaults.sl_percent;
                this.form.skip_stop_loss = false;
                this.form.days_to_ignore = defaults.days_to_ignore;
                this.form.limit_hit = '';
                this.form.candle = '';
                this.form.candles_back = 365;
                this.form.since = '';
                this.form.timeframe = '1d';

                this.coverage = null;
                this.result = null;
                this.statusMessage = '';

                if (!symId) return;
                const sym = this.findSymbol(symId);
                if (!sym) return;

                if (sym.percentage_gap_long != null) this.form.gap_long_percent = sym.percentage_gap_long;
                if (sym.percentage_gap_short != null) this.form.gap_short_percent = sym.percentage_gap_short;
                if (sym.total_limit_orders) this.form.total_limit_orders = parseInt(sym.total_limit_orders, 10);
                if (sym.limit_quantity_multipliers) {
                    try {
                        const m = typeof sym.limit_quantity_multipliers === 'string'
                            ? JSON.parse(sym.limit_quantity_multipliers)
                            : sym.limit_quantity_multipliers;
                        if (Array.isArray(m)) this.form.multipliers = m.join(',');
                    } catch (e) { /* ignore */ }
                }
            },

            findSymbol(id) {
                for (const [exchange, group] of Object.entries(this.symbols)) {
                    for (const s of group) {
                        if (String(s.id) === String(id)) return s;
                    }
                }
                return null;
            },

            pct(key) {
                if (!this.result?.totals) return '0.00';
                const sims = (this.result.totals.candles ?? 0) * 2;
                if (!sims) return '0.00';
                return ((this.result.totals[key] ?? 0) / sims * 100).toFixed(2);
            },

            visibleRows() {
                const rows = this.result?.rows ?? [];
                if (!this.filterOnlySlHits && !this.filterOnlyNonRebounds) return rows;

                const allowed = new Set();
                if (this.filterOnlySlHits) allowed.add('stopped_out');
                if (this.filterOnlyNonRebounds) allowed.add('non-reboundable');

                return rows.filter(r => allowed.has(r.status));
            },

            buildResultSummary() {
                if (!this.result) return '';
                const t = this.result.totals ?? {};
                const m = this.result.meta ?? {};
                const sims = (t.candles ?? 0) * 2;
                const line = (k, v) => `${k}: ${v ?? '—'}`;
                const pctFmt = k => {
                    if (!sims) return '0.00';
                    return (((t[k] ?? 0) / sims) * 100).toFixed(2);
                };

                const parts = [];
                parts.push(`Backtest Result — ${this.resultPair}`);
                parts.push(`Timeframe: ${m.timeframe ?? '—'}`);
                parts.push(`Window since: ${m.window_since ?? 'full history'}`);
                parts.push(`Config tested: tp=${m.tp_percent ?? '—'}% sl=${m.sl_percent ?? '—'}% gap_long=${m.gap_long_percent ?? '—'} gap_short=${m.gap_short_percent ?? '—'} N=${m.total_limit_orders ?? '—'} multipliers=[${(m.multipliers ?? []).join(',')}] leverage=${m.leverage ?? '—'}x margin=${m.margin ?? '—'}`);
                parts.push('');
                parts.push(`OVERALL SCORE: ${t.overall_score ?? '—'} / 100  (Grade ${t.grade ?? '—'})`);
                parts.push(`Verdict: ${t.verdict ?? '—'}`);
                parts.push('');
                parts.push('OUTCOMES');
                parts.push(line('  Candles analysed', t.candles ?? 0));
                parts.push(`  TP from market-only: ${t.tp_market_only ?? 0} (${pctFmt('tp_market_only')}%)`);
                parts.push(`  Reboundable: ${t.reboundable ?? 0} (${pctFmt('reboundable')}%)`);
                parts.push(`  Stopped out: ${t.stops ?? 0} (${pctFmt('stops')}%)`);
                parts.push(`  Non-reboundable: ${t.non_reboundable ?? 0} (${pctFmt('non_reboundable')}%)`);
                if (t.dropped_inconclusive) {
                    parts.push(`  Dropped (inside 15-day buffer): ${t.dropped_inconclusive}`);
                }
                parts.push('');
                parts.push('ANALYTICS');
                parts.push(`  Risk score: ${t.risk_score ?? '—'}`);
                parts.push(`  Avg rung depth: ${t.avg_rung_depth ?? '—'} / ${m.total_limit_orders ?? '—'}`);
                parts.push(`  Avg MAE %: ${t.avg_mae_pct ?? '—'}`);
                parts.push(`  Max MAE %: ${t.max_mae_pct ?? '—'}`);
                parts.push(`  Avg candles to profit: ${t.avg_candles_to_profit ?? '—'}`);
                parts.push(`  p95 candles to profit: ${t.p95_candles_to_profit ?? '—'}`);
                parts.push(`  Worst-bucket pass rate: ${t.worst_bucket_pass_rate ?? '—'}%`);
                parts.push('');
                parts.push('RUNG DISTRIBUTION (where sims terminated)');
                const dist = t.rung_distribution ?? {};
                for (const [rung, count] of Object.entries(dist)) {
                    parts.push(`  rung ${rung}: ${count}`);
                }
                parts.push('');
                parts.push('REGIME BUCKETS');
                const regimes = this.result.regimes ?? [];
                if (regimes.length) {
                    parts.push('  from                  to                    candles  pass  stops  non-reb  pass_rate');
                    for (const r of regimes) {
                        parts.push(`  ${(r.from ?? '').padEnd(20)}  ${(r.to ?? '').padEnd(20)}  ${String(r.candles).padStart(7)}  ${String((r.tp_market_only ?? 0) + (r.reboundable ?? 0)).padStart(4)}  ${String(r.stops ?? 0).padStart(5)}  ${String(r.non_reboundable ?? 0).padStart(7)}  ${r.pass_rate}%`);
                    }
                } else {
                    parts.push('  (no buckets)');
                }
                const rows = this.result.rows ?? [];
                if (rows.length) {
                    parts.push('');
                    parts.push(`FAILURE ROWS (${rows.length})`);
                    for (const r of rows) {
                        parts.push(`  ${r.direction} start=${r.start_candle} entry=${r.entry_ref_price} last_rung=${r.last_rung} status=${r.status}${r.tp_hit_candle ? ` tp_hit=${r.tp_hit_candle}` : ''} mae_pct=${r.mae_pct ?? '—'}`);
                    }
                }
                return parts.join('\n');
            },

            async copyResultSummary() {
                const text = this.buildResultSummary();
                if (!text) return;
                try {
                    await navigator.clipboard.writeText(text);
                } catch (_) {
                    const ta = document.createElement('textarea');
                    ta.value = text;
                    ta.style.position = 'fixed';
                    ta.style.opacity = '0';
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    document.body.removeChild(ta);
                }
                this.resultCopied = true;
                setTimeout(() => { this.resultCopied = false; }, 2000);
            },

            async copyAiInsights() {
                if (!this.aiInsights) return;
                try {
                    await navigator.clipboard.writeText(this.aiInsights);
                } catch (_) {
                    // Fallback for contexts without clipboard API (http pages, etc.)
                    const ta = document.createElement('textarea');
                    ta.value = this.aiInsights;
                    ta.style.position = 'fixed';
                    ta.style.opacity = '0';
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    document.body.removeChild(ta);
                }
                this.aiCopied = true;
                setTimeout(() => { this.aiCopied = false; }, 2000);
            },

            async fetchAiInsights() {
                if (!this.result) return;

                this.aiBusy = true;
                this.aiInsights = '';
                this.aiError = '';
                this.aiInsightsModel = '';

                try {
                    const config = Object.fromEntries(
                        Object.entries(this.form).filter(([_, v]) => v !== '' && v !== null)
                    );
                    const res = await this.post('{{ route('system.backtracking.ai-insights') }}', {
                        exchange_symbol_id: this.form.exchange_symbol_id,
                        timeframe: this.form.timeframe,
                        totals: this.result.totals ?? {},
                        regimes: this.result.regimes ?? [],
                        meta: this.result.meta ?? {},
                        config: config,
                        rows: this.result.rows ?? [],
                    });
                    if (res.ok) {
                        this.aiInsights = res.insights ?? '';
                        this.aiInsightsModel = res.model ?? '';
                    } else {
                        this.aiError = res.error ?? 'Unknown error.';
                    }
                } catch (e) {
                    this.aiError = e.message;
                } finally {
                    this.aiBusy = false;
                }
            },

            metricTileClass(metric) {
                const t = this.result?.totals ?? {};
                const n = this.result?.meta?.total_limit_orders ?? 4;
                const green = 'bg-emerald-50 text-emerald-900';
                const amber = 'bg-amber-50 text-amber-900';
                const red   = 'bg-red-50 text-red-900';
                const band = (v, goodMax, okMax) => v == null ? 'bg-gray-50 text-gray-900'
                    : (v <= goodMax ? green : (v <= okMax ? amber : red));
                const bandHigh = (v, goodMin, okMin) => v == null ? 'bg-gray-50 text-gray-900'
                    : (v >= goodMin ? green : (v >= okMin ? amber : red));

                switch (metric) {
                    case 'risk_score':    return band(t.risk_score, 3, 10);
                    case 'rung_depth':    {
                        const ratio = (t.avg_rung_depth ?? 0) / Math.max(1, n);
                        return band(ratio, 0.25, 0.5);
                    }
                    case 'max_mae':       return band(t.max_mae_pct, 10, 50);
                    case 'avg_ctp':       return band(t.avg_candles_to_profit, 2, 5);
                    case 'worst_bucket':  return bandHigh(t.worst_bucket_pass_rate, 95, 85);
                    default:              return 'bg-gray-50 text-gray-900';
                }
            },

            gradeClass(grade) {
                switch (grade) {
                    case 'A': return 'bg-emerald-50 border-emerald-200 text-emerald-900';
                    case 'B': return 'bg-emerald-50 border-emerald-200 text-emerald-900';
                    case 'C': return 'bg-amber-50 border-amber-200 text-amber-900';
                    case 'D': return 'bg-amber-50 border-amber-300 text-amber-900';
                    case 'F': return 'bg-red-50 border-red-200 text-red-900';
                    default:  return 'bg-gray-50 border-gray-200 text-gray-900';
                }
            },

            rungBarPct(count) {
                const dist = this.result?.totals?.rung_distribution ?? {};
                const values = Object.values(dist);
                const max = values.length ? Math.max(...values) : 0;
                return max > 0 ? Math.round((count / max) * 100) : 0;
            },

            rowClass(status) {
                if (status === 'tp_hit_from_market_only' || status === 'reboundable') return 'bg-emerald-50';
                if (status === 'stopped_out') return 'bg-red-50';
                if (status === 'non-reboundable') return 'bg-amber-50';
                return 'bg-gray-50';
            },

            async runBacktest() {
                this.busy = true;
                this.statusError = false;

                try {
                    // Phase 1 — ensure DB has what the sim needs. Fetch is
                    // idempotent (Vision skips already-covered months, Binance
                    // REST skips when current, TAAPI skips when latest candle
                    // is already present) so running it unconditionally costs
                    // ~2-3s when warm and avoids a stale-data backtest.
                    this.busyLabel = 'Fetching candles…';
                    this.statusMessage = 'Fetching candles (Vision → Binance REST → TAAPI)…';

                    const fetchPayload = {
                        exchange_symbol_id: this.form.exchange_symbol_id,
                        timeframe: this.form.timeframe,
                        taapi_topup: true,
                    };
                    if (this.form.candles_back !== '' && this.form.candles_back !== null) {
                        fetchPayload.candles_back = this.form.candles_back;
                    }
                    if (this.form.since && this.form.since.trim() !== '') {
                        fetchPayload.since = this.form.since.trim();
                    }

                    const fetchRes = await this.post('{{ route('system.backtracking.fetch-candles') }}', fetchPayload);
                    if (!fetchRes.ok) {
                        this.statusMessage = 'Fetch failed: ' + (fetchRes.error ?? 'unknown');
                        this.statusError = true;
                        return;
                    }
                    if (fetchRes.coverage) this.coverage = fetchRes.coverage;

                    // Phase 2 — actual simulation.
                    this.busyLabel = 'Running backtest…';
                    this.statusMessage = 'Running backtest — this may take up to a minute…';

                    const runPayload = Object.fromEntries(
                        Object.entries(this.form)
                            .filter(([_, v]) => v !== '' && v !== null)
                    );
                    const runRes = await this.post('{{ route('system.backtracking.run') }}', runPayload);

                    if (runRes.ok) {
                        this.result = runRes.result;
                        this.resultPair = runRes.pair;
                        this.rowsTruncated = runRes.rows_truncated;
                        this.statusMessage = `Backtest complete — ${runRes.result.totals.candles} candles analysed. ${fetchRes.message}`;
                    } else {
                        this.statusMessage = 'Backtest failed: ' + (runRes.error ?? 'unknown');
                        this.statusError = true;
                    }
                } catch (e) {
                    this.statusMessage = 'Error: ' + e.message;
                    this.statusError = true;
                } finally {
                    this.busy = false;
                    this.busyLabel = 'Running…';
                }
            },

            async post(url, body) {
                const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                const res = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': token,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify(body),
                });
                return await res.json();
            },
        };
    }
    </script>
</x-app-layout>
