<x-app-layout :activeSection="'system'" :activeHighlight="'system-dashboard'">

    {{-- Vitals ribbon: CPU / RAM / HDD / Dispatcher / Slow Queries.
         Live system health ribbon — was /system/heartbeat before that
         standalone surface was retired into this dashboard. --}}
    <div x-data="heartbeat()" x-init="fetchData(); startPolling()" class="mb-4 sm:mb-6">
        <div class="ui-card p-4 sm:p-5">
            <div class="flex items-baseline justify-between mb-4 gap-3 flex-wrap">
                <div class="flex items-baseline gap-2 flex-wrap">
                    <h2 class="text-sm font-semibold ui-text">Vitals</h2>
                    <span class="text-[11px] ui-text-muted">server load · dispatcher throughput · query health</span>
                </div>
                <span class="text-[10px] ui-text-subtle uppercase tracking-wider">Live</span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-4">
                <template x-for="gauge in gauges" :key="gauge.label">
                    <div class="ui-bg-elevated rounded-lg p-3">
                        <div class="flex items-baseline justify-between mb-2">
                            <span class="text-xs font-semibold ui-text" x-text="gauge.label"></span>
                            <span
                                class="text-[11px] font-mono ui-tabular"
                                :style="'color: ' + gaugeColor(gauge.percent)"
                                x-text="gauge.percent.toFixed(1) + '%'"
                            ></span>
                        </div>

                        <x-hub-ui::progress-bar
                            value="gauge.percent"
                            ticks="10"
                            tick-width="8"
                            tick-height="18"
                            tick-gap="2"
                            class="w-full"
                        />

                        <div class="flex items-center justify-between mt-2 text-[10px] ui-text-subtle font-mono">
                            <span class="truncate" :title="gauge.detail" x-text="gauge.detail"></span>
                            <x-hub-ui::trend-delta value="gauge.delta" suffix="pt" precision="1" />
                        </div>
                    </div>
                </template>

                {{-- Dispatcher tile --}}
                <div class="ui-bg-elevated rounded-lg p-3">
                    <div class="flex items-baseline justify-between mb-2">
                        <span class="text-xs font-semibold ui-text">Dispatcher</span>
                        <template x-if="stepDispatcher.running">
                            <span class="text-[11px] font-mono ui-tabular" style="color: rgb(var(--ui-success))">running</span>
                        </template>
                        <template x-if="!stepDispatcher.running">
                            <span class="text-[11px] font-mono ui-tabular" style="color: rgb(var(--ui-danger))">stopped</span>
                        </template>
                    </div>

                    <div class="flex items-baseline gap-3 leading-none py-1">
                        <span class="text-base font-bold font-mono ui-text ui-tabular" x-text="(stepDispatcher.total || 0).toLocaleString()"></span>
                        <template x-for="key in ['Running', 'Pending', 'Failed']" :key="key">
                            <div class="flex items-baseline gap-1">
                                <span
                                    class="text-xs font-bold font-mono ui-tabular"
                                    :style="((stepDispatcher.by_state || {})[key] || 0) > 0 ? 'color: ' + stepStateColor(key) : ''"
                                    :class="((stepDispatcher.by_state || {})[key] || 0) === 0 ? 'ui-text-subtle' : ''"
                                    x-text="((stepDispatcher.by_state || {})[key] || 0).toLocaleString()"
                                ></span>
                                <span class="text-[9px] uppercase tracking-[0.12em] ui-text-subtle" x-text="key.slice(0, 3).toLowerCase()"></span>
                            </div>
                        </template>
                    </div>

                    <div class="flex items-center justify-between mt-2 text-[10px] ui-text-subtle font-mono">
                        <span class="truncate" x-text="stepDispatcher.last_tick ? ('last ' + stepDispatcher.last_tick) : '—'"></span>
                        <a href="{{ route('system.steps', ['prefix' => 'default']) }}" wire:navigate class="flex items-center gap-0.5 hover:opacity-80 transition-opacity" style="color: rgb(var(--ui-primary))">
                            <span>details</span>
                            <x-feathericon-chevron-right class="w-3 h-3" />
                        </a>
                    </div>
                </div>

                {{-- Slow queries tile --}}
                <div class="ui-bg-elevated rounded-lg p-3">
                    <div class="flex items-baseline justify-between mb-2">
                        <span class="text-xs font-semibold ui-text">Slow Queries</span>
                        <template x-if="slowQueries.last_hour_count === 0">
                            <span class="text-[11px] font-mono ui-tabular" style="color: rgb(var(--ui-success))">clear</span>
                        </template>
                        <template x-if="slowQueries.last_hour_count > 0">
                            <span class="text-[11px] font-mono ui-tabular" style="color: rgb(var(--ui-warning))" x-text="slowQueries.last_hour_count + ' hits'"></span>
                        </template>
                    </div>

                    <div class="flex items-baseline gap-2 py-1">
                        <span
                            class="text-base font-bold font-mono ui-tabular leading-none"
                            :class="slowQueries.last_hour_count === 0 ? 'ui-text-subtle' : 'ui-text'"
                            x-text="(slowQueries.last_hour_count || 0).toLocaleString()"
                        ></span>
                        <span class="text-[9px] uppercase tracking-[0.12em] ui-text-subtle">last hour</span>
                    </div>

                    <div class="flex items-center justify-between mt-2 text-[10px] ui-text-subtle font-mono">
                        <span class="truncate" x-text="(slowQueries.recent?.[0]?.time_ms || 0) + 'ms newest'"></span>
                        <span x-text="(slowQueries.recent?.length || 0) + ' recent'"></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div x-data="adminDashboard()" x-init="fetchData()">
        <div x-show="loading" class="flex items-center justify-center py-20">
            <x-hub-ui::spinner size="lg" />
        </div>

        <div x-show="!loading" x-cloak class="space-y-4 sm:space-y-5">
            {{-- System State — per-fleet cooldown chips (Default + Trading).
                 Each chip toggles its own MaintenanceMode prefix flag
                 independently — pausing one fleet does NOT pause the
                 other. Backed by `MaintenanceMode::isStepsDispatchPaused`
                 / `pauseStepsDispatch` / `resumeStepsDispatch` so the
                 ingestion-side `routes/console.php` skip-gates honour
                 the chip state per fleet. --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <template x-for="fleet in cooldownFleets" :key="fleet.slug">
                    <div
                        class="ui-card flex items-center justify-between gap-4 px-5 py-4 flex-wrap"
                        :style="cooldown[fleet.slug]?.is_paused ? 'border-color: rgb(var(--ui-warning) / 0.5); background-color: rgb(var(--ui-warning) / 0.08)' : ''"
                    >
                        <div class="flex items-center gap-3 min-w-0">
                            <template x-if="cooldown[fleet.slug]?.is_paused">
                                <x-hub-ui::pulse-dot type="warning" :pulse="true" size="md" />
                            </template>
                            <template x-if="!cooldown[fleet.slug]?.is_paused">
                                <x-hub-ui::pulse-dot type="success" size="md" />
                            </template>
                            <div class="min-w-0">
                                <div class="text-sm font-semibold ui-text">
                                    Cooling Down — <span x-text="fleet.label"></span>
                                </div>
                                <p
                                    class="text-xs ui-text-subtle mt-0.5"
                                    x-text="cooldown[fleet.slug]?.is_paused
                                        ? (fleet.label + ' fleet paused' + (cooldown[fleet.slug]?.reason ? ' — ' + cooldown[fleet.slug].reason : '') + '.')
                                        : (fleet.label + ' fleet dispatching on cadence.')"
                                ></p>
                            </div>
                        </div>
                        <x-hub-ui::switch
                            state="cooldown[fleet.slug]?.is_paused"
                            @click="toggleCoolingDown(fleet.slug)"
                            onColor="warning"
                            size="md"
                            x-bind:class="togglingCoolingDown[fleet.slug] ? 'opacity-50 pointer-events-none' : ''"
                        />
                    </div>
                </template>
            </div>

            {{-- KPI Strip: Hero gauge + Direction + Stats --}}
            <div class="ui-card overflow-hidden">
                <div class="kpi-strip">
                    {{-- Hero gauge --}}
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
                                    <span class="hero-gauge__value">
                                        <x-hub-ui::number value="tradeablePct" />
                                    </span>
                                    <span class="hero-gauge__unit">%</span>
                                    <span class="hero-gauge__label">TRADEABLE</span>
                                </div>
                            </div>
                            <div class="hero-stats">
                                <div class="hero-stat">
                                    <span class="hero-stat__value hero-stat__value--success">
                                        <x-hub-ui::number value="stats.total_tradeable" />
                                    </span>
                                    <span class="hero-stat__label">Active</span>
                                </div>
                                <div class="hero-stat__divider"></div>
                                <div class="hero-stat">
                                    <span class="hero-stat__value hero-stat__value--muted">
                                        <x-hub-ui::number value="stats.total_non_tradeable" />
                                    </span>
                                    <span class="hero-stat__label">Inactive</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Direction --}}
                    <div class="kpi-strip__direction">
                        <div class="dash-panel__header">
                            <span class="dash-panel__title">DIRECTION</span>
                            <span class="dash-panel__live"><span class="dash-panel__live-dot"></span>LIVE</span>
                        </div>
                        <div class="direction-display">
                            <div class="direction-side direction-side--long">
                                <div class="direction-side__icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                        <path d="M12 19V5M5 12l7-7 7 7" />
                                    </svg>
                                </div>
                                <div class="direction-side__data">
                                    <span class="direction-side__value">
                                        <x-hub-ui::number value="stats.total_longs" />
                                    </span>
                                    <span class="direction-side__pct" x-text="longPct + '%'"></span>
                                </div>
                                <span class="direction-side__label">LONG</span>
                            </div>
                            <div class="direction-bar">
                                <div class="direction-bar__long" :style="'width: ' + longPct + '%'"></div>
                                <div class="direction-bar__short" :style="'width: ' + shortPct + '%'"></div>
                            </div>
                            <div class="direction-side direction-side--short">
                                <div class="direction-side__icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                        <path d="M12 5v14M5 12l7 7 7-7" />
                                    </svg>
                                </div>
                                <div class="direction-side__data">
                                    <span class="direction-side__value">
                                        <x-hub-ui::number value="stats.total_shorts" />
                                    </span>
                                    <span class="direction-side__pct" x-text="shortPct + '%'"></span>
                                </div>
                                <span class="direction-side__label">SHORT</span>
                            </div>
                        </div>
                    </div>

                    {{-- Stats grid --}}
                    <div class="kpi-strip__stats">
                        <div class="stat-item">
                            <span class="stat-item__value">
                                <x-hub-ui::number value="stats.total_exchanges" />
                            </span>
                            <span class="stat-item__label">EXCHANGES</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-item__value">
                                <x-hub-ui::number value="stats.total_symbols" />
                            </span>
                            <span class="stat-item__label">CMC SYMBOLS</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-item__value">
                                <x-hub-ui::number value="stats.total_exchange_symbols" />
                            </span>
                            <span class="stat-item__label">EX. SYMBOLS</span>
                        </div>
                        <div class="stat-item stat-item--highlight">
                            <span class="stat-item__value">
                                <x-hub-ui::number value="stats.total_tradeable" />
                            </span>
                            <span class="stat-item__label">TRADEABLE</span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Black Swan Composite Score --}}
            <div class="ui-card overflow-hidden" x-show="bscs">
                    <div class="dash-panel__header">
                        <span class="dash-panel__title">BLACK SWAN COMPOSITE SCORE</span>
                        <span class="dash-panel__subtitle"
                              x-text="bscs?.is_stale ? '⚠ stale' : ('synced ' + bscsRelativeAge())"></span>
                    </div>
                    <div class="bscs-grid">
                        {{-- Score + band ring --}}
                        <div class="bscs-score" :class="'bscs-score--' + (bscs?.band ?? 'unknown')">
                            <div class="bscs-score__num" x-text="bscs?.score ?? '—'"></div>
                            <div class="bscs-score__band" x-text="(bscs?.band ?? 'no data').toUpperCase()"></div>
                            <div class="bscs-score__threshold"
                                 x-text="'block @ ' + (bscs?.cooldown_threshold ?? bscs?.block_threshold ?? 80)"></div>
                        </div>

                        {{-- Cooldown / override pills --}}
                        <div class="bscs-state">
                            <div class="bscs-pill"
                                 :class="bscs?.should_block_opens ? 'bscs-pill--danger' : 'bscs-pill--ok'">
                                <span class="bscs-pill__dot"></span>
                                <span x-text="bscs?.should_block_opens ? 'New opens BLOCKED' : 'Opens flowing'"></span>
                            </div>
                            <div x-show="bscs?.cooldown_active" class="bscs-meta">
                                Cooldown until <span x-text="bscsFmtTime(bscs?.cooldown_until)"></span>
                            </div>
                            <div x-show="bscs?.override_active" class="bscs-meta">
                                Override until <span x-text="bscsFmtTime(bscs?.override_until)"></span>
                            </div>
                        </div>

                        {{-- 5-signal grid --}}
                        <div class="bscs-signals">
                            <template x-for="sig in [
                                { key: 'vol_expansion', label: 'Vol expansion', threshold: '>1.30' },
                                { key: 'range_blowout', label: 'Range blowout', threshold: '>1.50' },
                                { key: 'corr_regime',   label: 'Correlation',   threshold: '>0.70' },
                                { key: 'rejection_pct', label: 'Rejection %',   threshold: '<-5%' },
                                { key: 'fut_vol',       label: 'Futures vol',   threshold: '>1.20' },
                            ]" :key="sig.key">
                                <div class="bscs-signal"
                                     :class="bscs?.sub_signals?.[sig.key]?.fired ? 'bscs-signal--fired' : ''">
                                    <span class="bscs-signal__label" x-text="sig.label"></span>
                                    <span class="bscs-signal__value"
                                          x-text="bscs?.sub_signals?.[sig.key]?.value ?? '—'"></span>
                                    <span class="bscs-signal__threshold" x-text="sig.threshold"></span>
                                    <span class="bscs-signal__dot"
                                          x-text="bscs?.sub_signals?.[sig.key]?.fired ? '✓' : '·'"></span>
                                </div>
                            </template>
                        </div>

                        {{-- Sparkline --}}
                        <div class="bscs-spark" x-show="(bscs?.sparkline ?? []).length > 1">
                            <div class="bscs-spark__title">30 snapshots</div>
                            <div class="bscs-spark__bars">
                                <template x-for="(s, i) in (bscs?.sparkline ?? [])" :key="i">
                                    <div class="bscs-spark__bar"
                                         :class="'bscs-spark__bar--' + (s.band ?? 'unknown')"
                                         :style="'height: ' + Math.max(4, s.score) + '%'"
                                         :title="s.t + ' · score ' + s.score"></div>
                                </template>
                            </div>
                        </div>

                        {{-- Manual override (operator-only — this dashboard is admin-gated) --}}
                        <div class="bscs-override">
                            <div class="bscs-override__title">Manual override</div>
                            <p class="bscs-override__hint">
                                Bypass the cooldown for a time-boxed window. Reason captured for audit log; modelLog fires on engage + clear.
                            </p>

                            {{-- Active state --}}
                            <div x-show="bscs?.override_active" class="bscs-override__active">
                                <div>
                                    <div class="bscs-override__active-title">Active until <span x-text="bscsFmtTime(bscs?.override_until)"></span></div>
                                    <div x-show="bscs?.override_reason" class="bscs-override__active-reason">"<span x-text="bscs?.override_reason"></span>"</div>
                                </div>
                                <button type="button" class="bscs-override__btn bscs-override__btn--secondary"
                                        @click="bscsClearOverride()"
                                        x-bind:disabled="bscsOverrideBusy">
                                    <span x-show="!bscsOverrideBusy">Clear now</span>
                                    <span x-show="bscsOverrideBusy">Working…</span>
                                </button>
                            </div>

                            {{-- Engage form --}}
                            <div x-show="!bscs?.override_active" class="bscs-override__form">
                                <div class="bscs-override__field">
                                    <label>Hours</label>
                                    <input type="number" min="0.5" max="24" step="0.5" x-model.number="bscsOverrideHours" />
                                </div>
                                <div class="bscs-override__field bscs-override__field--grow">
                                    <label>Reason (required)</label>
                                    <input type="text" maxlength="255" x-model="bscsOverrideReason" placeholder="e.g. range-bound chop despite high score" />
                                </div>
                                <button type="button" class="bscs-override__btn bscs-override__btn--primary"
                                        @click="bscsEngageOverride()"
                                        x-bind:disabled="bscsOverrideBusy || !bscsOverrideReady()">
                                    <span x-show="!bscsOverrideBusy">Engage</span>
                                    <span x-show="bscsOverrideBusy">Engaging…</span>
                                </button>
                            </div>
                            <div x-show="bscsOverrideError" class="bscs-override__error" x-text="bscsOverrideError"></div>
                        </div>
                    </div>
                </div>

                {{-- Exchanges Table --}}
                <div class="ui-card overflow-hidden">
                    <div class="dash-panel__header">
                        <span class="dash-panel__title">EXCHANGES</span>
                        <span class="dash-panel__subtitle" x-text="exchanges.length + ' connected'"></span>
                    </div>
                    <div class="exchange-table">
                        <div class="exchange-table__header">
                            <span>Exchange</span>
                            <span>Symbols</span>
                            <span>Tradeable</span>
                            <span>Rate</span>
                            <span>Long</span>
                            <span>Short</span>
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
                                    <div class="mini-bar">
                                        <div class="mini-bar__fill" :style="'width: ' + exTradeablePct(ex) + '%; background: ' + exchangeColor(ex.canonical)"></div>
                                    </div>
                                    <span x-text="exTradeablePct(ex) + '%'"></span>
                                </div>
                                <span class="exchange-row__num exchange-row__num--long" x-text="ex.tradeable_longs"></span>
                                <span class="exchange-row__num exchange-row__num--short" x-text="ex.tradeable_shorts"></span>
                            </div>
                        </template>
                    </div>
                </div>
        </div>
    </div>

    <style>
        /* KPI Strip — Hero gauge + Direction + Stats laid out side-by-side */
        .kpi-strip {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1px;
            background: rgb(var(--ui-border) / 0.5);
        }
        @media (min-width: 768px) {
            .kpi-strip {
                grid-template-columns: 220px 1fr;
            }
            .kpi-strip__hero { grid-row: span 2; }
            .kpi-strip__direction { grid-column: 2; }
            .kpi-strip__stats { grid-column: 2; }
        }
        @media (min-width: 1280px) {
            .kpi-strip {
                grid-template-columns: 220px minmax(0, 1.4fr) minmax(0, 1.6fr);
            }
            .kpi-strip__hero { grid-row: auto; }
            .kpi-strip__direction { grid-column: 2; }
            .kpi-strip__stats { grid-column: 3; }
        }
        .kpi-strip__hero,
        .kpi-strip__direction,
        .kpi-strip__stats {
            background: rgb(var(--ui-bg-card));
            position: relative;
            min-width: 0;
        }
        .kpi-strip__hero {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 18px;
            background: linear-gradient(145deg, rgb(var(--ui-bg-card)), rgb(var(--ui-bg-elevated) / 0.5));
        }
        .kpi-strip__direction {
            display: flex;
            flex-direction: column;
        }
        .kpi-strip__stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
        }
        @media (min-width: 1280px) {
            .kpi-strip__stats { grid-template-columns: repeat(4, 1fr); }
        }

        /* Panel header (used inside KPI strip + BSCS + Exchanges cards) */
        .dash-panel__header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 14px;
            border-bottom: 1px solid rgb(var(--ui-border) / 0.5);
        }
        .dash-panel__title {
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.08em;
            color: rgb(var(--ui-text-muted));
        }
        .dash-panel__subtitle {
            font-size: 11px;
            color: rgb(var(--ui-text-subtle));
        }
        .dash-panel__live {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 0.05em;
            color: rgb(var(--ui-success));
        }
        .dash-panel__live-dot {
            width: 6px;
            height: 6px;
            background: rgb(var(--ui-success));
            border-radius: 50%;
            animation: pulse 2s ease-in-out infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; box-shadow: 0 0 0 0 rgb(var(--ui-success) / 0.4); }
            50% { opacity: 0.8; box-shadow: 0 0 0 4px rgb(var(--ui-success) / 0); }
        }

        .dash-panel__glow {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 110px;
            height: 110px;
            background: radial-gradient(circle, rgb(var(--ui-success) / 0.15) 0%, transparent 70%);
            pointer-events: none;
        }
        .dash-panel__content {
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
        }

        /* Hero Gauge */
        .hero-gauge {
            position: relative;
            width: 105px;
            height: 105px;
        }
        .hero-gauge__ring {
            width: 100%;
            height: 100%;
            transform: rotate(-90deg);
        }
        .hero-gauge__track {
            fill: none;
            stroke: rgb(var(--ui-border));
            stroke-width: 8;
        }
        .hero-gauge__fill {
            fill: none;
            stroke: url(#gaugeGradient);
            stroke-width: 8;
            stroke-linecap: round;
            transition: stroke-dasharray 1s ease-out;
            filter: drop-shadow(0 0 6px rgb(var(--ui-success) / 0.4));
        }
        .hero-gauge__center {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .hero-gauge__value {
            font-family: 'JetBrains Mono', monospace;
            font-size: 28px;
            font-weight: 700;
            line-height: 1;
            color: rgb(var(--ui-text));
        }
        .hero-gauge__unit {
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px;
            font-weight: 500;
            color: rgb(var(--ui-text-muted));
            margin-top: -2px;
        }
        .hero-gauge__label {
            font-size: 9px;
            font-weight: 600;
            letter-spacing: 0.1em;
            color: rgb(var(--ui-success));
            margin-top: 3px;
        }

        /* Hero Stats */
        .hero-stats {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .hero-stat {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1px;
        }
        .hero-stat__value {
            font-family: 'JetBrains Mono', monospace;
            font-size: 14px;
            font-weight: 600;
        }
        .hero-stat__value--success { color: rgb(var(--ui-success)); }
        .hero-stat__value--muted { color: rgb(var(--ui-text-subtle)); }
        .hero-stat__label {
            font-size: 9px;
            font-weight: 500;
            letter-spacing: 0.05em;
            color: rgb(var(--ui-text-subtle));
            text-transform: uppercase;
        }
        .hero-stat__divider {
            width: 1px;
            height: 22px;
            background: rgb(var(--ui-border));
        }

        /* Direction Panel */
        .direction-display {
            flex: 1;
            display: flex;
            align-items: center;
            padding: 14px 18px;
            gap: 14px;
            min-height: 84px;
        }
        .direction-side {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .direction-side--long { color: rgb(var(--ui-success)); }
        .direction-side--short { color: rgb(var(--ui-danger)); flex-direction: row-reverse; }
        .direction-side__icon {
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 5px;
            background: currentColor;
        }
        .direction-side__icon svg {
            width: 14px;
            height: 14px;
            color: rgb(var(--ui-bg-card));
        }
        .direction-side__data {
            display: flex;
            flex-direction: column;
        }
        .direction-side__value {
            font-family: 'JetBrains Mono', monospace;
            font-size: 14px;
            font-weight: 700;
            line-height: 1.2;
        }
        .direction-side__pct {
            font-family: 'JetBrains Mono', monospace;
            font-size: 11px;
            font-weight: 500;
            opacity: 0.7;
        }
        .direction-side__label {
            font-size: 9px;
            font-weight: 600;
            letter-spacing: 0.06em;
            opacity: 0.6;
        }
        .direction-side--short .direction-side__data { align-items: flex-end; }

        /* Direction Bar */
        .direction-bar {
            flex: 1;
            height: 6px;
            display: flex;
            border-radius: 3px;
            overflow: hidden;
            background: rgb(var(--ui-bg-elevated));
        }
        .direction-bar__long {
            height: 100%;
            background: linear-gradient(90deg, rgb(var(--ui-primary)), rgb(var(--ui-success)));
            transition: width 0.8s ease-out;
        }
        .direction-bar__short {
            height: 100%;
            background: linear-gradient(90deg, rgb(var(--ui-danger)), rgb(var(--ui-danger)));
            transition: width 0.8s ease-out;
        }

        /* Stats grid (inside KPI strip) */
        .stat-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 14px 12px;
            gap: 4px;
            border-right: 1px solid rgb(var(--ui-border) / 0.3);
            border-bottom: 1px solid rgb(var(--ui-border) / 0.3);
        }
        .stat-item:nth-child(2n) { border-right: none; }
        @media (min-width: 1280px) {
            .stat-item { border-bottom: none; }
            .stat-item { border-right: 1px solid rgb(var(--ui-border) / 0.3); }
            .stat-item:last-child { border-right: none; }
        }
        .stat-item--highlight {
            background: linear-gradient(180deg, rgb(var(--ui-success) / 0.08) 0%, transparent 100%);
        }
        .stat-item--highlight .stat-item__value { color: rgb(var(--ui-success)); }
        .stat-item__value {
            font-family: 'JetBrains Mono', monospace;
            font-size: 14px;
            font-weight: 700;
            color: rgb(var(--ui-text));
            line-height: 1;
        }
        .stat-item__label {
            font-size: 9px;
            font-weight: 600;
            letter-spacing: 0.06em;
            color: rgb(var(--ui-text-subtle));
        }

        /* BSCS Panel */
        .bscs-grid {
            display: grid;
            grid-template-columns: 140px 1fr 1fr;
            grid-template-rows: auto auto;
            gap: 14px;
            padding: 14px;
        }
        .bscs-score {
            grid-row: 1 / span 2;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            padding: 16px 12px;
            border-radius: 12px;
            background: rgba(16, 185, 129, 0.08);
            border: 1px solid rgba(16, 185, 129, 0.25);
        }
        .bscs-score--elevated { background: rgba(245, 158, 11, 0.08); border-color: rgba(245, 158, 11, 0.30); }
        .bscs-score--fragile  { background: rgba(249, 115, 22, 0.10); border-color: rgba(249, 115, 22, 0.35); }
        .bscs-score--critical { background: rgba(239, 68, 68, 0.12);  border-color: rgba(239, 68, 68, 0.40); }
        .bscs-score--unknown  { background: rgba(148, 163, 184, 0.10); border-color: rgba(148, 163, 184, 0.30); }
        .bscs-score__num   { font-size: 38px; font-weight: 700; line-height: 1; font-variant-numeric: tabular-nums; }
        .bscs-score__band  { font-size: 10px; letter-spacing: 0.18em; opacity: 0.75; margin-top: 6px; }
        .bscs-score__threshold { font-size: 9px; opacity: 0.55; margin-top: 4px; font-family: monospace; }

        .bscs-state { display: flex; flex-direction: column; gap: 8px; align-items: flex-start; }
        .bscs-pill {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 10px; border-radius: 999px; font-size: 11px; font-weight: 600;
        }
        .bscs-pill__dot { width: 8px; height: 8px; border-radius: 999px; background: currentColor; }
        .bscs-pill--ok     { background: rgba(16, 185, 129, 0.10); color: rgb(16, 185, 129); }
        .bscs-pill--danger { background: rgba(239, 68, 68, 0.12);  color: rgb(220, 38, 38); }
        .bscs-meta { font-size: 10px; opacity: 0.65; font-family: monospace; }

        .bscs-signals { display: flex; flex-direction: column; gap: 4px; grid-column: 2 / span 2; }
        .bscs-signal {
            display: grid; grid-template-columns: 1fr auto auto 16px;
            gap: 10px; align-items: center;
            padding: 6px 10px; border-radius: 6px;
            background: rgba(148, 163, 184, 0.06);
            font-size: 11px;
        }
        .bscs-signal--fired { background: rgba(239, 68, 68, 0.10); color: rgb(220, 38, 38); }
        .bscs-signal__label { font-weight: 500; }
        .bscs-signal__value { font-family: monospace; font-variant-numeric: tabular-nums; }
        .bscs-signal__threshold { font-family: monospace; font-size: 10px; opacity: 0.5; }
        .bscs-signal__dot { font-weight: 700; text-align: center; }

        .bscs-spark { grid-column: 2 / span 2; }
        .bscs-spark__title { font-size: 9px; letter-spacing: 0.16em; opacity: 0.55; margin-bottom: 4px; text-transform: uppercase; }
        .bscs-spark__bars { display: flex; align-items: flex-end; gap: 2px; height: 36px; }
        .bscs-spark__bar { flex: 1; min-width: 3px; border-radius: 2px; opacity: 0.85; background: rgb(16, 185, 129); }
        .bscs-spark__bar--elevated { background: rgb(245, 158, 11); }
        .bscs-spark__bar--fragile  { background: rgb(249, 115, 22); }
        .bscs-spark__bar--critical { background: rgb(239, 68, 68); }
        .bscs-spark__bar--unknown  { background: rgb(148, 163, 184); }

        .bscs-override { grid-column: 1 / -1; padding-top: 14px; border-top: 1px dashed rgba(148, 163, 184, 0.25); }
        .bscs-override__title { font-size: 11px; letter-spacing: 0.16em; text-transform: uppercase; opacity: 0.65; font-weight: 600; }
        .bscs-override__hint { font-size: 11px; opacity: 0.55; margin: 4px 0 10px; }
        .bscs-override__active { display: flex; align-items: center; justify-content: space-between; gap: 12px;
            padding: 10px 12px; border-radius: 8px; background: rgba(59, 130, 246, 0.08); border: 1px solid rgba(59, 130, 246, 0.25); }
        .bscs-override__active-title { font-size: 12px; font-weight: 600; color: rgb(30, 64, 175); }
        .bscs-override__active-reason { font-size: 11px; opacity: 0.7; font-style: italic; margin-top: 2px; color: rgb(30, 64, 175); }
        .bscs-override__form { display: grid; grid-template-columns: 110px 1fr auto; gap: 10px; align-items: end; }
        .bscs-override__field { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
        .bscs-override__field--grow { min-width: 0; }
        .bscs-override__field label { font-size: 10px; letter-spacing: 0.14em; text-transform: uppercase; opacity: 0.55; }
        .bscs-override__field input { padding: 6px 10px; border: 1px solid rgba(148, 163, 184, 0.40); border-radius: 6px; font-size: 12px; background: white; }
        .bscs-override__btn { padding: 8px 14px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; border: none; }
        .bscs-override__btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .bscs-override__btn--primary { background: rgb(16, 185, 129); color: white; }
        .bscs-override__btn--primary:hover:not(:disabled) { background: rgb(5, 150, 105); }
        .bscs-override__btn--secondary { background: rgba(59, 130, 246, 0.15); color: rgb(30, 64, 175); }
        .bscs-override__btn--secondary:hover:not(:disabled) { background: rgba(59, 130, 246, 0.25); }
        .bscs-override__error { margin-top: 8px; font-size: 11px; color: rgb(220, 38, 38); font-family: monospace; }

        /* Exchange Table */
        .exchange-table {
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        .exchange-table__header {
            display: grid;
            grid-template-columns: 1.4fr 0.8fr 0.8fr 1.2fr 0.6fr 0.6fr;
            gap: 6px;
            padding: 8px 14px;
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 0.05em;
            color: rgb(var(--ui-text-subtle));
            text-transform: uppercase;
            background: rgb(var(--ui-bg-elevated) / 0.5);
            border-bottom: 1px solid rgb(var(--ui-border) / 0.3);
        }
        .exchange-row {
            display: grid;
            grid-template-columns: 1.4fr 0.8fr 0.8fr 1.2fr 0.6fr 0.6fr;
            gap: 6px;
            padding: 8px 14px;
            align-items: center;
            border-bottom: 1px solid rgb(var(--ui-border) / 0.2);
            transition: background 0.15s;
        }
        .exchange-row:hover {
            background: rgb(var(--ui-bg-elevated) / 0.3);
        }
        .exchange-row:last-child { border-bottom: none; }
        .exchange-row__name {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 500;
            color: rgb(var(--ui-text));
        }
        .exchange-row__icon {
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 700;
            color: #000;
        }
        .exchange-row__num {
            font-family: 'JetBrains Mono', monospace;
            font-size: 13px;
            font-weight: 500;
            color: rgb(var(--ui-text-muted));
        }
        .exchange-row__num--highlight { color: rgb(var(--ui-text)); font-weight: 600; }
        .exchange-row__num--long { color: rgb(var(--ui-success)); }
        .exchange-row__num--short { color: rgb(var(--ui-danger)); }
        .exchange-row__rate {
            display: flex;
            align-items: center;
            gap: 6px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px;
            color: rgb(var(--ui-text-muted));
        }
        .mini-bar {
            flex: 1;
            height: 4px;
            background: rgb(var(--ui-bg-elevated));
            border-radius: 2px;
            overflow: hidden;
        }
        .mini-bar__fill {
            height: 100%;
            border-radius: 2px;
            transition: width 0.6s ease-out;
        }

        /* Responsive — Small screens */
        @media (max-width: 640px) {
            .exchange-table__header,
            .exchange-row {
                grid-template-columns: 1.2fr 0.8fr 0.8fr 1fr;
            }
            .exchange-table__header span:nth-child(5),
            .exchange-table__header span:nth-child(6),
            .exchange-row span:nth-child(5),
            .exchange-row span:nth-child(6) {
                display: none;
            }
        }
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
                cooldown: {
                    default: { is_paused: false, reason: null, paused_at: null, expires_in_seconds: null },
                    trading: { is_paused: false, reason: null, paused_at: null, expires_in_seconds: null },
                },
                togglingCoolingDown: { default: false, trading: false },

                bscsRelativeAge() {
                    const s = Number(this.bscs?.age_seconds);
                    if (!Number.isFinite(s) || s < 0) return '—';
                    if (s < 60) return s + 's ago';
                    if (s < 3600) return Math.floor(s / 60) + 'm ago';
                    return Math.floor(s / 3600) + 'h ago';
                },

                bscsFmtTime(iso) {
                    if (!iso) return '—';
                    try { return new Date(iso).toLocaleString(); } catch (_) { return iso; }
                },

                bscsOverrideReady() {
                    const h = Number(this.bscsOverrideHours);
                    return Number.isFinite(h) && h >= 0.5 && h <= 24
                        && (this.bscsOverrideReason ?? '').trim().length >= 3;
                },

                async bscsEngageOverride() {
                    this.bscsOverrideError = '';
                    if (!this.bscsOverrideReady()) {
                        this.bscsOverrideError = 'Hours must be 0.5–24 and reason must be at least 3 characters.';
                        return;
                    }
                    this.bscsOverrideBusy = true;
                    try {
                        const res = await window.hubUiFetch('{{ route('system.bscs.override.engage') }}', {
                            method: 'POST',
                            body: JSON.stringify({
                                hours: this.bscsOverrideHours,
                                reason: this.bscsOverrideReason.trim(),
                            }),
                        });
                        if (res.ok) {
                            this.bscsOverrideReason = '';
                            await this.fetchData();
                        } else {
                            this.bscsOverrideError = res.data?.error ?? 'Engage failed.';
                        }
                    } catch (e) {
                        this.bscsOverrideError = e.message;
                    } finally {
                        this.bscsOverrideBusy = false;
                    }
                },

                async bscsClearOverride() {
                    this.bscsOverrideError = '';
                    this.bscsOverrideBusy = true;
                    try {
                        const res = await window.hubUiFetch('{{ route('system.bscs.override.clear') }}', {
                            method: 'POST',
                        });
                        if (res.ok) {
                            await this.fetchData();
                        } else {
                            this.bscsOverrideError = res.data?.error ?? 'Clear failed.';
                        }
                    } catch (e) {
                        this.bscsOverrideError = e.message;
                    } finally {
                        this.bscsOverrideBusy = false;
                    }
                },

                // Floor at 1% when the numerator is positive — a non-zero
                // count rounding to 0% hides "something is running" signals
                // from operators at a glance.
                safePct(n, t) {
                    n = Number(n) || 0;
                    t = Number(t) || 0;
                    if (!t || n <= 0) return 0;
                    return Math.max(1, Math.round((n / t) * 100));
                },

                get tradeablePct() {
                    return this.safePct(this.stats.total_tradeable, this.stats.total_exchange_symbols);
                },

                get longPct() {
                    const longs = Number(this.stats.total_longs) || 0;
                    return this.safePct(longs, longs + (Number(this.stats.total_shorts) || 0));
                },

                get shortPct() {
                    const shorts = Number(this.stats.total_shorts) || 0;
                    return this.safePct(shorts, (Number(this.stats.total_longs) || 0) + shorts);
                },

                exTradeablePct(ex) {
                    return this.safePct(ex.tradeable, ex.total);
                },

                exchangeColor(canonical) {
                    const colors = { binance: '#F0B90B', bybit: '#F7A600', kucoin: '#23AF91', bitget: '#00B8D9', kraken: '#5741D9' };
                    return colors[canonical] || '#6366f1';
                },

                async fetchData() {
                    const [dashRes, coolingRes] = await Promise.all([
                        hubUiFetch('{{ route("system.dashboard.data") }}', { method: 'GET' }),
                        hubUiFetch('{{ route("system.steps.cooling-down") }}', { method: 'GET' }),
                    ]);

                    if (dashRes.ok) {
                        this.stats = dashRes.data;
                        this.exchanges = dashRes.data.exchanges;
                        this.bscs = dashRes.data.bscs ?? null;
                    }

                    if (coolingRes.ok && coolingRes.data) {
                        this.cooldown = {
                            default: coolingRes.data.default ?? this.cooldown.default,
                            trading: coolingRes.data.trading ?? this.cooldown.trading,
                        };
                    }

                    this.loading = false;
                },

                async toggleCoolingDown(slug) {
                    if (this.togglingCoolingDown[slug]) return;
                    this.togglingCoolingDown[slug] = true;

                    const previous = { ...this.cooldown[slug] };
                    this.cooldown[slug] = { ...previous, is_paused: !previous.is_paused };

                    const url = '{{ route("system.steps.toggle-cooling-down", ["prefix" => "__SLUG__"]) }}'.replace('__SLUG__', slug);
                    const { ok, data } = await hubUiFetch(url, { method: 'POST' });
                    if (ok && data) {
                        this.cooldown[slug] = {
                            is_paused: data.is_paused,
                            reason: data.reason ?? null,
                            paused_at: data.paused_at ?? null,
                            expires_in_seconds: data.expires_in_seconds ?? null,
                        };
                    } else {
                        this.cooldown[slug] = previous;
                    }
                    this.togglingCoolingDown[slug] = false;
                },
            };
        }

        function heartbeat() {
            return {
                loading: true,
                lastUpdated: null,
                _interval: null,
                _hasSnapshot: false,

                gauges: [
                    { label: 'CPU', percent: 0, delta: 0, detail: '—', sub: null },
                    { label: 'RAM', percent: 0, delta: 0, detail: '—', sub: null },
                    { label: 'HDD', percent: 0, delta: 0, detail: '—', sub: null },
                ],

                stepDispatcher: { running: false, total: 0, by_state: {}, last_tick: null },
                slowQueries: { last_hour_count: 0, recent: [] },

                async fetchData() {
                    const { ok, data } = await hubUiFetch('{{ route("system.dashboard.health") }}', { method: 'GET' });

                    if (ok) {
                        const s = data.server;
                        const ramPercent = s.ram_total_mb > 0 ? (s.ram_used_mb / s.ram_total_mb * 100) : 0;
                        const hddPercent = s.hdd_total_gb > 0 ? (s.hdd_used_gb / s.hdd_total_gb * 100) : 0;

                        const newGauges = [
                            { label: 'CPU', percent: s.cpu_percent, detail: 'load across 32 vCPU', sub: null },
                            { label: 'RAM', percent: Math.round(ramPercent * 10) / 10, detail: this.formatMb(s.ram_used_mb) + ' / ' + this.formatMb(s.ram_total_mb), sub: 'memory in use' },
                            { label: 'HDD', percent: Math.round(hddPercent * 10) / 10, detail: s.hdd_used_gb + ' / ' + s.hdd_total_gb + ' GB', sub: 'root filesystem' },
                        ];

                        this.gauges = newGauges.map((g, i) => {
                            const prev = this._hasSnapshot && this.gauges[i] ? this.gauges[i].percent : g.percent;
                            return { ...g, delta: Math.round((g.percent - prev) * 10) / 10 };
                        });
                        this._hasSnapshot = true;

                        this.stepDispatcher = data.step_dispatcher;
                        this.slowQueries = data.slow_queries;

                        this.lastUpdated = new Date().toLocaleTimeString();
                    }

                    this.loading = false;
                },

                startPolling() {
                    this._interval = setInterval(() => this.fetchData(), 5000);
                },

                formatMb(mb) {
                    if (mb >= 1024) return (mb / 1024).toFixed(1) + ' GB';
                    return Math.round(mb) + ' MB';
                },

                gaugeColor(percent) {
                    if (percent >= 80) return 'rgb(var(--ui-danger))';
                    if (percent >= 60) return 'rgb(var(--ui-warning))';
                    return 'rgb(var(--ui-success))';
                },

                stepStateColor(state) {
                    const map = {
                        'Running': 'rgb(var(--ui-warning))',
                        'Pending': 'rgb(var(--ui-info))',
                        'Failed': 'rgb(var(--ui-danger))',
                        'Completed': 'rgb(var(--ui-success))',
                    };
                    return map[state] || 'rgb(var(--ui-text-subtle))';
                },
            };
        }
    </script>
</x-app-layout>
