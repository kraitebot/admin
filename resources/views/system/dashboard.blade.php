<x-app-layout :activeSection="'system'" :activeHighlight="'system-dashboard'">

    <div class="max-w-[1600px] mx-auto" x-data="{ ...heartbeat(), ...adminDashboard() }" x-init="fetchData(); fetchHealth(); startPolling()">

        <div class="grid grid-cols-1 xl:grid-cols-[1fr_300px] gap-5">

            {{-- ═══════════════════════════════════════════════════════════
                 MAIN COLUMN — KPI Strip, Step Dispatchers, Exchanges
                 ═══════════════════════════════════════════════════════════ --}}
            <div class="flex flex-col gap-5 min-w-0">

                {{-- Cooldown chips (mobile only — xl+ in sidebar) --}}
                <div class="xl:hidden grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <template x-for="fleet in cooldownFleets" :key="fleet.slug + '-mobile'">
                        <div
                            class="ui-card flex items-center justify-between gap-3 px-4 py-3"
                            :style="cooldown[fleet.slug]?.is_paused ? 'border-color: rgb(var(--ui-warning) / 0.5); background-color: rgb(var(--ui-warning) / 0.08)' : ''"
                        >
                            <div class="flex items-center gap-2 min-w-0">
                                <template x-if="cooldown[fleet.slug]?.is_paused">
                                    <x-hub-ui::pulse-dot type="warning" :pulse="true" size="sm" />
                                </template>
                                <template x-if="!cooldown[fleet.slug]?.is_paused">
                                    <x-hub-ui::pulse-dot type="success" size="sm" />
                                </template>
                                <span class="text-xs font-semibold ui-text" x-text="fleet.label"></span>
                            </div>
                            <x-hub-ui::switch
                                state="cooldown[fleet.slug]?.is_paused"
                                @click="toggleCoolingDown(fleet.slug)"
                                onColor="warning"
                                size="sm"
                                x-bind:class="togglingCoolingDown[fleet.slug] ? 'opacity-50 pointer-events-none' : ''"
                            />
                        </div>
                    </template>
                </div>

                {{-- KPI Strip: Hero gauge + Direction + Stats --}}
                <div class="ui-card overflow-hidden" x-show="!loading" x-cloak>
                    <div class="kpi-strip">
                        <div class="kpi-strip__hero">
                            <div class="dash-panel__glow"></div>
                            <div class="dash-panel__content">
                                <div class="hero-gauge">
                                    <svg class="hero-gauge__ring" viewBox="0 0 120 120">
                                        <defs>
                                            <linearGradient id="gaugeGradient" x1="0%" y1="0%" x2="100%" y2="100%">
                                                <stop offset="0%" stop-color="rgb(var(--ui-success))" />
                                                <stop offset="100%" stop-color="rgb(var(--ui-primary))" />
                                            </linearGradient>
                                        </defs>
                                        <circle cx="60" cy="60" r="52" class="hero-gauge__track" />
                                        <circle cx="60" cy="60" r="52" class="hero-gauge__fill" :style="'stroke-dasharray: ' + (tradeablePct * 3.27) + ' 327'" />
                                    </svg>
                                    <div class="hero-gauge__center">
                                        <span class="hero-gauge__value"><x-hub-ui::number value="tradeablePct" /></span>
                                        <span class="hero-gauge__unit">%</span>
                                        <span class="hero-gauge__label">TRADEABLE</span>
                                    </div>
                                </div>
                                <div class="hero-stats">
                                    <div class="hero-stat">
                                        <span class="hero-stat__value hero-stat__value--success"><x-hub-ui::number value="stats.total_tradeable" /></span>
                                        <span class="hero-stat__label">Active</span>
                                    </div>
                                    <div class="hero-stat__divider"></div>
                                    <div class="hero-stat">
                                        <span class="hero-stat__value hero-stat__value--muted"><x-hub-ui::number value="stats.total_non_tradeable" /></span>
                                        <span class="hero-stat__label">Inactive</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="kpi-strip__direction">
                            <div class="dash-panel__header">
                                <span class="dash-panel__title">DIRECTION</span>
                                <span class="dash-panel__live"><span class="dash-panel__live-dot"></span>LIVE</span>
                            </div>
                            <div class="direction-display">
                                <div class="direction-side direction-side--long">
                                    <div class="direction-side__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 19V5M5 12l7-7 7 7" /></svg></div>
                                    <div class="direction-side__data">
                                        <span class="direction-side__value"><x-hub-ui::number value="stats.total_longs" /></span>
                                        <span class="direction-side__pct" x-text="longPct + '%'"></span>
                                    </div>
                                    <span class="direction-side__label">LONG</span>
                                </div>
                                <div class="direction-bar">
                                    <div class="direction-bar__long" :style="'width: ' + longPct + '%'"></div>
                                    <div class="direction-bar__short" :style="'width: ' + shortPct + '%'"></div>
                                </div>
                                <div class="direction-side direction-side--short">
                                    <div class="direction-side__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12l7 7 7-7" /></svg></div>
                                    <div class="direction-side__data">
                                        <span class="direction-side__value"><x-hub-ui::number value="stats.total_shorts" /></span>
                                        <span class="direction-side__pct" x-text="shortPct + '%'"></span>
                                    </div>
                                    <span class="direction-side__label">SHORT</span>
                                </div>
                            </div>
                        </div>
                        <div class="kpi-strip__stats">
                            <div class="stat-item"><span class="stat-item__value"><x-hub-ui::number value="stats.total_exchanges" /></span><span class="stat-item__label">EXCHANGES</span></div>
                            <div class="stat-item"><span class="stat-item__value"><x-hub-ui::number value="stats.total_symbols" /></span><span class="stat-item__label">CMC SYMBOLS</span></div>
                            <div class="stat-item"><span class="stat-item__value"><x-hub-ui::number value="stats.total_exchange_symbols" /></span><span class="stat-item__label">EX. SYMBOLS</span></div>
                            <div class="stat-item stat-item--highlight"><span class="stat-item__value"><x-hub-ui::number value="stats.total_tradeable" /></span><span class="stat-item__label">TRADEABLE</span></div>
                        </div>
                    </div>
                </div>

                {{-- Step Dispatchers — per-prefix cards --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4" x-show="!loading" x-cloak>
                    <template x-for="fleet in dispatchers" :key="fleet.prefix">
                        <div class="ui-card p-4">
                            <div class="flex items-center justify-between mb-3">
                                <div class="flex items-center gap-2">
                                    <h3 class="text-xs font-bold ui-text" x-text="fleet.label"></h3>
                                    <a :href="fleet.url" wire:navigate class="text-[9px] font-mono hover:opacity-80" style="color: rgb(var(--ui-primary))">details →</a>
                                </div>
                                <template x-if="fleet.data">
                                    <span class="text-[9px] font-mono uppercase tracking-wider"
                                          :style="fleet.data.throughput?.current_per_10s > 0 ? 'color: rgb(var(--ui-success))' : 'color: rgb(var(--ui-text-subtle))'"
                                          x-text="(fleet.data.throughput?.current_per_10s || 0) + '/10s'"></span>
                                </template>
                            </div>

                            {{-- State badges --}}
                            <div class="grid grid-cols-3 gap-2 mb-3" x-show="fleet.data">
                                <template x-for="st in ['Pending', 'Dispatched', 'Running', 'Throttled', 'Failed', 'Completed']" :key="st">
                                    <div class="flex flex-col items-center py-1.5 rounded-md" style="background-color: rgb(var(--ui-bg-elevated))">
                                        <span class="text-sm font-bold font-mono ui-tabular"
                                              :style="(fleet.data?.leaf_totals?.[st] || 0) > 0 ? 'color: ' + stateColor(st) : ''"
                                              :class="(fleet.data?.leaf_totals?.[st] || 0) === 0 ? 'ui-text-subtle' : ''"
                                              x-text="(fleet.data?.leaf_totals?.[st] || 0).toLocaleString()"></span>
                                        <span class="text-[8px] uppercase tracking-wider ui-text-subtle mt-0.5" x-text="st.slice(0, 4)"></span>
                                    </div>
                                </template>
                            </div>

                            {{-- Throughput bar --}}
                            <div x-show="fleet.data?.throughput" class="mt-2">
                                <div class="flex items-center justify-between text-[9px] ui-text-subtle mb-1">
                                    <span>Throughput</span>
                                    <span class="font-mono" x-text="(fleet.data?.throughput?.saturation || 0).toFixed(0) + '% sat'"></span>
                                </div>
                                <div class="h-1.5 rounded-full overflow-hidden" style="background-color: rgb(var(--ui-bg-elevated))">
                                    <div class="h-full rounded-full transition-all duration-500"
                                         :style="'width: ' + (fleet.data?.throughput?.saturation || 0) + '%; background-color: ' + (fleet.data?.throughput?.saturation >= 80 ? 'rgb(var(--ui-danger))' : fleet.data?.throughput?.saturation >= 50 ? 'rgb(var(--ui-warning))' : 'rgb(var(--ui-success))')"></div>
                                </div>
                            </div>

                            {{-- Loading / unavailable --}}
                            <div x-show="!fleet.data && !fleet.unavailable" class="flex justify-center py-4">
                                <x-hub-ui::spinner size="sm" />
                            </div>
                            <div x-show="fleet.unavailable" class="flex justify-center py-4">
                                <span class="text-[10px] ui-text-subtle uppercase tracking-wider">No data</span>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- Exchanges Table — grows to fill remaining height --}}
                <div class="ui-card overflow-hidden flex-1" x-show="!loading" x-cloak>
                    <div class="dash-panel__header">
                        <span class="dash-panel__title">EXCHANGES</span>
                        <span class="dash-panel__subtitle" x-text="exchanges.length + ' connected'"></span>
                    </div>
                    <div class="exchange-table">
                        <div class="exchange-table__header">
                            <span>Exchange</span><span>Symbols</span><span>Tradeable</span><span>Rate</span><span>Long</span><span>Short</span>
                        </div>
                        <template x-for="ex in exchanges" :key="ex.canonical">
                            <div class="exchange-row">
                                <div class="exchange-row__name">
                                    <span class="exchange-row__icon" :style="'background: ' + exchangeColor(ex.canonical)" x-text="ex.name.charAt(0)"></span>
                                    <span x-text="ex.name"></span>
                                </div>
                                <span class="exchange-row__num" x-text="ex.total.toLocaleString()"></span>
                                <span class="exchange-row__num exchange-row__num--highlight" x-text="ex.tradeable.toLocaleString()"></span>
                                <div class="exchange-row__rate">
                                    <div class="mini-bar"><div class="mini-bar__fill" :style="'width: ' + exTradeablePct(ex) + '%; background: ' + exchangeColor(ex.canonical)"></div></div>
                                    <span x-text="exTradeablePct(ex) + '%'"></span>
                                </div>
                                <span class="exchange-row__num exchange-row__num--long" x-text="ex.tradeable_longs"></span>
                                <span class="exchange-row__num exchange-row__num--short" x-text="ex.tradeable_shorts"></span>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Loading state --}}
                <div x-show="loading" class="flex items-center justify-center py-20">
                    <x-hub-ui::spinner size="lg" />
                </div>
            </div>

            {{-- ═══════════════════════════════════════════════════════════
                 SIDEBAR — Vitals, BSCS compact, Cooldown, Slow Queries
                 ═══════════════════════════════════════════════════════════ --}}
            <div class="hidden xl:block">
                <div class="ui-card p-4 divide-y ui-border h-full flex flex-col">

                    {{-- Vitals --}}
                    <div class="pb-4">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-[11px] font-semibold uppercase tracking-wider ui-text-muted">Vitals</h3>
                            <span class="text-[9px] uppercase tracking-wider ui-text-subtle">Live</span>
                        </div>
                        <div class="space-y-3">
                            <template x-for="gauge in gauges" :key="gauge.label">
                                <div>
                                    <div class="flex items-center justify-between mb-1">
                                        <span class="text-[11px] font-medium ui-text" x-text="gauge.label"></span>
                                        <span class="text-[11px] font-mono ui-tabular" :style="'color: ' + gaugeColor(gauge.percent)" x-text="gauge.percent.toFixed(1) + '%'"></span>
                                    </div>
                                    <x-hub-ui::progress-bar value="gauge.percent" ticks="10" tick-width="6" tick-height="14" tick-gap="2" class="w-full" />
                                    <div class="flex items-center justify-between mt-1 text-[9px] ui-text-subtle font-mono">
                                        <span class="truncate" x-text="gauge.detail"></span>
                                        <x-hub-ui::trend-delta value="gauge.delta" suffix="pt" precision="1" />
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    {{-- BSCS — compact --}}
                    <div class="py-4" x-show="bscs" x-cloak>
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="text-[11px] font-semibold uppercase tracking-wider ui-text-muted">BSCS</h3>
                            <span class="text-[9px] ui-text-subtle font-mono" x-text="bscs?.is_stale ? '⚠ stale' : bscsRelativeAge()"></span>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="flex-shrink-0 w-12 h-12 rounded-xl flex flex-col items-center justify-center"
                                 :class="'bscs-tile--' + (bscs?.band ?? 'unknown')">
                                <span class="text-lg font-bold font-mono leading-none" x-text="bscs?.score ?? '—'"></span>
                                <span class="text-[7px] uppercase tracking-wider opacity-75 mt-0.5" x-text="(bscs?.band ?? '—').toUpperCase()"></span>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="bscs-pill-sm" :class="bscs?.should_block_opens ? 'bscs-pill-sm--danger' : 'bscs-pill-sm--ok'">
                                    <span class="w-1.5 h-1.5 rounded-full" style="background: currentColor"></span>
                                    <span x-text="bscs?.should_block_opens ? 'Blocked' : 'Flowing'"></span>
                                </div>
                                <div x-show="bscs?.cooldown_active" class="text-[9px] ui-text-subtle font-mono mt-1 truncate">
                                    until <span x-text="bscsFmtTime(bscs?.cooldown_until)"></span>
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center gap-1.5 mt-3">
                            <template x-for="sig in ['vol_expansion', 'range_blowout', 'corr_regime', 'rejection_pct', 'fut_vol']" :key="sig">
                                <div class="flex-1 h-1.5 rounded-full" :style="bscs?.sub_signals?.[sig]?.fired ? 'background-color: rgb(var(--ui-danger))' : 'background-color: rgb(var(--ui-bg-elevated))'"></div>
                            </template>
                        </div>
                    </div>

                    {{-- Cooldown Toggles --}}
                    <div class="py-4">
                        <h3 class="text-[11px] font-semibold uppercase tracking-wider ui-text-muted mb-3">Fleet Cooldown</h3>
                        <div class="space-y-2">
                            <template x-for="fleet in cooldownFleets" :key="fleet.slug + '-sidebar'">
                                <div class="flex items-center justify-between gap-2 px-3 py-2.5 rounded-lg transition-colors"
                                     :style="cooldown[fleet.slug]?.is_paused ? 'background-color: rgb(var(--ui-warning) / 0.08); border: 1px solid rgb(var(--ui-warning) / 0.3)' : 'background-color: rgb(var(--ui-bg-elevated)); border: 1px solid transparent'">
                                    <div class="flex items-center gap-2">
                                        <template x-if="cooldown[fleet.slug]?.is_paused">
                                            <x-hub-ui::pulse-dot type="warning" :pulse="true" size="sm" />
                                        </template>
                                        <template x-if="!cooldown[fleet.slug]?.is_paused">
                                            <x-hub-ui::pulse-dot type="success" size="sm" />
                                        </template>
                                        <span class="text-xs font-semibold ui-text" x-text="fleet.label"></span>
                                    </div>
                                    <x-hub-ui::switch
                                        state="cooldown[fleet.slug]?.is_paused"
                                        @click="toggleCoolingDown(fleet.slug)"
                                        onColor="warning"
                                        size="sm"
                                        x-bind:class="togglingCoolingDown[fleet.slug] ? 'opacity-50 pointer-events-none' : ''"
                                    />
                                </div>
                            </template>
                        </div>
                    </div>

                    {{-- Slow Queries --}}
                    <div class="pt-4 flex-1">
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="text-[11px] font-semibold uppercase tracking-wider ui-text-muted">Slow Queries</h3>
                            <template x-if="slowQueries.last_hour_count === 0">
                                <span class="text-[10px] font-mono" style="color: rgb(var(--ui-success))">clear</span>
                            </template>
                            <template x-if="slowQueries.last_hour_count > 0">
                                <span class="text-[10px] font-mono" style="color: rgb(var(--ui-warning))" x-text="slowQueries.last_hour_count + ' hits'"></span>
                            </template>
                        </div>
                        <div class="flex items-baseline gap-2">
                            <span class="text-lg font-bold font-mono ui-tabular" :class="slowQueries.last_hour_count === 0 ? 'ui-text-subtle' : 'ui-text'" x-text="(slowQueries.last_hour_count || 0).toLocaleString()"></span>
                            <span class="text-[9px] uppercase tracking-wider ui-text-subtle">last hour</span>
                        </div>
                        <div class="text-[9px] ui-text-subtle font-mono mt-1">
                            <span x-text="(slowQueries.recent?.[0]?.time_ms || 0) + 'ms newest'"></span>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <style>
        /* KPI Strip */
        .kpi-strip { display: grid; grid-template-columns: 1fr; gap: 1px; background: rgb(var(--ui-border) / 0.5); }
        @media (min-width: 768px) { .kpi-strip { grid-template-columns: 200px 1fr; } .kpi-strip__hero { grid-row: span 2; } .kpi-strip__direction { grid-column: 2; } .kpi-strip__stats { grid-column: 2; } }
        @media (min-width: 1280px) { .kpi-strip { grid-template-columns: 200px minmax(0, 1.4fr) minmax(0, 1.6fr); } .kpi-strip__hero { grid-row: auto; } .kpi-strip__direction { grid-column: 2; } .kpi-strip__stats { grid-column: 3; } }
        .kpi-strip__hero, .kpi-strip__direction, .kpi-strip__stats { background: rgb(var(--ui-bg-card)); position: relative; min-width: 0; }
        .kpi-strip__hero { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 16px; background: linear-gradient(145deg, rgb(var(--ui-bg-card)), rgb(var(--ui-bg-elevated) / 0.5)); }
        .kpi-strip__direction { display: flex; flex-direction: column; }
        .kpi-strip__stats { display: grid; grid-template-columns: repeat(2, 1fr); }
        @media (min-width: 1280px) { .kpi-strip__stats { grid-template-columns: repeat(4, 1fr); } }

        .dash-panel__header { display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; border-bottom: 1px solid rgb(var(--ui-border) / 0.5); }
        .dash-panel__title { font-size: 11px; font-weight: 600; letter-spacing: 0.08em; color: rgb(var(--ui-text-muted)); }
        .dash-panel__subtitle { font-size: 11px; color: rgb(var(--ui-text-subtle)); }
        .dash-panel__live { display: flex; align-items: center; gap: 6px; font-size: 10px; font-weight: 600; letter-spacing: 0.05em; color: rgb(var(--ui-success)); }
        .dash-panel__live-dot { width: 6px; height: 6px; background: rgb(var(--ui-success)); border-radius: 50%; animation: pulse 2s ease-in-out infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.6; } }
        .dash-panel__glow { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 100px; height: 100px; background: radial-gradient(circle, rgb(var(--ui-success) / 0.15) 0%, transparent 70%); pointer-events: none; }
        .dash-panel__content { position: relative; display: flex; flex-direction: column; align-items: center; gap: 10px; }

        .hero-gauge { position: relative; width: 95px; height: 95px; }
        .hero-gauge__ring { width: 100%; height: 100%; transform: rotate(-90deg); }
        .hero-gauge__track { fill: none; stroke: rgb(var(--ui-border)); stroke-width: 8; }
        .hero-gauge__fill { fill: none; stroke: url(#gaugeGradient); stroke-width: 8; stroke-linecap: round; transition: stroke-dasharray 1s ease-out; filter: drop-shadow(0 0 6px rgb(var(--ui-success) / 0.4)); }
        .hero-gauge__center { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; }
        .hero-gauge__value { font-family: 'JetBrains Mono', monospace; font-size: 24px; font-weight: 700; line-height: 1; color: rgb(var(--ui-text)); }
        .hero-gauge__unit { font-family: 'JetBrains Mono', monospace; font-size: 11px; color: rgb(var(--ui-text-muted)); margin-top: -2px; }
        .hero-gauge__label { font-size: 8px; font-weight: 600; letter-spacing: 0.1em; color: rgb(var(--ui-success)); margin-top: 2px; }
        .hero-stats { display: flex; align-items: center; gap: 12px; }
        .hero-stat { display: flex; flex-direction: column; align-items: center; gap: 1px; }
        .hero-stat__value { font-family: 'JetBrains Mono', monospace; font-size: 13px; font-weight: 600; }
        .hero-stat__value--success { color: rgb(var(--ui-success)); }
        .hero-stat__value--muted { color: rgb(var(--ui-text-subtle)); }
        .hero-stat__label { font-size: 8px; font-weight: 500; letter-spacing: 0.05em; color: rgb(var(--ui-text-subtle)); text-transform: uppercase; }
        .hero-stat__divider { width: 1px; height: 20px; background: rgb(var(--ui-border)); }

        .direction-display { flex: 1; display: flex; align-items: center; padding: 12px 16px; gap: 12px; min-height: 80px; }
        .direction-side { display: flex; align-items: center; gap: 6px; }
        .direction-side--long { color: rgb(var(--ui-success)); }
        .direction-side--short { color: rgb(var(--ui-danger)); flex-direction: row-reverse; }
        .direction-side__icon { width: 22px; height: 22px; display: flex; align-items: center; justify-content: center; border-radius: 5px; background: currentColor; }
        .direction-side__icon svg { width: 12px; height: 12px; color: rgb(var(--ui-bg-card)); }
        .direction-side__data { display: flex; flex-direction: column; }
        .direction-side__value { font-family: 'JetBrains Mono', monospace; font-size: 13px; font-weight: 700; line-height: 1.2; }
        .direction-side__pct { font-family: 'JetBrains Mono', monospace; font-size: 10px; opacity: 0.7; }
        .direction-side__label { font-size: 8px; font-weight: 600; letter-spacing: 0.06em; opacity: 0.6; }
        .direction-side--short .direction-side__data { align-items: flex-end; }
        .direction-bar { flex: 1; height: 5px; display: flex; border-radius: 3px; overflow: hidden; background: rgb(var(--ui-bg-elevated)); }
        .direction-bar__long { height: 100%; background: linear-gradient(90deg, rgb(var(--ui-primary)), rgb(var(--ui-success))); transition: width 0.8s ease-out; }
        .direction-bar__short { height: 100%; background: rgb(var(--ui-danger)); transition: width 0.8s ease-out; }

        .stat-item { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 12px 10px; gap: 3px; border-right: 1px solid rgb(var(--ui-border) / 0.3); border-bottom: 1px solid rgb(var(--ui-border) / 0.3); }
        .stat-item:nth-child(2n) { border-right: none; }
        @media (min-width: 1280px) { .stat-item { border-bottom: none; border-right: 1px solid rgb(var(--ui-border) / 0.3); } .stat-item:last-child { border-right: none; } }
        .stat-item--highlight { background: linear-gradient(180deg, rgb(var(--ui-success) / 0.08) 0%, transparent 100%); }
        .stat-item--highlight .stat-item__value { color: rgb(var(--ui-success)); }
        .stat-item__value { font-family: 'JetBrains Mono', monospace; font-size: 13px; font-weight: 700; color: rgb(var(--ui-text)); line-height: 1; }
        .stat-item__label { font-size: 8px; font-weight: 600; letter-spacing: 0.06em; color: rgb(var(--ui-text-subtle)); }

        /* BSCS compact tile */
        .bscs-tile--calm { background: rgba(16, 185, 129, 0.10); color: rgb(16, 185, 129); }
        .bscs-tile--elevated { background: rgba(245, 158, 11, 0.10); color: rgb(245, 158, 11); }
        .bscs-tile--fragile { background: rgba(249, 115, 22, 0.12); color: rgb(249, 115, 22); }
        .bscs-tile--critical { background: rgba(239, 68, 68, 0.14); color: rgb(239, 68, 68); }
        .bscs-tile--unknown { background: rgba(148, 163, 184, 0.10); color: rgb(148, 163, 184); }
        .bscs-pill-sm { display: inline-flex; align-items: center; gap: 4px; font-size: 10px; font-weight: 600; }
        .bscs-pill-sm--ok { color: rgb(var(--ui-success)); }
        .bscs-pill-sm--danger { color: rgb(var(--ui-danger)); }

        /* Exchange Table */
        .exchange-table { display: flex; flex-direction: column; }
        .exchange-table__header { display: grid; grid-template-columns: 1.4fr 0.8fr 0.8fr 1.2fr 0.6fr 0.6fr; gap: 6px; padding: 7px 14px; font-size: 9px; font-weight: 600; letter-spacing: 0.05em; color: rgb(var(--ui-text-subtle)); text-transform: uppercase; background: rgb(var(--ui-bg-elevated) / 0.5); border-bottom: 1px solid rgb(var(--ui-border) / 0.3); }
        .exchange-row { display: grid; grid-template-columns: 1.4fr 0.8fr 0.8fr 1.2fr 0.6fr 0.6fr; gap: 6px; padding: 7px 14px; align-items: center; border-bottom: 1px solid rgb(var(--ui-border) / 0.2); transition: background 0.15s; }
        .exchange-row:hover { background: rgb(var(--ui-bg-elevated) / 0.3); }
        .exchange-row:last-child { border-bottom: none; }
        .exchange-row__name { display: flex; align-items: center; gap: 7px; font-size: 12px; font-weight: 500; color: rgb(var(--ui-text)); }
        .exchange-row__icon { width: 18px; height: 18px; display: flex; align-items: center; justify-content: center; border-radius: 4px; font-size: 10px; font-weight: 700; color: #000; }
        .exchange-row__num { font-family: 'JetBrains Mono', monospace; font-size: 12px; font-weight: 500; color: rgb(var(--ui-text-muted)); }
        .exchange-row__num--highlight { color: rgb(var(--ui-text)); font-weight: 600; }
        .exchange-row__num--long { color: rgb(var(--ui-success)); }
        .exchange-row__num--short { color: rgb(var(--ui-danger)); }
        .exchange-row__rate { display: flex; align-items: center; gap: 5px; font-family: 'JetBrains Mono', monospace; font-size: 11px; color: rgb(var(--ui-text-muted)); }
        .mini-bar { flex: 1; height: 3px; background: rgb(var(--ui-bg-elevated)); border-radius: 2px; overflow: hidden; }
        .mini-bar__fill { height: 100%; border-radius: 2px; transition: width 0.6s ease-out; }
        @media (max-width: 640px) { .exchange-table__header, .exchange-row { grid-template-columns: 1.2fr 0.8fr 0.8fr 1fr; } .exchange-table__header span:nth-child(5), .exchange-table__header span:nth-child(6), .exchange-row span:nth-child(5), .exchange-row span:nth-child(6) { display: none; } }
    </style>

    <script>
        function adminDashboard() {
            return {
                loading: true,
                stats: {},
                exchanges: [],
                bscs: null,
                bscsOverrideHours: 4,
                bscsOverrideReason: '',
                bscsOverrideBusy: false,
                bscsOverrideError: '',
                cooldownFleets: [
                    { slug: 'default', label: 'Default' },
                    { slug: 'trading', label: 'Trading' },
                ],
                cooldown: { default: { is_paused: false }, trading: { is_paused: false } },
                togglingCoolingDown: { default: false, trading: false },

                dispatchers: [
                    { prefix: 'default', label: 'Default Fleet', url: '{{ route("system.steps", ["prefix" => "default"]) }}', data: null, unavailable: false },
                    { prefix: 'trading', label: 'Trading Fleet', url: '{{ route("system.steps", ["prefix" => "trading"]) }}', data: null, unavailable: false },
                ],

                bscsRelativeAge() {
                    const s = Number(this.bscs?.age_seconds);
                    if (!Number.isFinite(s) || s < 0) return '—';
                    if (s < 60) return s + 's ago';
                    if (s < 3600) return Math.floor(s / 60) + 'm ago';
                    return Math.floor(s / 3600) + 'h ago';
                },
                bscsFmtTime(iso) { if (!iso) return '—'; try { return new Date(iso).toLocaleTimeString(); } catch (_) { return iso; } },
                bscsOverrideReady() { const h = Number(this.bscsOverrideHours); return Number.isFinite(h) && h >= 0.5 && h <= 24 && (this.bscsOverrideReason ?? '').trim().length >= 3; },
                async bscsEngageOverride() {
                    this.bscsOverrideError = '';
                    if (!this.bscsOverrideReady()) { this.bscsOverrideError = 'Hours 0.5–24 + reason ≥3 chars.'; return; }
                    this.bscsOverrideBusy = true;
                    try {
                        const res = await window.hubUiFetch('{{ route('system.bscs.override.engage') }}', { method: 'POST', body: JSON.stringify({ hours: this.bscsOverrideHours, reason: this.bscsOverrideReason.trim() }) });
                        if (res.ok) { this.bscsOverrideReason = ''; await this.fetchData(); } else { this.bscsOverrideError = res.data?.error ?? 'Failed.'; }
                    } catch (e) { this.bscsOverrideError = e.message; } finally { this.bscsOverrideBusy = false; }
                },
                async bscsClearOverride() {
                    this.bscsOverrideBusy = true;
                    try { const res = await window.hubUiFetch('{{ route('system.bscs.override.clear') }}', { method: 'POST' }); if (res.ok) await this.fetchData(); } catch (_) {} finally { this.bscsOverrideBusy = false; }
                },

                safePct(n, t) { n = Number(n) || 0; t = Number(t) || 0; if (!t || n <= 0) return 0; return Math.max(1, Math.round((n / t) * 100)); },
                get tradeablePct() { return this.safePct(this.stats.total_tradeable, this.stats.total_exchange_symbols); },
                get longPct() { const l = Number(this.stats.total_longs) || 0; return this.safePct(l, l + (Number(this.stats.total_shorts) || 0)); },
                get shortPct() { const s = Number(this.stats.total_shorts) || 0; return this.safePct(s, (Number(this.stats.total_longs) || 0) + s); },
                exTradeablePct(ex) { return this.safePct(ex.tradeable, ex.total); },
                exchangeColor(canonical) { return ({ binance: '#F0B90B', bybit: '#F7A600', kucoin: '#23AF91', bitget: '#00B8D9' })[canonical] || '#6366f1'; },

                stateColor(st) {
                    return ({ Pending: 'rgb(var(--ui-info))', Dispatched: 'rgb(var(--ui-primary))', Running: 'rgb(var(--ui-warning))', Throttled: 'rgb(var(--ui-text-muted))', Failed: 'rgb(var(--ui-danger))', Completed: 'rgb(var(--ui-success))' })[st] || 'rgb(var(--ui-text-subtle))';
                },

                async fetchData() {
                    const [dashRes, coolingRes, defaultRes, tradingRes] = await Promise.all([
                        hubUiFetch('{{ route("system.dashboard.data") }}', { method: 'GET' }),
                        hubUiFetch('{{ route("system.steps.cooling-down") }}', { method: 'GET' }),
                        hubUiFetch('{{ route("system.steps.data", ["prefix" => "default"]) }}', { method: 'GET' }),
                        hubUiFetch('{{ route("system.steps.data", ["prefix" => "trading"]) }}', { method: 'GET' }),
                    ]);
                    if (dashRes.ok) { this.stats = dashRes.data; this.exchanges = dashRes.data.exchanges; this.bscs = dashRes.data.bscs ?? null; }
                    if (coolingRes.ok) { this.cooldown = { default: coolingRes.data.default ?? this.cooldown.default, trading: coolingRes.data.trading ?? this.cooldown.trading }; }
                    if (defaultRes.ok) { this.dispatchers[0].data = defaultRes.data; } else { this.dispatchers[0].unavailable = true; }
                    if (tradingRes.ok) { this.dispatchers[1].data = tradingRes.data; } else { this.dispatchers[1].unavailable = true; }
                    this.loading = false;
                },

                async toggleCoolingDown(slug) {
                    if (this.togglingCoolingDown[slug]) return;
                    this.togglingCoolingDown[slug] = true;
                    const prev = { ...this.cooldown[slug] };
                    this.cooldown[slug] = { ...prev, is_paused: !prev.is_paused };
                    const url = '{{ route("system.steps.toggle-cooling-down", ["prefix" => "__SLUG__"]) }}'.replace('__SLUG__', slug);
                    const { ok, data } = await hubUiFetch(url, { method: 'POST' });
                    if (ok && data) { this.cooldown[slug] = { is_paused: data.is_paused, reason: data.reason ?? null }; } else { this.cooldown[slug] = prev; }
                    this.togglingCoolingDown[slug] = false;
                },
            };
        }

        function heartbeat() {
            return {
                _interval: null, _hasSnapshot: false,
                gauges: [
                    { label: 'CPU', percent: 0, delta: 0, detail: '—' },
                    { label: 'RAM', percent: 0, delta: 0, detail: '—' },
                    { label: 'HDD', percent: 0, delta: 0, detail: '—' },
                ],
                stepDispatcher: { running: false, total: 0, by_state: {}, last_tick: null },
                slowQueries: { last_hour_count: 0, recent: [] },

                async fetchHealth() {
                    const { ok, data } = await hubUiFetch('{{ route("system.dashboard.health") }}', { method: 'GET' });
                    if (ok) {
                        const s = data.server;
                        const ramPct = s.ram_total_mb > 0 ? (s.ram_used_mb / s.ram_total_mb * 100) : 0;
                        const hddPct = s.hdd_total_gb > 0 ? (s.hdd_used_gb / s.hdd_total_gb * 100) : 0;
                        const newG = [
                            { label: 'CPU', percent: s.cpu_percent, detail: 'load across cores' },
                            { label: 'RAM', percent: Math.round(ramPct * 10) / 10, detail: this.formatMb(s.ram_used_mb) + ' / ' + this.formatMb(s.ram_total_mb) },
                            { label: 'HDD', percent: Math.round(hddPct * 10) / 10, detail: s.hdd_used_gb + ' / ' + s.hdd_total_gb + ' GB' },
                        ];
                        this.gauges = newG.map((g, i) => ({ ...g, delta: Math.round((g.percent - (this._hasSnapshot ? this.gauges[i].percent : g.percent)) * 10) / 10 }));
                        this._hasSnapshot = true;
                        this.stepDispatcher = data.step_dispatcher;
                        this.slowQueries = data.slow_queries;
                    }
                },
                startPolling() { this._interval = setInterval(() => this.fetchHealth(), 5000); },
                formatMb(mb) { return mb >= 1024 ? (mb / 1024).toFixed(1) + ' GB' : Math.round(mb) + ' MB'; },
                gaugeColor(p) { return p >= 80 ? 'rgb(var(--ui-danger))' : p >= 60 ? 'rgb(var(--ui-warning))' : 'rgb(var(--ui-success))'; },
                stepStateColor(state) { return ({ Running: 'rgb(var(--ui-warning))', Pending: 'rgb(var(--ui-info))', Failed: 'rgb(var(--ui-danger))' })[state] || 'rgb(var(--ui-text-subtle))'; },
            };
        }
    </script>
</x-app-layout>
