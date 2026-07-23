<x-app-layout active="projections-yearly" :title="'Kraite — Yearly projections'">
    <script>
        window.yearlyProjectionPage = (dataUrl) => {
            const TONES = {
                pessimistic: { label: 'Pessimistic', css: 'var(--pnl-down-fg)', activeText: '#fff' },
                neutral: { label: 'Neutral', css: 'var(--info)', activeText: '#fff' },
                optimistic: { label: 'Optimistic', css: 'var(--pnl-up-fg)', activeText: '#04140d' },
            };

            const cleanDecimal = (value) => {
                if (value == null) return null;
                const raw = String(value);
                const negative = raw.startsWith('-');
                const unsigned = negative ? raw.slice(1) : raw;
                const [wholeRaw, fraction = ''] = unsigned.split('.');
                const whole = wholeRaw.replace(/^0+(?=\d)/, '') || '0';

                return { negative, whole, fraction };
            };

            const compactDecimal = (value, decimals = 2) => {
                const parsed = cleanDecimal(value);
                if (!parsed) return '—';

                if (parsed.whole.length > 15) {
                    const tail = (parsed.whole.slice(1, 1 + decimals) || '').padEnd(decimals, '0');
                    return `${parsed.negative ? '−' : ''}${parsed.whole[0]}${decimals ? '.' + tail : ''}e+${parsed.whole.length - 1}`;
                }

                const numeric = Number(`${parsed.negative ? '-' : ''}${parsed.whole}.${parsed.fraction}`);
                return numeric.toLocaleString('en-US', {
                    minimumFractionDigits: decimals,
                    maximumFractionDigits: decimals,
                }).replace('-', '−');
            };

            return {
                dataUrl,
                scenario: 'neutral',
                loading: true,
                error: null,
                fetched: null,
                tones: TONES,
                scenarioOptions: ['pessimistic', 'neutral', 'optimistic'],

                init() {
                    this.load();
                },
                tone() {
                    return TONES[this.scenario];
                },
                selected() {
                    return this.fetched?.outlook?.scenarios?.[this.scenario] || null;
                },
                milestones() {
                    return this.selected()?.milestones || [];
                },
                finalMilestone() {
                    const rows = this.milestones();
                    return rows.length ? rows[rows.length - 1] : null;
                },
                money(value) {
                    const formatted = compactDecimal(value);
                    if (formatted === '—') return formatted;
                    return formatted.startsWith('−') ? `−$${formatted.slice(1)}` : `$${formatted}`;
                },
                signedMoney(value) {
                    const parsed = cleanDecimal(value);
                    if (!parsed) return '—';
                    const isZero = /^0*$/.test(parsed.whole) && /^0*$/.test(parsed.fraction);
                    const formatted = compactDecimal(value);

                    if (parsed.negative) return `−$${formatted.slice(1)}`;
                    return `${isZero ? '' : '+'}$${formatted}`;
                },
                percentage(value) {
                    const parsed = cleanDecimal(value);
                    if (!parsed) return '—';
                    const isZero = /^0*$/.test(parsed.whole) && /^0*$/.test(parsed.fraction);
                    const formatted = compactDecimal(value);

                    return `${parsed.negative ? '' : (isZero ? '' : '+')}${formatted}%`;
                },
                multiple(value) {
                    const formatted = compactDecimal(value);
                    return formatted === '—' ? formatted : `${formatted}×`;
                },
                dailyRate() {
                    const value = this.selected()?.daily_pct;
                    if (value == null) return '—';
                    return `${Number(value) >= 0 ? '+' : '−'}${Math.abs(Number(value) * 100).toFixed(2)}% / day`;
                },
                profitClass(value) {
                    return String(value || '0').startsWith('-') ? 'text-pnldown' : 'text-pnlup';
                },
                unavailableMessage() {
                    const reason = this.selected()?.reason;
                    if (reason === 'no_wallet') return 'No wallet history yet. Yearly planning starts after the first balance snapshot.';
                    if (reason === 'invalid_rate') return 'This scenario is outside a meaningful compounding range and cannot be projected.';
                    return 'Not enough realized trading data this month to build a yearly outlook.';
                },
                async load() {
                    this.loading = true;
                    this.error = null;

                    try {
                        const response = await fetch(this.dataUrl, {
                            headers: { Accept: 'application/json' },
                            signal: AbortSignal.timeout(8000),
                        });

                        if (!response.ok) {
                            throw new Error('Yearly projection could not be loaded.');
                        }

                        this.fetched = await response.json();
                    } catch (error) {
                        this.error = error?.message || 'Yearly projection could not be loaded.';
                    } finally {
                        this.loading = false;
                    }
                },
            };
        };
    </script>

    <div x-data="yearlyProjectionPage('{{ route('projections.yearly.data') }}')">
        <div class="flex items-end justify-between gap-5 pb-5 mb-6 border-b border-line max-[820px]:flex-col max-[820px]:items-start">
            <div>
                <div class="font-mono text-[11px] font-medium tracking-[0.12em] uppercase text-fg-3 mb-2 flex items-center gap-2">
                    <x-feathericon-trending-up class="w-[13px] h-[13px]" stroke-width="1.75"/>Performance
                </div>
                <h1 class="font-sans font-bold text-[28px] tracking-[-0.02em] text-fg-1 leading-[1.1] max-[640px]:text-[24px]">Yearly projections</h1>
                <div class="text-[13px] text-fg-3 mt-1.5">A five-year portfolio outlook if the bot keeps compounding at its currently observed pace.</div>
            </div>

            @unless($noPositions)
                <button type="button" @click="load()" :disabled="loading"
                        class="appearance-none font-sans font-semibold rounded-control border cursor-pointer inline-flex items-center gap-[7px] whitespace-nowrap transition-colors duration-fast ease-out active:translate-y-px h-[34px] px-3 text-[12px] bg-transparent text-fg-1 border-line-strong hover:bg-hover disabled:opacity-50">
                    <x-feathericon-refresh-cw class="w-[15px] h-[15px]" stroke-width="1.75" ::class="loading ? 'animate-spin' : ''"/>Sync
                </button>
            @endunless
        </div>

        @if($noPositions)
            <div class="card">
                <div class="flex flex-col items-center justify-center text-center py-[78px] px-5">
                    <div class="w-12 h-12 rounded-control border border-line flex items-center justify-center text-fg-mute mb-4">
                        <x-feathericon-calendar class="w-6 h-6" stroke-width="1.75"/>
                    </div>
                    <h4 class="font-sans font-semibold text-[19px] text-fg-1 leading-[1.2] tracking-[-0.01em] mb-1.5">Nothing to project yet</h4>
                    <p class="text-[13px] text-fg-3 max-w-[440px] m-0">The five-year outlook starts after the engine closes its first trade and establishes an observed daily return.</p>
                </div>
            </div>
        @else
            <template x-if="loading">
                <div class="flex flex-col gap-5">
                    <div class="card card--flat grid grid-cols-1 min-[720px]:grid-cols-4">
                        <template x-for="i in 4" :key="i">
                            <div class="flex flex-col gap-2.5 py-5 px-5 border-b border-line-soft min-[720px]:border-b-0 min-[720px]:border-r last:border-0">
                                <div class="animate-pulse-soft bg-surface-3 rounded-control h-2.5 w-2/3"></div>
                                <div class="animate-pulse-soft bg-surface-3 rounded-control h-6 w-4/5"></div>
                            </div>
                        </template>
                    </div>
                    <div class="card card--flat p-5">
                        <div class="grid grid-cols-1 min-[680px]:grid-cols-2 min-[1100px]:grid-cols-5 gap-3">
                            <template x-for="i in 5" :key="i">
                                <div class="animate-pulse-soft bg-surface-3 rounded-control h-[220px]"></div>
                            </template>
                        </div>
                    </div>
                </div>
            </template>

            <template x-if="!loading && error">
                <div class="card">
                    <div class="flex items-center gap-3 py-6 px-5">
                        <span class="text-danger"><x-feathericon-alert-triangle class="w-4 h-4" stroke-width="1.75"/></span>
                        <span class="text-[13px] text-fg-2" x-text="error"></span>
                    </div>
                </div>
            </template>

            <template x-if="!loading && !error && fetched">
                <div class="flex flex-col gap-5">
                    <div class="card card--flat">
                        <div class="grid grid-cols-1 min-[720px]:grid-cols-[1fr_0.8fr_0.8fr_1.6fr]">
                            <div class="flex flex-col gap-1.5 py-4 px-5 border-b border-line-soft min-[720px]:border-b-0 min-[720px]:border-r">
                                <span class="font-mono text-[9.5px] font-medium tracking-[0.09em] uppercase text-fg-mute">Portfolio now</span>
                                <span class="font-mono text-[23px] font-semibold leading-none tabular-nums tracking-[-0.02em] text-fg-1" x-text="money(fetched.current_wallet)"></span>
                                <span class="font-mono text-[9px] tracking-[0.05em] uppercase text-fg-mute">Compounding starts here</span>
                            </div>
                            <div class="flex flex-col gap-1.5 py-4 px-5 border-b border-line-soft min-[720px]:border-b-0 min-[720px]:border-r">
                                <span class="font-mono text-[9.5px] font-medium tracking-[0.09em] uppercase text-fg-mute">Accounts combined</span>
                                <span class="font-mono text-[23px] font-semibold leading-none tabular-nums tracking-[-0.02em] text-fg-1" x-text="fetched.account_count"></span>
                                <span class="font-mono text-[9px] tracking-[0.05em] uppercase text-fg-mute">Visible portfolio</span>
                            </div>
                            <div class="flex flex-col gap-1.5 py-4 px-5 border-b border-line-soft min-[720px]:border-b-0 min-[720px]:border-r">
                                <span class="font-mono text-[9.5px] font-medium tracking-[0.09em] uppercase text-fg-mute">Observed days</span>
                                <span class="font-mono text-[23px] font-semibold leading-none tabular-nums tracking-[-0.02em] text-fg-1" x-text="fetched.days_observed"></span>
                                <span class="font-mono text-[9px] tracking-[0.05em] uppercase text-fg-mute">Current month</span>
                            </div>
                            <div class="flex flex-col gap-2.5 py-4 px-5">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="font-mono text-[9.5px] font-medium tracking-[0.09em] uppercase text-fg-mute">Planning scenario</span>
                                    <span class="font-mono text-[10px] font-semibold tabular-nums" :style="`color: ${tone().css}`" x-text="dailyRate()"></span>
                                </div>
                                <div class="relative grid grid-cols-3 h-[38px] bg-surface-3 border border-line rounded-control">
                                    <span aria-hidden="true"
                                          :style="`left: ${(scenarioOptions.indexOf(scenario) * 100 / 3).toFixed(4)}%; margin-left: 3px; width: calc(33.3333% - 6px); background: ${tone().css}`"
                                          class="absolute top-[3px] bottom-[3px] rounded-[7px] pointer-events-none transition-[left,background-color] duration-slow ease-out"></span>
                                    <template x-for="option in scenarioOptions" :key="option">
                                        <button type="button" @click="scenario = option"
                                                class="appearance-none bg-transparent border-0 relative z-[1] px-1 font-mono text-[9.5px] font-semibold tracking-[0.04em] cursor-pointer transition-colors duration-fast"
                                                :style="`color: ${scenario === option ? tone().activeText : 'var(--fg-3)'}`"
                                                x-text="tones[option].label"></button>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card card--flat">
                        <div class="flex items-start justify-between gap-4 py-[15px] px-5 border-b border-line-soft max-[680px]:flex-col">
                            <div>
                                <div class="font-sans font-semibold text-[14px] text-fg-1 flex items-center gap-[9px]">
                                    <x-feathericon-compass class="w-4 h-4 text-fg-3" stroke-width="1.75"/>
                                    <span>Five-year capital horizon</span>
                                    @php
                                        $yearlyHelp = "Each milestone starts from today's combined wallet and compounds the selected daily rate through that year-end.\n\n**Pessimistic** uses the weakest observed trading day this month. **Neutral** is the midpoint between the weakest and strongest days. **Optimistic** uses the strongest day.\n\nThe model assumes the rate stays constant. Deposits, withdrawals, changing market conditions, and future strategy adjustments are not included.";
                                    @endphp
                                    <x-ui.help-dot title="Five-year capital horizon" :body="$yearlyHelp" tip="Projected portfolio value at each of the next five year-ends." />
                                </div>
                                <div class="text-[11.5px] text-fg-mute mt-1">Daily compounding from <span class="font-mono text-fg-3" x-text="fetched.today"></span></div>
                            </div>
                            <span class="inline-flex items-center gap-2 font-mono text-[9.5px] font-semibold tracking-[0.08em] uppercase px-2.5 py-1 rounded-chip bg-surface-3"
                                  :style="`color: ${tone().css}`">
                                <span class="w-1.5 h-1.5 rounded-chip" :style="`background: ${tone().css}`"></span>
                                <span x-text="tone().label + ' path'"></span>
                            </span>
                        </div>

                        <template x-if="selected()?.available">
                            <div class="relative">
                                <div aria-hidden="true"
                                     class="hidden min-[1100px]:block absolute top-[35px] left-[10%] right-[10%] h-px"
                                     :style="`background: linear-gradient(90deg, color-mix(in srgb, ${tone().css} 25%, transparent), ${tone().css}, color-mix(in srgb, ${tone().css} 25%, transparent))`"></div>

                                <div class="grid grid-cols-1 min-[680px]:grid-cols-2 min-[1100px]:grid-cols-5">
                                    <template x-for="(milestone, index) in milestones()" :key="milestone.year">
                                        <div class="relative flex flex-col min-w-0 py-5 px-5 border-b border-line-soft min-[680px]:odd:border-r min-[1100px]:border-b-0 min-[1100px]:border-r last:border-r-0">
                                            <div class="relative z-[1] flex items-center gap-2.5 mb-5">
                                                <span class="w-[29px] h-[29px] rounded-full border-2 bg-surface flex items-center justify-center font-mono text-[9px] font-bold tabular-nums"
                                                      :style="`border-color: ${tone().css}; color: ${tone().css}`"
                                                      x-text="String(index + 1).padStart(2, '0')"></span>
                                                <span class="flex flex-col min-w-0">
                                                    <span class="font-mono text-[10px] font-semibold tracking-[0.07em] uppercase text-fg-2" x-text="milestone.label"></span>
                                                    <span class="font-mono text-[9px] text-fg-mute" x-text="'31 Dec ' + milestone.year"></span>
                                                </span>
                                            </div>

                                            <span class="font-mono text-[9.5px] font-medium tracking-[0.09em] uppercase text-fg-mute">Projected portfolio</span>
                                            <span class="font-mono text-[20px] font-semibold leading-[1.1] tabular-nums tracking-[-0.025em] break-all mt-1.5"
                                                  :style="`color: ${tone().css}`"
                                                  x-text="money(milestone.end_wallet)"></span>

                                            <div class="flex flex-col gap-2.5 mt-5 pt-4 border-t border-line-soft">
                                                <div class="flex flex-col gap-0.5">
                                                    <span class="font-mono text-[8.5px] tracking-[0.08em] uppercase text-fg-mute">Profit from today</span>
                                                    <span class="font-mono text-[12px] font-semibold tabular-nums break-all"
                                                          :class="profitClass(milestone.projected_profit)"
                                                          x-text="signedMoney(milestone.projected_profit)"></span>
                                                </div>
                                                <div class="grid grid-cols-2 gap-2">
                                                    <div class="flex flex-col gap-0.5">
                                                        <span class="font-mono text-[8.5px] tracking-[0.08em] uppercase text-fg-mute">Growth</span>
                                                        <span class="font-mono text-[11px] font-semibold tabular-nums text-fg-2" x-text="percentage(milestone.growth_pct)"></span>
                                                    </div>
                                                    <div class="flex flex-col gap-0.5">
                                                        <span class="font-mono text-[8.5px] tracking-[0.08em] uppercase text-fg-mute">Multiple</span>
                                                        <span class="font-mono text-[11px] font-semibold tabular-nums text-fg-2" x-text="multiple(milestone.multiple)"></span>
                                                    </div>
                                                </div>
                                                <span class="font-mono text-[8.5px] tracking-[0.06em] uppercase text-fg-faint" x-text="milestone.days.toLocaleString('en-US') + ' compounding days'"></span>
                                            </div>
                                        </div>
                                    </template>
                                </div>

                                <div class="flex items-center gap-3 py-[13px] px-5 border-t border-line-soft bg-surface-2 max-[680px]:items-start">
                                    <span class="flex-shrink-0" :style="`color: ${tone().css}`">
                                        <x-feathericon-flag class="w-[15px] h-[15px]" stroke-width="1.75"/>
                                    </span>
                                    <span class="text-[12px] leading-[1.5] text-fg-3">
                                        At the final horizon, the model reaches
                                        <span class="font-mono font-semibold text-fg-1" x-text="money(finalMilestone()?.end_wallet)"></span>
                                        — including
                                        <span class="font-mono font-semibold" :class="profitClass(finalMilestone()?.projected_profit)" x-text="signedMoney(finalMilestone()?.projected_profit)"></span>
                                        projected profit from today.
                                    </span>
                                </div>
                            </div>
                        </template>

                        <template x-if="!selected()?.available">
                            <div class="flex items-center gap-3 py-7 px-5">
                                <span class="flex-shrink-0 text-warn"><x-feathericon-alert-triangle class="w-4 h-4" stroke-width="1.75"/></span>
                                <span class="text-[12.5px] text-fg-3" x-text="unavailableMessage()"></span>
                            </div>
                        </template>
                    </div>

                    <div class="flex items-start gap-2.5 px-1">
                        <x-feathericon-info class="w-3.5 h-3.5 text-fg-mute flex-shrink-0 mt-0.5" stroke-width="1.75"/>
                        <p class="m-0 text-[10.5px] leading-[1.5] text-fg-mute">Planning estimate only. It extends the current month's observed daily performance unchanged and does not guarantee future returns.</p>
                    </div>
                </div>
            </template>
        @endif
    </div>
</x-app-layout>
