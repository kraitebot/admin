<x-app-layout :activeHighlight="'bscs'">
    <div x-data="bscsPage()" x-init="init()" class="pb-12">

        <x-hub-ui::page-header
            title="Black Swan Composite Score"
            description="Live regime detection · updates hourly at :50 UTC"
        >
            <x-slot:icon>
                <span class="inline-flex items-center justify-center w-11 h-11 rounded-xl"
                      :style="bandBgStyle()">
                    <svg class="w-6 h-6" :style="bandFgStyle()" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z" />
                    </svg>
                </span>
            </x-slot:icon>
            <x-slot:actions>
                <button type="button"
                        @click="openModal('overview')"
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-semibold ui-text-muted hover:ui-text border ui-border hover:ui-bg-elevated transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Learn how it works
                </button>
            </x-slot:actions>
        </x-hub-ui::page-header>

        {{-- Top row: hero + timeline side-by-side --}}
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 mb-4">
            {{-- Hero --}}
            <div class="ui-card p-5 lg:col-span-5">
                <div class="flex items-center justify-between gap-3 mb-4">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 ui-text-subtle" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" /></svg>
                        <span class="text-[11px] uppercase tracking-[0.18em] ui-text-subtle font-semibold">Current state</span>
                    </div>
                    <span class="text-[11px] font-mono ui-text-muted" x-text="payload?.is_stale ? '⚠ stale' : ('synced ' + relativeAge())"></span>
                </div>
                <div class="flex flex-col items-center text-center rounded-2xl py-6 px-4"
                     :style="bandBgStyle()">
                    <div class="text-[72px] font-bold leading-none font-mono ui-tabular"
                         :style="bandFgStyle()"
                         x-text="payload?.score ?? '—'"></div>
                    <div class="mt-2 text-xs tracking-[0.20em] uppercase font-bold"
                         :style="bandFgStyle()"
                         x-text="(payload?.band ?? 'no data').toUpperCase()"></div>
                    <div class="mt-1 text-[10px] ui-text-subtle font-mono"
                         x-text="'block @ ' + (payload?.cooldown_threshold ?? 80)"></div>
                </div>
                <div class="mt-4 inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-[11px] font-semibold w-full justify-center"
                     :class="payload?.should_block_opens ? 'bg-red-50 text-red-700' : 'bg-emerald-50 text-emerald-700'">
                    <span class="w-2 h-2 rounded-full" :class="payload?.should_block_opens ? 'bg-red-600' : 'bg-emerald-600'"></span>
                    <span x-text="payload?.should_block_opens ? 'New opens BLOCKED' : 'New opens flowing'"></span>
                </div>
                <p class="mt-3 text-sm ui-text leading-relaxed text-center" x-text="bandPlainEnglish()"></p>
                <div x-show="payload?.cooldown_active" class="mt-2 text-[11px] ui-text-muted font-mono text-center">
                    cooldown until <span x-text="fmtTime(payload?.cooldown_until)"></span>
                </div>
            </div>

            {{-- Timeline --}}
            <div class="ui-card p-5 lg:col-span-7 flex flex-col">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 ui-text-subtle" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" /></svg>
                        <h2 class="text-sm font-semibold ui-text">30-day score timeline</h2>
                    </div>
                    <span class="text-[11px] ui-text-subtle font-mono" x-text="(payload?.timeline ?? []).length + ' points'"></span>
                </div>
                <div class="flex-1 relative h-40 flex items-end gap-px overflow-hidden rounded bg-gray-50 px-1.5 py-1.5">
                    <template x-for="(p, i) in (payload?.timeline ?? [])" :key="i">
                        <div class="flex-1 min-w-0 rounded-t opacity-90 transition-opacity hover:opacity-100"
                             :style="'height:' + Math.max(2, p.score) + '%'"
                             :class="bandBarClass(p.band)"
                             :title="p.t + '  ·  score ' + p.score + '  ·  ' + p.band"></div>
                    </template>
                </div>
                <div class="mt-3 flex flex-wrap gap-3 text-[11px] ui-text-muted">
                    <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-sm bg-emerald-500"></span>Calm</span>
                    <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-sm bg-amber-500"></span>Elevated</span>
                    <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-sm bg-orange-500"></span>Fragile</span>
                    <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-sm bg-red-500"></span>Critical</span>
                </div>
            </div>
        </div>

        {{-- Soft posture banner (Fragile only) --}}
        <div x-show="payload?.band === 'fragile'"
             class="rounded-xl border border-orange-300 bg-orange-50 p-4 mb-4 flex items-start gap-3">
            <svg class="w-9 h-9 text-orange-600 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
            <div class="flex-1">
                <div class="text-sm font-semibold text-orange-900">Soft posture active</div>
                <p class="text-[13px] text-orange-900/85 leading-relaxed mt-0.5">
                    New positions would open at <strong x-text="fragileMultiplier()"></strong> of base size —
                    a <strong x-text="fragileReduction() + '%'"></strong> reduction. Existing positions are not resized.
                </p>
            </div>
        </div>

        {{-- Override-active banner --}}
        <div x-show="payload?.override_active"
             class="rounded-xl border border-blue-300 bg-blue-50 p-4 mb-4 flex items-start gap-3">
            <svg class="w-9 h-9 text-blue-600 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75l3 3m0 0l3-3m-3 3v-7.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            <div class="flex-1">
                <div class="text-sm font-semibold text-blue-900">Manual override active</div>
                <div class="text-[13px] text-blue-900/85 mt-0.5">
                    System cooldown bypassed until <span class="font-mono font-semibold" x-text="fmtTime(payload?.override_until)"></span>
                </div>
                <div x-show="payload?.override_reason" class="text-[12px] text-blue-900/70 mt-1 italic">
                    Reason: "<span x-text="payload?.override_reason"></span>"
                </div>
            </div>
        </div>

        {{-- 5 sub-signals as a 2-3 column grid of icon-led cards --}}
        <div class="mb-4">
            <div class="flex items-center justify-between mb-3 px-1">
                <div class="flex items-center gap-2">
                    <svg class="w-4 h-4 ui-text-subtle" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25m18 0A2.25 2.25 0 0018.75 3H5.25A2.25 2.25 0 003 5.25m18 0V12a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 12V5.25" /></svg>
                    <h2 class="text-sm font-semibold ui-text">5 sub-signals</h2>
                </div>
                <span class="text-[11px] ui-text-subtle">click any card for the detailed explanation</span>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                <template x-for="sig in signalDocs" :key="sig.key">
                    <button type="button"
                            @click="openModal(sig.key)"
                            :class="payload?.sub_signals?.[sig.key]?.fired
                                ? 'bg-red-50 border-red-200 hover:bg-red-100 hover:border-red-300'
                                : 'bg-white border-gray-200 hover:border-gray-300 hover:shadow-sm'"
                            class="text-left rounded-xl border p-4 transition-all">
                        <div class="flex items-center justify-between mb-3">
                            <span class="inline-flex items-center justify-center w-10 h-10 rounded-lg"
                                  :class="payload?.sub_signals?.[sig.key]?.fired ? 'bg-red-200/60 text-red-700' : 'bg-gray-100 text-gray-600'">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24" x-html="sig.iconPath"></svg>
                            </span>
                            <span class="inline-flex items-center justify-center w-6 h-6 rounded-full text-[11px] font-bold"
                                  :class="payload?.sub_signals?.[sig.key]?.fired ? 'bg-red-600 text-white' : 'bg-gray-200 text-gray-500'"
                                  x-text="payload?.sub_signals?.[sig.key]?.fired ? '✓' : '·'"></span>
                        </div>
                        <div class="font-semibold ui-text text-sm" x-text="sig.title"></div>
                        <p class="text-[11.5px] ui-text-muted mt-1 leading-snug line-clamp-2" x-text="sig.what"></p>
                        <div class="mt-3 flex items-baseline justify-between gap-2 pt-3 border-t ui-border">
                            <div>
                                <div class="text-[10px] uppercase tracking-wider ui-text-subtle">Current</div>
                                <div class="text-base font-mono font-bold ui-tabular"
                                     :class="payload?.sub_signals?.[sig.key]?.fired ? 'text-red-700' : 'ui-text'"
                                     x-text="payload?.sub_signals?.[sig.key]?.value ?? '—'"></div>
                            </div>
                            <div class="text-right">
                                <div class="text-[10px] uppercase tracking-wider ui-text-subtle">Fires when</div>
                                <div class="text-base font-mono ui-text-muted ui-tabular" x-text="sig.threshold"></div>
                            </div>
                        </div>
                    </button>
                </template>
            </div>
        </div>

        {{-- 4 bands quick reference --}}
        <div class="mb-4">
            <div class="flex items-center justify-between mb-3 px-1">
                <div class="flex items-center gap-2">
                    <svg class="w-4 h-4 ui-text-subtle" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
                    <h2 class="text-sm font-semibold ui-text">The 4 bands</h2>
                </div>
                <span class="text-[11px] ui-text-subtle">click any band for full detail</span>
            </div>
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                @php
                    $bands = [
                        ['key' => 'band_calm',     'name' => 'Calm',     'range' => '0–39',   'tone' => 'emerald',
                         'icon' => 'M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0'],
                        ['key' => 'band_elevated', 'name' => 'Elevated', 'range' => '40–59',  'tone' => 'amber',
                         'icon' => 'M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178zM15 12a3 3 0 11-6 0 3 3 0 016 0z'],
                        ['key' => 'band_fragile',  'name' => 'Fragile',  'range' => '60–79',  'tone' => 'orange',
                         'icon' => 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z'],
                        ['key' => 'band_critical', 'name' => 'Critical', 'range' => '80–100', 'tone' => 'red',
                         'icon' => 'M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z'],
                    ];
                @endphp
                @foreach ($bands as $b)
                    <button type="button"
                            @click="openModal('{{ $b['key'] }}')"
                            class="rounded-xl border bg-{{ $b['tone'] }}-50 border-{{ $b['tone'] }}-200 hover:bg-{{ $b['tone'] }}-100 hover:border-{{ $b['tone'] }}-300 p-4 text-left transition-colors">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex items-center justify-center w-10 h-10 rounded-lg bg-{{ $b['tone'] }}-200/60 text-{{ $b['tone'] }}-700 flex-shrink-0">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $b['icon'] }}" /></svg>
                            </span>
                            <div>
                                <div class="text-sm font-bold text-{{ $b['tone'] }}-900">{{ $b['name'] }}</div>
                                <div class="text-[11px] font-mono text-{{ $b['tone'] }}-700 opacity-80">Score {{ $b['range'] }}</div>
                            </div>
                        </div>
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Universal modal --}}
        <div x-show="activeModal" x-cloak
             @keydown.escape.window="closeModal()"
             class="fixed inset-0 z-50 flex items-center justify-center px-4 py-6">
            <div class="absolute inset-0 bg-black/60" @click="closeModal()"></div>
            <div class="relative bg-white rounded-xl shadow-xl max-w-3xl w-full max-h-[90vh] overflow-y-auto">
                <div class="sticky top-0 bg-white border-b ui-border px-6 py-4 flex items-center justify-between">
                    <h3 class="text-base font-semibold ui-text" x-text="modalTitle()"></h3>
                    <button type="button" @click="closeModal()"
                            class="ui-text-muted hover:ui-text transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <div class="p-6">
                    {{-- Per-signal modals --}}
                    <template x-if="signalDocs.find(s => s.key === activeModal)">
                        <div class="space-y-4 text-sm ui-text leading-relaxed">
                            <template x-for="sig in [signalDocs.find(s => s.key === activeModal)]" :key="sig.key">
                                <div>
                                    <div class="grid grid-cols-2 gap-3 mb-4">
                                        <div class="rounded bg-gray-50 p-3">
                                            <div class="text-[10px] uppercase tracking-wider ui-text-subtle">Current value</div>
                                            <div class="font-mono font-semibold text-lg ui-tabular" x-text="payload?.sub_signals?.[sig.key]?.value ?? '—'"></div>
                                        </div>
                                        <div class="rounded bg-gray-50 p-3">
                                            <div class="text-[10px] uppercase tracking-wider ui-text-subtle">Fires when</div>
                                            <div class="font-mono font-semibold text-lg ui-tabular" x-text="sig.threshold"></div>
                                        </div>
                                    </div>
                                    <h4 class="font-semibold ui-text mt-4 mb-1">What it measures</h4>
                                    <p x-text="sig.what"></p>
                                    <h4 class="font-semibold ui-text mt-4 mb-1">Why it matters</h4>
                                    <p x-text="sig.why"></p>
                                    <h4 class="font-semibold ui-text mt-4 mb-1">Formula (precise)</h4>
                                    <pre class="bg-gray-50 p-3 rounded text-[12px] font-mono whitespace-pre-wrap" x-text="sig.formula"></pre>
                                    <h4 class="font-semibold ui-text mt-4 mb-1">Default threshold</h4>
                                    <p>
                                        <code class="bg-gray-50 px-1.5 py-0.5 rounded" x-text="sig.threshold"></code> —
                                        <span x-text="sig.thresholdRationale"></span>
                                    </p>
                                </div>
                            </template>
                        </div>
                    </template>

                    {{-- Per-band modals --}}
                    <template x-if="['band_calm','band_elevated','band_fragile','band_critical'].includes(activeModal)">
                        <div class="text-sm ui-text leading-relaxed space-y-3">
                            <div :class="bandModalCardClass()" class="rounded-lg p-4 border">
                                <div class="flex items-baseline justify-between">
                                    <span class="font-bold text-lg" x-text="bandModalData().name"></span>
                                    <span class="font-mono text-sm" x-text="'Score ' + bandModalData().range"></span>
                                </div>
                            </div>
                            <h4 class="font-semibold ui-text">What it means</h4>
                            <p x-text="bandModalData().what"></p>
                            <h4 class="font-semibold ui-text">What the bot does</h4>
                            <p x-text="bandModalData().action"></p>
                            <h4 class="font-semibold ui-text">When does this band trigger?</h4>
                            <p x-text="bandModalData().trigger"></p>
                        </div>
                    </template>

                    {{-- Overview / knowledge base --}}
                    <template x-if="activeModal === 'overview'">
                        <div class="text-sm ui-text leading-relaxed space-y-6">
                            <section>
                                <h4 class="font-semibold ui-text text-base mb-2">In plain English</h4>
                                <p>
                                    BSCS is a single number from <strong>0</strong> (everything calm) to <strong>100</strong> (cascade about to happen)
                                    that the system computes once an hour by looking at five different angles of the crypto market.
                                </p>
                                <p class="mt-2">
                                    BSCS does <em>not</em> predict <em>what</em> will trigger a crash. What it <em>can</em> see is the
                                    <strong>fragility setup</strong> — the conditions that turn any spark into a forest fire.
                                    When too many of those conditions stack up, the bot pauses opening new positions for 24 hours.
                                </p>
                                <p class="mt-2 ui-text-muted">
                                    Existing positions are never touched by BSCS — no auto-close, no SL move.
                                </p>
                            </section>

                            <section>
                                <h4 class="font-semibold ui-text text-base mb-2">How the score is built</h4>
                                <ul class="list-disc pl-5 space-y-1">
                                    <li>5 signals, each binary: <strong>fire (+20)</strong> or <strong>quiet (+0)</strong>. Sum → 0 to 100 in steps of 20.</li>
                                    <li>Computed <strong>once per hour at :50 UTC</strong>.</li>
                                    <li>Reference set: fixed basket <strong>BTCUSDT, ETHUSDT, SOLUSDT, BNBUSDT, XRPUSDT</strong> on 1h candles.</li>
                                    <li>Baseline window: <strong>14 days</strong>.</li>
                                </ul>
                            </section>

                            <section>
                                <h4 class="font-semibold ui-text text-base mb-2">How the cooldown works</h4>
                                <ol class="list-decimal pl-5 space-y-1">
                                    <li>Each hour, the score is computed.</li>
                                    <li>If score reaches <strong>≥ 80</strong>, a <strong>24-hour cooldown</strong> is armed: bot stops opening new positions.</li>
                                    <li>After 24h, the system re-checks: still high → another 24h; recovered → cooldown lifts.</li>
                                    <li>An operator can manually <strong>override</strong> for up to 24h. Auto-expires.</li>
                                </ol>
                            </section>

                            <section>
                                <h4 class="font-semibold ui-text text-base mb-2">Fragile margin scaling</h4>
                                <p>Score 60 = full size. Score 79 = half size. Linear in between:</p>
                                <pre class="bg-gray-50 p-3 rounded text-[12px] font-mono mt-2">reduction% = (score − 60) ÷ 19 × 50
margin_slice = base × (1 − reduction% ÷ 100)</pre>
                                <table class="w-full text-[12px] font-mono mt-3">
                                    <thead class="text-left ui-text-subtle"><tr><th class="py-1 pr-3">Score</th><th class="py-1 pr-3">Reduction</th><th class="py-1">Slice</th></tr></thead>
                                    <tbody class="ui-text">
                                        <tr class="border-t"><td class="py-1.5 pr-3">60</td><td class="py-1.5 pr-3">0%</td><td class="py-1.5">1.00× base</td></tr>
                                        <tr class="border-t"><td class="py-1.5 pr-3">70</td><td class="py-1.5 pr-3">~26%</td><td class="py-1.5">0.74× base</td></tr>
                                        <tr class="border-t"><td class="py-1.5 pr-3">79</td><td class="py-1.5 pr-3">~50%</td><td class="py-1.5">0.50× base</td></tr>
                                    </tbody>
                                </table>
                            </section>

                            <section>
                                <h4 class="font-semibold ui-text text-base mb-2">Where the signals came from</h4>
                                <p>The five signals were validated against six confirmed crypto black swan events spanning five years.</p>
                                <table class="w-full text-[12px] font-mono mt-2">
                                    <thead class="text-left ui-text-subtle"><tr><th class="py-1 pr-3">Event</th><th class="py-1 pr-3">Drop</th><th class="py-1">BSCS @ T-6h</th></tr></thead>
                                    <tbody class="ui-text">
                                        <tr class="border-t"><td class="py-1.5 pr-3">2020-03 COVID</td><td class="py-1.5 pr-3">-39%</td><td class="py-1.5 text-red-700 font-bold">100%</td></tr>
                                        <tr class="border-t"><td class="py-1.5 pr-3">2021-05 China ban</td><td class="py-1.5 pr-3">-30%</td><td class="py-1.5 text-red-700 font-bold">100%</td></tr>
                                        <tr class="border-t"><td class="py-1.5 pr-3">2022-06 Celsius/LUNA</td><td class="py-1.5 pr-3">-32%</td><td class="py-1.5 text-amber-700 font-bold">60%</td></tr>
                                        <tr class="border-t"><td class="py-1.5 pr-3">2022-11 FTX</td><td class="py-1.5 pr-3">-22%</td><td class="py-1.5 text-red-700 font-bold">100%</td></tr>
                                        <tr class="border-t"><td class="py-1.5 pr-3">2024-08 BoJ unwind</td><td class="py-1.5 pr-3">-15.5%</td><td class="py-1.5 text-red-700 font-bold">100%</td></tr>
                                        <tr class="border-t"><td class="py-1.5 pr-3">2025-10 Trump tariffs</td><td class="py-1.5 pr-3">-16%</td><td class="py-1.5 text-red-700 font-bold">100%</td></tr>
                                    </tbody>
                                </table>
                            </section>

                            <section>
                                <h4 class="font-semibold ui-text text-base mb-2">False positive rate</h4>
                                <p>
                                    ~70% of <strong>≥80</strong> episodes do <em>not</em> lead to a cascade. Accepted because the action is "don't open new positions",
                                    not "realised loss". A false positive costs an opportunity window. A true positive avoids a 6+ position correlated drawdown.
                                </p>
                            </section>

                            <section>
                                <h4 class="font-semibold ui-text text-base mb-2">Signals tested and dropped</h4>
                                <ul class="list-disc pl-5 space-y-1">
                                    <li><strong>Funding rate</strong> — stayed near zero in all 6 events. Fires for endogenous squeezes; not news shocks.</li>
                                    <li><strong>Fear &amp; Greed Index</strong> — directionally inconsistent across events.</li>
                                    <li><strong>Taker buy/sell imbalance</strong> — pure noise (0.49–0.50 across the entire pre-event window).</li>
                                </ul>
                            </section>

                            <section>
                                <h4 class="font-semibold ui-text text-base mb-2">If something goes wrong</h4>
                                <p>
                                    If the hourly compute fails, the score gets stale. The system <strong>fails OPEN</strong> — opens are allowed,
                                    a warning is logged. Missing a pause is preferable to a permanent halt on broken telemetry.
                                </p>
                            </section>
                        </div>
                    </template>
                </div>
            </div>
        </div>

    </div>

    <script>
        function bscsPage() {
            return {
                payload: null,
                activeModal: null,
                _timer: null,

                signalDocs: [
                    {
                        key: 'vol_expansion',
                        title: 'Volatility Expansion',
                        threshold: '> 1.30',
                        iconPath: '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 010 3.75H5.625a1.875 1.875 0 010-3.75z" />',
                        what: 'Compares how jittery BTC has been in the last 24 hours vs its average jitteriness over the prior 14 days.',
                        why: 'Volatility clusters. Once BTC starts moving more violently than usual, that violence tends to feed itself — high vol breeds higher vol. This signal catches the early build-up before the cascade.',
                        formula: 'stdev(BTC 1h log-returns over last 24 bars) ÷ stdev(BTC 1h log-returns over last 14d × 24 bars)',
                        thresholdRationale: 'A 30% jump above the trailing baseline is enough to flag a regime shift without firing on benign drifts.',
                    },
                    {
                        key: 'range_blowout',
                        title: 'Range Blowout',
                        threshold: '> 1.50',
                        iconPath: '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15" />',
                        what: 'Compares today\'s BTC daily range (high-low as % of low) to the average daily range over the past 14 days.',
                        why: 'A sudden daily range much wider than recent history is the textbook fingerprint of a cascade in progress — once dump candles start printing, this fires immediately.',
                        formula: '(BTC last-24h max high − last-24h min low) / last-24h min low ÷ mean of the same per-day metric across the prior 14 daily 24h windows',
                        thresholdRationale: '1.50× the trailing daily range = today is 50% wider than typical. Fires once intraday volatility decisively breaks out.',
                    },
                    {
                        key: 'corr_regime',
                        title: 'Correlation Regime',
                        threshold: '> 0.70',
                        iconPath: '<path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244" />',
                        what: 'Average pairwise correlation across BTC, ETH, SOL, BNB, XRP over the last 48 hours.',
                        why: 'In calm regimes, alts drift independently. In risk-off cascades, everything dumps together — that\'s the "correlation goes to 1" effect. High correlation across the basket = the market is acting as one frightened animal.',
                        formula: 'Mean off-diagonal Pearson correlation of 1h returns across BTC + 4 alts, rolling 48h',
                        thresholdRationale: '0.70 mean off-diagonal correlation across 5 majors is a strong risk-off signature; below that threshold, normal idiosyncratic moves dominate.',
                    },
                    {
                        key: 'rejection_pct',
                        title: 'Rejection from High',
                        threshold: '< -5%',
                        iconPath: '<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 13.5L12 21m0 0l-7.5-7.5M12 21V3" />',
                        what: 'How far below its 14-day rolling high BTC currently sits, as a percentage. Negative means below the high.',
                        why: 'Once BTC is more than 5% off its recent peak, distribution is already underway — buyers stepping back, sellers in control. Combined with the other signals, this confirms the cascade is real, not noise.',
                        formula: '(BTC current close ÷ BTC 14d rolling high) − 1, expressed as %',
                        thresholdRationale: '-5% is large enough to filter routine pullbacks while small enough to catch distribution before the bottom.',
                    },
                    {
                        key: 'fut_vol',
                        title: 'Futures Volume Hot',
                        threshold: '> 1.20',
                        iconPath: '<path stroke-linecap="round" stroke-linejoin="round" d="M15.362 5.214A8.252 8.252 0 0112 21 8.25 8.25 0 016.038 7.048 8.287 8.287 0 009 9.6a8.983 8.983 0 013.361-6.867 8.21 8.21 0 003.001 2.48z" /><path stroke-linecap="round" stroke-linejoin="round" d="M12 18a3.75 3.75 0 00.495-7.467 5.99 5.99 0 00-1.925 3.546 5.974 5.974 0 01-2.133-1A3.75 3.75 0 0012 18z" />',
                        what: 'Today\'s BTC futures quote-volume vs the 14-day daily average. A ratio above 1 means today is busier than normal.',
                        why: 'Elevated futures volume above baseline = leverage activity is hot, fuel is loaded. Cascades feed on liquidations of leveraged positions; this signal catches when the powder is dry.',
                        formula: 'Sum(BTCUSDT futures quote volume, last 24h) ÷ 14-day daily average',
                        thresholdRationale: '1.20 = 20% above trailing daily average. Catches the leverage build-up phase without firing on routine activity.',
                    },
                ],

                bandsData: {
                    band_calm: {
                        name: 'Calm', range: '0–39', tone: 'emerald',
                        what: 'Markets are behaving normally. None or only one of the five fragility signals is firing.',
                        action: 'Bot opens new positions at full size. No special posture.',
                        trigger: 'Score lands here when the market is in low-volatility, uncorrelated drift — typical "boring" regime.',
                    },
                    band_elevated: {
                        name: 'Elevated', range: '40–59', tone: 'amber',
                        what: 'Two signals firing. Some flags are flickering but no cascade signature yet.',
                        action: 'Bot keeps running normally — no margin reduction, no block.',
                        trigger: 'Typically rising vol or correlation by itself. Not enough to act on.',
                    },
                    band_fragile: {
                        name: 'Fragile', range: '60–79', tone: 'orange',
                        what: 'Three signals firing. Multiple fragility conditions stacking — risk of cascade is materially elevated.',
                        action: 'New positions open at reduced size on a linear ramp (0% reduction at 60, 50% reduction at 79). Existing positions are never resized.',
                        trigger: 'Vol spike + correlation high + futures volume hot, or similar combination.',
                    },
                    band_critical: {
                        name: 'Critical', range: '80–100', tone: 'red',
                        what: 'Four or all five signals firing. Cascade signature detected — same pattern seen at T-6h before every major event in the backtest.',
                        action: 'Bot pauses opening new positions for 24 hours. Existing positions stay open and continue their lifecycle. After 24h, the system re-checks: still high → another 24h; recovered → cooldown lifts.',
                        trigger: 'Vol breakout + correlation high + futures hot + price already rejected from highs + range blowout. The full setup.',
                    },
                },

                init() {
                    this.fetchData();
                    this._timer = setInterval(() => this.fetchData(), 60000);
                },

                async fetchData() {
                    const res = await window.hubUiFetch('{{ route('bscs.data') }}', { method: 'GET' });
                    if (res.ok) this.payload = res.data;
                },

                openModal(key) { this.activeModal = key; document.body.classList.add('overflow-hidden'); },
                closeModal()   { this.activeModal = null; document.body.classList.remove('overflow-hidden'); },

                modalTitle() {
                    if (!this.activeModal) return '';
                    if (this.activeModal === 'overview') return 'How BSCS works';
                    const sig = this.signalDocs.find(s => s.key === this.activeModal);
                    if (sig) return sig.title;
                    const band = this.bandsData[this.activeModal];
                    if (band) return band.name + ' band — what it means';
                    return '';
                },

                bandModalData() { return this.bandsData[this.activeModal] ?? {}; },
                bandModalCardClass() {
                    const tone = this.bandModalData().tone;
                    return ({
                        emerald: 'bg-emerald-50 border-emerald-200 text-emerald-900',
                        amber:   'bg-amber-50 border-amber-200 text-amber-900',
                        orange:  'bg-orange-50 border-orange-200 text-orange-900',
                        red:     'bg-red-50 border-red-200 text-red-900',
                    })[tone] ?? 'bg-gray-50 border-gray-200 text-gray-900';
                },

                bandColor(band) {
                    return ({
                        calm:     'rgb(16, 185, 129)',
                        elevated: 'rgb(245, 158, 11)',
                        fragile:  'rgb(249, 115, 22)',
                        critical: 'rgb(239, 68, 68)',
                    })[band] ?? 'rgb(148, 163, 184)';
                },
                bandBgStyle()  { const c = this.bandColor(this.payload?.band); return `background-color: ${c}1a; border: 1px solid ${c}40`; },
                bandFgStyle()  { return `color: ${this.bandColor(this.payload?.band)}`; },
                bandBarClass(band) {
                    return ({ calm: 'bg-emerald-500', elevated: 'bg-amber-500', fragile: 'bg-orange-500', critical: 'bg-red-500' })[band] ?? 'bg-gray-400';
                },
                bandPlainEnglish() {
                    if (this.payload?.score === null || this.payload?.score === undefined) return 'Awaiting first compute. The score updates once an hour.';
                    return ({
                        calm:     'Markets are calm. The bot is opening positions normally at full size.',
                        elevated: 'A few signals are flickering. Nothing actionable yet — the bot keeps running normally.',
                        fragile:  'Multiple fragility signals are firing. New positions open at reduced size to limit exposure if a cascade hits.',
                        critical: 'Cascade signature detected. The bot has paused opening new positions for 24 hours. Existing positions stay open.',
                    })[this.payload?.band] ?? 'No band data yet.';
                },

                fragileReduction() {
                    const score = Number(this.payload?.score);
                    if (!Number.isFinite(score) || score < 60 || score > 79) return 0;
                    return Math.round(((score - 60) / 19) * 50);
                },
                fragileMultiplier() {
                    const r = this.fragileReduction();
                    return ((100 - r) / 100).toFixed(2) + '× base';
                },

                relativeAge() {
                    const s = Number(this.payload?.age_seconds);
                    if (!Number.isFinite(s) || s < 0) return '—';
                    if (s < 60) return s + 's ago';
                    if (s < 3600) return Math.floor(s / 60) + 'm ago';
                    return Math.floor(s / 3600) + 'h ago';
                },
                fmtTime(iso) {
                    if (!iso) return '—';
                    try { return new Date(iso).toLocaleString(); } catch (_) { return iso; }
                },
            };
        }
    </script>
</x-app-layout>
