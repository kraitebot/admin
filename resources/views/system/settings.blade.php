@php
    $input = 'w-full h-[42px] rounded-control border border-line bg-input px-3.5 font-mono text-[13px] tabular-nums text-fg-1 outline-none transition-[border-color,box-shadow] duration-fast focus:border-accent focus:shadow-[0_0_0_3px_color-mix(in_srgb,var(--accent)_18%,transparent)]';
    $select = $input.' appearance-none cursor-pointer pr-10';
    $nullableBoolean = static fn (?bool $value): string => $value === null ? 'inherit' : ($value ? '1' : '0');
    $canTradeValue = old('can_trade', $nullableBoolean($engine->can_trade));
    $notificationsValue = old('notifications_enabled', $nullableBoolean($engine->notifications_enabled));
    $correlationEnabledValue = old('corr_enabled', $nullableBoolean($engine->corr_enabled));
    $elasticityEnabledValue = old('elast_enabled', $nullableBoolean($engine->elast_enabled));
    $correlationTypeValue = old('td_correlation_type', $engine->td_correlation_type ?? 'inherit');
    $cooldownActive = $engine->bscs_cooldown_until?->isFuture() ?? false;
@endphp

<x-app-layout active="settings" :title="'Kraite — Settings'">
    <div class="flex items-end justify-between gap-5 pb-5 mb-6 border-b border-line max-[820px]:flex-col max-[820px]:items-start">
        <div>
            <div class="font-mono text-[11px] font-medium tracking-[0.12em] uppercase text-fg-3 mb-2 flex items-center gap-2">
                <x-feathericon-sliders class="w-[13px] h-[13px]" stroke-width="1.75"/>Platform controls
            </div>
            <h1 class="font-sans font-bold text-[28px] tracking-[-0.02em] text-fg-1 leading-[1.1] max-[640px]:text-[24px]">Runtime settings</h1>
            <div class="text-[13px] text-fg-3 mt-1.5">Database-backed controls applied without editing files or running a release.</div>
        </div>
        <div class="flex items-center gap-2 rounded-control border border-line bg-surface px-3.5 h-9">
            <span class="w-2 h-2 rounded-full bg-accent"></span>
            <span class="font-mono text-[10px] font-bold tracking-[0.09em] uppercase text-fg-2">Live database settings</span>
        </div>
    </div>

    @if(session('status'))
        <div class="mb-5 rounded-control border border-accent/40 bg-accent/10 px-4 py-3 text-[13px] text-fg-1 flex items-center gap-2">
            <x-feathericon-check class="w-4 h-4 text-accent" stroke-width="2"/>
            {{ session('status') }}
        </div>
    @endif

    @if($errors->any())
        <div class="mb-5 rounded-control border border-danger/40 bg-danger/10 px-4 py-3 text-[13px] text-danger">
            <div class="font-semibold flex items-center gap-2">
                <x-feathericon-alert-triangle class="w-4 h-4" stroke-width="1.75"/>
                Settings were not saved.
            </div>
            <ul class="mt-2 ml-6 list-disc space-y-1 text-[12px]">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid grid-cols-[minmax(0,1fr)_340px] gap-5 items-start max-[1050px]:grid-cols-1">
        <form method="POST" action="{{ route('system.settings.update') }}" class="card card--flat overflow-hidden">
            @csrf
            @method('PATCH')

            <x-ui.card-head icon="sliders" title="Database controls" :accent="true" hint="changes apply to the shared singleton"/>

            <x-form.group title="Trading gates" icon="shield" hint="new positions only">
                <x-form.field
                    label="Allow opening positions"
                    for="allow_opening_positions"
                    help="The operator gate for every account. Turning it off leaves existing positions running.">
                    <input type="hidden" name="allow_opening_positions" value="0">
                    <label class="h-[42px] rounded-control border border-line bg-input px-3.5 flex items-center justify-between gap-4 cursor-pointer">
                        <span class="font-sans text-[13px] text-fg-2">New position cycles</span>
                        <span class="relative inline-flex items-center">
                            <input id="allow_opening_positions" type="checkbox" name="allow_opening_positions" value="1"
                                   class="peer sr-only" @checked((bool) old('allow_opening_positions', $engine->allow_opening_positions))>
                            <span class="h-[24px] w-[44px] rounded-full bg-surface-3 ring-1 ring-inset ring-line-strong transition-colors peer-checked:bg-accent peer-focus-visible:ring-2 peer-focus-visible:ring-accent"></span>
                            <span class="absolute left-1 h-4 w-4 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5"></span>
                        </span>
                    </label>
                </x-form.field>

                <x-form.field
                    label="Master trading"
                    for="can_trade"
                    help="Emergency fleet-wide stop for new opens. Existing positions continue managing and closing.">
                    <div class="relative">
                        <select id="can_trade" name="can_trade" class="{{ $select }}">
                            <option value="inherit" @selected($canTradeValue === 'inherit')>Use safe default — enabled</option>
                            <option value="1" @selected((string) $canTradeValue === '1')>Enabled</option>
                            <option value="0" @selected((string) $canTradeValue === '0')>Suspended</option>
                        </select>
                        <x-feathericon-chevron-down class="pointer-events-none absolute right-3.5 top-1/2 w-4 h-4 -translate-y-1/2 text-fg-mute" stroke-width="1.75"/>
                    </div>
                </x-form.field>
            </x-form.group>

            <x-form.group title="Notifications" icon="activity" hint="global delivery">
                <x-form.field
                    label="Notification delivery"
                    for="notifications_enabled"
                    help="Global delivery switch before each user's own channel preferences are applied.">
                    <div class="relative">
                        <select id="notifications_enabled" name="notifications_enabled" class="{{ $select }}">
                            <option value="inherit" @selected($notificationsValue === 'inherit')>Use configured default — {{ $effective['notifications_enabled'] ? 'enabled' : 'disabled' }}</option>
                            <option value="1" @selected((string) $notificationsValue === '1')>Enabled</option>
                            <option value="0" @selected((string) $notificationsValue === '0')>Disabled</option>
                        </select>
                        <x-feathericon-chevron-down class="pointer-events-none absolute right-3.5 top-1/2 w-4 h-4 -translate-y-1/2 text-fg-mute" stroke-width="1.75"/>
                    </div>
                </x-form.field>

                <div class="rounded-control border border-line-soft bg-surface-2 px-4 py-3 flex gap-3">
                    <x-feathericon-info class="w-4 h-4 text-fg-mute flex-shrink-0 mt-0.5" stroke-width="1.75"/>
                    <p class="text-[12px] leading-[1.55] text-fg-3">Channels and credentials stay outside this form. This switch only enables or suppresses the delivery pipeline.</p>
                </div>
            </x-form.group>

            <x-form.group title="Market calculations" icon="trending-up" hint="next scheduled computation">
                <x-form.field
                    label="Correlation series"
                    for="td_correlation_type"
                    help="The BTC relationship series token discovery uses when ranking candidates.">
                    <div class="relative">
                        <select id="td_correlation_type" name="td_correlation_type" class="{{ $select }}">
                            <option value="inherit" @selected($correlationTypeValue === 'inherit')>Use configured default — {{ $effective['td_correlation_type'] }}</option>
                            @foreach(['rolling', 'pearson', 'spearman'] as $type)
                                <option value="{{ $type }}" @selected($correlationTypeValue === $type)>{{ ucfirst($type) }}</option>
                            @endforeach
                        </select>
                        <x-feathericon-chevron-down class="pointer-events-none absolute right-3.5 top-1/2 w-4 h-4 -translate-y-1/2 text-fg-mute" stroke-width="1.75"/>
                    </div>
                </x-form.field>

                <x-form.field
                    label="Correlation computation"
                    for="corr_enabled"
                    help="Controls whether the BTC-correlation pipeline produces fresh values.">
                    <div class="relative">
                        <select id="corr_enabled" name="corr_enabled" class="{{ $select }}">
                            <option value="inherit" @selected($correlationEnabledValue === 'inherit')>Use configured default — {{ $effective['corr_enabled'] ? 'enabled' : 'disabled' }}</option>
                            <option value="1" @selected((string) $correlationEnabledValue === '1')>Enabled</option>
                            <option value="0" @selected((string) $correlationEnabledValue === '0')>Disabled</option>
                        </select>
                        <x-feathericon-chevron-down class="pointer-events-none absolute right-3.5 top-1/2 w-4 h-4 -translate-y-1/2 text-fg-mute" stroke-width="1.75"/>
                    </div>
                </x-form.field>

                <x-form.field
                    label="Elasticity computation"
                    for="elast_enabled"
                    help="Controls whether the BTC-elasticity pipeline produces fresh values.">
                    <div class="relative">
                        <select id="elast_enabled" name="elast_enabled" class="{{ $select }}">
                            <option value="inherit" @selected($elasticityEnabledValue === 'inherit')>Use configured default — {{ $effective['elast_enabled'] ? 'enabled' : 'disabled' }}</option>
                            <option value="1" @selected((string) $elasticityEnabledValue === '1')>Enabled</option>
                            <option value="0" @selected((string) $elasticityEnabledValue === '0')>Disabled</option>
                        </select>
                        <x-feathericon-chevron-down class="pointer-events-none absolute right-3.5 top-1/2 w-4 h-4 -translate-y-1/2 text-fg-mute" stroke-width="1.75"/>
                    </div>
                </x-form.field>
            </x-form.group>

            <x-form.group title="BSCS safety" icon="alert-triangle" hint="market-shock protection" :cols="1">
                <x-form.field
                    label="Freshness window"
                    for="bscs_freshness_max_seconds"
                    help="Maximum age, in seconds, before BSCS data is treated as stale.">
                    <input id="bscs_freshness_max_seconds" name="bscs_freshness_max_seconds" type="number" min="0" step="1"
                           value="{{ old('bscs_freshness_max_seconds', $engine->bscs_freshness_max_seconds) }}" class="{{ $input }}" required>
                </x-form.field>
            </x-form.group>

            <x-form.group title="Data retention" icon="database" hint="diagnostic history" :cols="1">
                <x-form.field
                    label="Position-trail retention"
                    for="trail_retention_hours"
                    help="Hours to retain closed-position diagnostics. Blank inherits {{ $effective['trail_retention_hours'] }}h; 0 purges immediately.">
                    <input id="trail_retention_hours" name="trail_retention_hours" type="number" min="0" step="1"
                           value="{{ old('trail_retention_hours', $engine->trail_retention_hours) }}" placeholder="Inherit {{ $effective['trail_retention_hours'] }}"
                           class="{{ $input }}">
                </x-form.field>
            </x-form.group>

            <div class="flex items-center justify-between gap-4 px-6 py-4 bg-surface-2 max-[640px]:px-4 max-[640px]:flex-col max-[640px]:items-stretch">
                <span class="font-mono text-[10px] text-fg-mute">Each change records the administrator and before/after values.</span>
                <button type="submit"
                        class="h-10 px-5 rounded-control bg-accent text-accent-on font-sans text-[13px] font-semibold inline-flex items-center justify-center gap-2 hover:bg-accent-hover transition-colors">
                    <x-feathericon-save class="w-4 h-4" stroke-width="1.75"/>
                    Save runtime settings
                </button>
            </div>
        </form>

        <aside class="flex flex-col gap-4">
            <section class="card card--flat overflow-hidden">
                <x-ui.card-head icon="activity" title="Current effect" hint="read-only"/>
                <div class="p-4 flex flex-col gap-3">
                    <div class="rounded-control border px-4 py-3 {{ $newOpensAllowed ? 'border-pnlup/40 bg-pnlup-bg' : 'border-danger/40 bg-danger/10' }}">
                        <div class="font-mono text-[9px] font-bold tracking-[0.1em] uppercase {{ $newOpensAllowed ? 'text-pnlup' : 'text-danger' }}">New positions</div>
                        <div class="font-sans text-[18px] font-semibold text-fg-1 mt-1">{{ $newOpensAllowed ? 'Allowed' : 'Blocked' }}</div>
                        <div class="text-[11px] text-fg-3 mt-1">Result after master, operator, and BSCS gates.</div>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        @foreach([
                            ['Master', $effective['can_trade'] ? 'Enabled' : 'Suspended', $effective['can_trade'] ? 'text-pnlup' : 'text-danger'],
                            ['Open gate', $engine->allow_opening_positions ? 'Open' : 'Closed', $engine->allow_opening_positions ? 'text-pnlup' : 'text-danger'],
                            ['BSCS gate', $bscsBlocksOpens ? 'Blocking' : 'Clear', $bscsBlocksOpens ? 'text-danger' : 'text-pnlup'],
                            ['Deploy mode', $engine->is_cooling_down ? 'Cooling' : 'Running', $engine->is_cooling_down ? 'text-warn' : 'text-fg-1'],
                        ] as [$label, $value, $color])
                            <div class="rounded-control border border-line bg-surface-2 p-3">
                                <div class="font-mono text-[9px] tracking-[0.09em] uppercase text-fg-mute">{{ $label }}</div>
                                <div class="text-[13px] font-semibold mt-1 {{ $color }}">{{ $value }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>

            <section class="card card--flat overflow-hidden">
                <x-ui.card-head icon="shield" title="BSCS state" hint="system-owned"/>
                <div class="grid grid-cols-2">
                    <div class="p-4 border-r border-b border-line-soft">
                        <div class="font-mono text-[9px] tracking-[0.09em] uppercase text-fg-mute">BSCS score</div>
                        <div class="font-mono text-[24px] font-bold tabular-nums text-fg-1 mt-1">{{ $engine->bscs_score ?? '—' }}</div>
                    </div>
                    <div class="p-4 border-b border-line-soft">
                        <div class="font-mono text-[9px] tracking-[0.09em] uppercase text-fg-mute">Band</div>
                        <div class="font-mono text-[14px] font-bold uppercase text-fg-1 mt-2">{{ $engine->bscs_band ?? 'Unknown' }}</div>
                    </div>
                    <div class="p-4 border-r border-line-soft">
                        <div class="font-mono text-[9px] tracking-[0.09em] uppercase text-fg-mute">Last computed</div>
                        <div class="font-mono text-[11px] text-fg-2 mt-2">{{ $engine->bscs_synced_at?->diffForHumans() ?? 'Never' }}</div>
                    </div>
                    <div class="p-4">
                        <div class="font-mono text-[9px] tracking-[0.09em] uppercase text-fg-mute">Cooldown</div>
                        <div class="font-mono text-[11px] mt-2 {{ $cooldownActive ? 'text-danger' : 'text-fg-2' }}">{{ $cooldownActive ? $engine->bscs_cooldown_until->diffForHumans() : 'Inactive' }}</div>
                    </div>
                </div>
            </section>

            <section class="card card--flat overflow-hidden">
                <x-ui.card-head icon="clock" title="Recent changes" hint="last 5"/>
                @forelse($history as $entry)
                    <div class="px-4 py-3 border-b border-line-soft last:border-b-0">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-[12px] font-semibold text-fg-2">{{ data_get($entry->metadata, 'actor_name', 'Administrator') }}</span>
                            <span class="font-mono text-[9px] text-fg-mute">{{ $entry->created_at->diffForHumans() }}</span>
                        </div>
                        <div class="font-mono text-[9.5px] text-fg-mute mt-1">Runtime settings snapshot recorded</div>
                    </div>
                @empty
                    <div class="px-4 py-6 text-center font-mono text-[10px] text-fg-mute">No console changes recorded yet.</div>
                @endforelse
            </section>
        </aside>
    </div>
</x-app-layout>
