<x-app-layout>
    <div x-data="adminDashboard()" x-init="fetchData()">
        <div x-show="loading" class="flex items-center justify-center py-20">
            <x-hub-ui::spinner size="lg" />
        </div>

        <div x-show="!loading" x-cloak class="dash-layout">
            {{-- System State — cooling-down toggle --}}
            <div
                class="ui-card flex items-center justify-between gap-4 px-5 py-4 flex-wrap"
                :style="isCoolingDown ? 'border-color: rgb(var(--ui-warning) / 0.5); background-color: rgb(var(--ui-warning) / 0.08)' : ''"
            >
                <div class="flex items-center gap-3 min-w-0">
                    <template x-if="isCoolingDown">
                        <x-hub-ui::pulse-dot type="warning" :pulse="true" size="md" />
                    </template>
                    <template x-if="!isCoolingDown">
                        <x-hub-ui::pulse-dot type="success" size="md" />
                    </template>
                    <div class="min-w-0">
                        <div class="text-sm font-semibold ui-text">Cooling Down</div>
                        <p class="text-xs ui-text-subtle mt-0.5" x-text="isCoolingDown ? 'Scheduled commands are paused — cron-gated jobs will skip until disabled.' : 'Scheduled commands are active and running on cadence.'"></p>
                    </div>
                </div>
                <x-hub-ui::switch
                    state="isCoolingDown"
                    @click="toggleCoolingDown()"
                    onColor="warning"
                    size="md"
                    x-bind:class="togglingCoolingDown ? 'opacity-50 pointer-events-none' : ''"
                />
            </div>

            {{-- Dashboard Grid --}}
            <div class="dash-grid">

                {{-- Main Gauge Panel --}}
                <div class="dash-panel dash-panel--hero">
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

                {{-- Direction Panel --}}
                <div class="dash-panel dash-panel--direction">
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

                {{-- Stats Strip --}}
                <div class="dash-panel dash-panel--stats">
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

                {{-- Exchanges Table --}}
                <div class="dash-panel dash-panel--exchanges">
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

            {{-- Scheduler Tile --}}
            <x-dashboard.scheduler-tile />
        </div>
    </div>

    <style>
        /* Dashboard Grid */
        .dash-grid {
            display: grid;
            grid-template-columns: 190px 1fr;
            grid-template-rows: auto auto auto;
            gap: 1px;
            background: rgb(var(--ui-border) / 0.5);
            border: 1px solid rgb(var(--ui-border));
            border-radius: 10px;
            overflow: hidden;
        }

        /* Panel Base */
        .dash-panel {
            background: rgb(var(--ui-bg-card));
            position: relative;
        }
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

        /* Hero Panel */
        .dash-panel--hero {
            grid-row: span 2;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 16px;
            background: linear-gradient(145deg, rgb(var(--ui-bg-card)), rgb(var(--ui-bg-elevated) / 0.5));
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
        .dash-panel--direction {
            display: flex;
            flex-direction: column;
        }
        .direction-display {
            flex: 1;
            display: flex;
            align-items: center;
            padding: 10px 14px;
            gap: 12px;
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

        /* Stats Strip */
        .dash-panel--stats {
            grid-column: span 2;
            display: flex;
        }
        .stat-item {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 10px 12px;
            gap: 2px;
            border-right: 1px solid rgb(var(--ui-border) / 0.3);
        }
        .stat-item:last-child { border-right: none; }
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

        /* Exchanges Panel */
        .dash-panel--exchanges {
            grid-column: span 2;
            display: flex;
            flex-direction: column;
        }

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

        /* Dashboard Layout — Mobile First */
        .dash-layout {
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px;
        }

        /* Tablet+ (3 column grid, each tile 1/3) */
        @media (min-width: 1024px) {
            .dash-layout {
                grid-template-columns: repeat(3, 1fr);
                align-items: start;
            }
        }

        /* Responsive — Small screens */
        @media (max-width: 640px) {
            .dash-grid {
                grid-template-columns: 1fr;
                max-width: 100%;
            }
            .dash-panel--hero { grid-row: auto; }
            .dash-panel--stats { grid-column: 1; flex-wrap: wrap; }
            .stat-item { min-width: 50%; }
            .dash-panel--exchanges { grid-column: 1; }
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
                schedule: [],
                isCoolingDown: false,
                togglingCoolingDown: false,

                get tradeablePct() {
                    const total = this.stats.total_exchange_symbols || 0;
                    return total ? Math.round((this.stats.total_tradeable / total) * 100) : 0;
                },

                get longPct() {
                    const total = (this.stats.total_longs || 0) + (this.stats.total_shorts || 0);
                    return total ? Math.round((this.stats.total_longs / total) * 100) : 0;
                },

                get shortPct() {
                    const total = (this.stats.total_longs || 0) + (this.stats.total_shorts || 0);
                    return total ? Math.round((this.stats.total_shorts / total) * 100) : 0;
                },

                exTradeablePct(ex) {
                    return ex.total ? Math.round((ex.tradeable / ex.total) * 100) : 0;
                },

                exchangeColor(canonical) {
                    const colors = { binance: '#F0B90B', bybit: '#F7A600', kucoin: '#23AF91', bitget: '#00B8D9', kraken: '#5741D9' };
                    return colors[canonical] || '#6366f1';
                },

                async fetchData() {
                    const [dashRes, coolingRes] = await Promise.all([
                        hubUiFetch('{{ route("dashboard.data") }}', { method: 'GET' }),
                        hubUiFetch('{{ route("system.step-dispatcher.cooling-down") }}', { method: 'GET' }),
                    ]);

                    if (dashRes.ok) {
                        this.stats = dashRes.data;
                        this.exchanges = dashRes.data.exchanges;
                        this.schedule = dashRes.data.schedule || [];
                    }

                    if (coolingRes.ok) {
                        this.isCoolingDown = coolingRes.data.is_cooling_down;
                    }

                    this.loading = false;
                },

                async toggleCoolingDown() {
                    if (this.togglingCoolingDown) return;
                    this.togglingCoolingDown = true;

                    const previous = this.isCoolingDown;
                    this.isCoolingDown = !this.isCoolingDown;

                    const { ok, data } = await hubUiFetch('{{ route("system.step-dispatcher.toggle-cooling-down") }}');
                    if (ok) {
                        this.isCoolingDown = data.is_cooling_down;
                    } else {
                        this.isCoolingDown = previous;
                    }
                    this.togglingCoolingDown = false;
                },
            };
        }
    </script>
</x-app-layout>
