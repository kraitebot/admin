<x-app-layout :activeSection="'system'" :activeHighlight="'ui-components'">
    <x-hub-ui::page-header
        title="UI Kit"
        description="Live catalogue of every hub-ui component in use across the admin. Handy reference for patterns, variants, and copy-paste starting points."
    />

    <div x-data="{ activeTab: 'typography' }" class="space-y-6">
        <x-hub-ui::tabs
            active="activeTab"
            class="!px-0 !pt-0 border-0"
            :tabs="[
                ['key' => 'typography', 'label' => 'Typography'],
                ['key' => 'buttons',    'label' => 'Buttons'],
                ['key' => 'forms',      'label' => 'Forms'],
                ['key' => 'feedback',   'label' => 'Feedback'],
                ['key' => 'overlays',   'label' => 'Overlays'],
                ['key' => 'display',    'label' => 'Display'],
                ['key' => 'data',       'label' => 'Data'],
                ['key' => 'headers',    'label' => 'Headers'],
            ]"
        />

        {{-- Typography --}}
        <div x-show="activeTab === 'typography'" class="space-y-6">
            @include('system.ui-components._section', ['title' => 'Headings', 'description' => 'Title scales from h1 → h6 with mobile-first sizing.'])
            <div class="ui-card p-6 space-y-3">
                <h1 class="text-3xl sm:text-4xl lg:text-5xl font-medium ui-text leading-tight">Heading 1 · text-5xl</h1>
                <h2 class="text-3xl font-semibold tracking-tight ui-text">Heading 2 · text-3xl</h2>
                <h3 class="text-2xl font-semibold ui-text">Heading 3 · text-2xl</h3>
                <h4 class="text-xl font-semibold ui-text">Heading 4 · text-xl</h4>
                <h5 class="text-base font-semibold tracking-tight ui-text">Heading 5 · text-base</h5>
                <h6 class="text-sm font-semibold uppercase tracking-wider ui-text-muted">Heading 6 · caps</h6>
            </div>

            @include('system.ui-components._section', ['title' => 'Text utilities', 'description' => 'Semantic ui-text-* helpers for theme-aware colour.'])
            <div class="ui-card p-6 space-y-2 text-sm">
                <p class="ui-text">Primary body text &mdash; <code class="ui-kbd">ui-text</code></p>
                <p class="ui-text-muted">Muted secondary text &mdash; <code class="ui-kbd">ui-text-muted</code></p>
                <p class="ui-text-subtle">Subtle hints and captions &mdash; <code class="ui-kbd">ui-text-subtle</code></p>
                <p class="ui-text-primary">Primary accent colour &mdash; <code class="ui-kbd">ui-text-primary</code></p>
                <p class="ui-text-success">Success / healthy state &mdash; <code class="ui-kbd">ui-text-success</code></p>
                <p class="ui-text-warning">Warning / attention &mdash; <code class="ui-kbd">ui-text-warning</code></p>
                <p class="ui-text-danger">Danger / destructive &mdash; <code class="ui-kbd">ui-text-danger</code></p>
                <p class="ui-text-info">Info / neutral accent &mdash; <code class="ui-kbd">ui-text-info</code></p>
            </div>

            @include('system.ui-components._section', ['title' => 'Inline', 'description' => 'Keycap, tabular-nums, code blocks.'])
            <div class="ui-card p-6 space-y-3 text-sm ui-text">
                <p>Press <kbd class="ui-kbd">Ctrl</kbd> + <kbd class="ui-kbd">Enter</kbd> to submit.</p>
                <p>Tabular numbers: <span class="ui-tabular font-mono">1,234.56</span> vs default: <span class="font-mono">1,234.56</span></p>
                <p>Monospace: <code class="font-mono ui-text-muted">$account-&gt;apiQueryPositions()</code></p>
            </div>
        </div>

        {{-- Buttons --}}
        <div x-show="activeTab === 'buttons'" x-cloak class="space-y-6">
            @include('system.ui-components._section', ['title' => 'Variants', 'description' => 'Primary · secondary · danger · ghost · link.'])
            <div class="ui-card p-6 flex flex-wrap gap-3">
                <button class="ui-btn ui-btn-primary ui-btn-md">Primary</button>
                <button class="ui-btn ui-btn-secondary ui-btn-md">Secondary</button>
                <button class="ui-btn ui-btn-danger ui-btn-md">Danger</button>
                <button class="ui-btn ui-btn-ghost ui-btn-md">Ghost</button>
                <button class="ui-btn ui-btn-link">Link style</button>
            </div>

            @include('system.ui-components._section', ['title' => 'Sizes', 'description' => 'sm · md · lg.'])
            <div class="ui-card p-6 flex items-center flex-wrap gap-3">
                <button class="ui-btn ui-btn-primary ui-btn-sm">Small</button>
                <button class="ui-btn ui-btn-primary ui-btn-md">Medium</button>
                <button class="ui-btn ui-btn-primary ui-btn-lg">Large</button>
            </div>

            @include('system.ui-components._section', ['title' => 'States', 'description' => 'Disabled, with icon, loading.'])
            <div class="ui-card p-6 flex flex-wrap gap-3">
                <button class="ui-btn ui-btn-primary ui-btn-md" disabled>Disabled</button>
                <button class="ui-btn ui-btn-secondary ui-btn-md">
                    <x-feathericon-download class="w-4 h-4" />
                    <span>With icon</span>
                </button>
                <button class="ui-btn ui-btn-primary ui-btn-md">
                    <x-hub-ui::spinner size="sm" />
                    <span>Loading…</span>
                </button>
            </div>
        </div>

        {{-- Forms --}}
        <div x-show="activeTab === 'forms'" x-cloak class="space-y-6" x-data="{ sample: '', pick: 'binance', notes: '', agree: false, togglePrimary: true, toggleSuccess: false, toggleWarning: true, toggleDanger: false }">
            @include('system.ui-components._section', ['title' => 'Input', 'description' => 'Text field with optional label, hint, error.'])
            <div class="ui-card p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
                <x-hub-ui::input name="name" label="Account name" placeholder="e.g. Main Binance" x-model="sample" />
                <x-hub-ui::input name="email" type="email" label="Email" hint="Used for login and notifications." placeholder="you@example.com" />
                <x-hub-ui::input name="bad" label="Invalid" error="This value is required." value="oops" />
                <x-hub-ui::input name="locked" label="Read-only" value="Cannot be changed" :readonly="true" />
            </div>

            @include('system.ui-components._section', ['title' => 'Select', 'description' => 'Labelled dropdown; binds with x-model.'])
            <div class="ui-card p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
                <x-hub-ui::select name="exchange" label="Exchange" x-model="pick">
                    <option value="binance">Binance</option>
                    <option value="bybit">Bybit</option>
                    <option value="kucoin">KuCoin</option>
                    <option value="bitget">Bitget</option>
                </x-hub-ui::select>
                <div class="text-xs ui-text-subtle self-end">
                    Selected: <span class="font-mono ui-text-muted" x-text="pick"></span>
                </div>
            </div>

            @include('system.ui-components._section', ['title' => 'Textarea', 'description' => 'Multi-line input with character counting via x-model.'])
            <div class="ui-card p-6">
                <x-hub-ui::textarea name="notes" label="Notes" rows="4" x-model="notes" placeholder="Write anything here…" />
                <p class="text-xs ui-text-subtle mt-2">Length: <span class="font-mono" x-text="notes.length"></span></p>
            </div>

            @include('system.ui-components._section', ['title' => 'Checkbox & Switch', 'description' => 'Toggle primitives. Each switch below owns its own state.'])
            <div class="ui-card p-6 flex flex-wrap items-center gap-x-6 gap-y-3">
                <x-hub-ui::checkbox name="agree" label="I accept the terms" x-model="agree" />
                <x-hub-ui::switch x-model="togglePrimary" label="Primary" labelPosition="right" size="sm" />
                <x-hub-ui::switch x-model="toggleSuccess" onColor="success" label="Success" labelPosition="right" size="sm" />
                <x-hub-ui::switch x-model="toggleWarning" onColor="warning" label="Warning" labelPosition="right" size="sm" />
                <x-hub-ui::switch x-model="toggleDanger" onColor="danger" label="Danger" labelPosition="right" size="sm" />
            </div>
        </div>

        {{-- Feedback --}}
        <div x-show="activeTab === 'feedback'" x-cloak class="space-y-6">
            @include('system.ui-components._section', ['title' => 'Alerts', 'description' => 'Inline banner messages, dismissible.'])
            <div class="ui-card p-6 space-y-3">
                <x-hub-ui::alert type="info">Informational — something neutral you should know.</x-hub-ui::alert>
                <x-hub-ui::alert type="success">Success — the operation completed.</x-hub-ui::alert>
                <x-hub-ui::alert type="warning" dismissible>Warning — something to pay attention to.</x-hub-ui::alert>
                <x-hub-ui::alert type="error">Error — the operation failed.</x-hub-ui::alert>
            </div>

            @include('system.ui-components._section', ['title' => 'Badges', 'description' => 'Short status chips.'])
            <div class="ui-card p-6 flex flex-wrap items-center gap-2">
                <x-hub-ui::badge type="default">default</x-hub-ui::badge>
                <x-hub-ui::badge type="primary">primary</x-hub-ui::badge>
                <x-hub-ui::badge type="success">success</x-hub-ui::badge>
                <x-hub-ui::badge type="warning">warning</x-hub-ui::badge>
                <x-hub-ui::badge type="danger">danger</x-hub-ui::badge>
                <x-hub-ui::badge type="info">info</x-hub-ui::badge>
                <x-hub-ui::badge type="online">online</x-hub-ui::badge>
                <x-hub-ui::badge type="offline">offline</x-hub-ui::badge>
                <x-hub-ui::badge type="pending">pending</x-hub-ui::badge>
            </div>

            @include('system.ui-components._section', ['title' => 'Status (pulse-dot + label)', 'description' => 'Compound: colored dot with label.'])
            <div class="ui-card p-6 flex flex-wrap items-center gap-6">
                <x-hub-ui::status type="success" label="Connected" :animated="true" />
                <x-hub-ui::status type="warning" label="Degraded" />
                <x-hub-ui::status type="danger"  label="Offline" />
                <x-hub-ui::status type="info"    label="Syncing"  :animated="true" />
            </div>

            @include('system.ui-components._section', ['title' => 'Pulse dot', 'description' => 'Plain dot primitive with optional ping ring.'])
            <div class="ui-card p-6 flex flex-wrap items-center gap-6">
                <div class="flex items-center gap-2"><x-hub-ui::pulse-dot type="success" :pulse="true" size="md" /><span class="text-xs ui-text-muted">success · pulse · md</span></div>
                <div class="flex items-center gap-2"><x-hub-ui::pulse-dot type="warning" :pulse="true" size="md" /><span class="text-xs ui-text-muted">warning · pulse · md</span></div>
                <div class="flex items-center gap-2"><x-hub-ui::pulse-dot type="danger" size="sm" /><span class="text-xs ui-text-muted">danger · sm</span></div>
                <div class="flex items-center gap-2"><x-hub-ui::pulse-dot type="info" size="lg" /><span class="text-xs ui-text-muted">info · lg</span></div>
            </div>

            @include('system.ui-components._section', ['title' => 'Spinner', 'description' => 'Loading indicator.'])
            <div class="ui-card p-6 flex flex-wrap items-center gap-6">
                <x-hub-ui::spinner size="xs" />
                <x-hub-ui::spinner size="sm" />
                <x-hub-ui::spinner size="md" />
                <x-hub-ui::spinner size="lg" />
                <x-hub-ui::spinner size="xl" />
            </div>

            @include('system.ui-components._section', ['title' => 'Toast & Confirmation (JS)', 'description' => 'Imperative helpers wired via hub-ui JS.'])
            <div class="ui-card p-6 flex flex-wrap gap-3">
                <button class="ui-btn ui-btn-primary ui-btn-sm" @click="window.showToast('Saved successfully', 'success', 2500)">Show success toast</button>
                <button class="ui-btn ui-btn-secondary ui-btn-sm" @click="window.showToast('Something went wrong', 'error', 2500)">Show error toast</button>
                <button class="ui-btn ui-btn-danger ui-btn-sm" @click="window.showConfirmation({ title: 'Delete record?', message: 'This cannot be undone.', confirmText: 'Delete', type: 'danger', onConfirm: () => window.showToast('Deleted', 'success', 1500) })">Show confirmation</button>
            </div>

            @include('system.ui-components._section', ['title' => 'Empty state', 'description' => 'Placeholder when a panel has no data yet.'])
            <div class="ui-card p-6">
                <x-hub-ui::empty-state
                    title="Nothing here yet"
                    description="This is where useful stuff will appear once there's something to show."
                >
                    <x-slot:icon>
                        <x-feathericon-inbox class="w-full h-full" />
                    </x-slot:icon>
                </x-hub-ui::empty-state>
            </div>
        </div>

        {{-- Overlays --}}
        <div x-show="activeTab === 'overlays'" x-cloak class="space-y-6">
            @include('system.ui-components._section', ['title' => 'Modal', 'description' => 'Alpine-driven dialog with focus trap. Opens/closes via window events.'])
            <div class="ui-card p-6">
                <button
                    type="button"
                    class="ui-btn ui-btn-primary ui-btn-md"
                    @click="$dispatch('open-modal', 'kit-demo')"
                >Open modal</button>

                <x-hub-ui::modal name="kit-demo" :show="false">
                    <div class="p-6 space-y-4">
                        <h3 class="text-lg font-semibold ui-text">Example modal</h3>
                        <p class="text-sm ui-text-muted">Modals trap focus while open and close on Escape or backdrop click.</p>
                        <div class="flex justify-end gap-2">
                            <button class="ui-btn ui-btn-ghost ui-btn-sm" @click="$dispatch('close-modal', 'kit-demo')">Cancel</button>
                            <button class="ui-btn ui-btn-primary ui-btn-sm" @click="$dispatch('close-modal', 'kit-demo')">Confirm</button>
                        </div>
                    </div>
                </x-hub-ui::modal>
            </div>

            @include('system.ui-components._section', ['title' => 'Dropdown', 'description' => 'Click-to-open menu.'])
            <div class="ui-card p-6">
                <x-hub-ui::dropdown align="left" width="48">
                    <x-slot:trigger>
                        <button class="ui-btn ui-btn-secondary ui-btn-md">
                            <span>Menu</span>
                            <x-feathericon-chevron-down class="w-4 h-4" />
                        </button>
                    </x-slot:trigger>
                    <x-slot:content>
                        <x-hub-ui::dropdown-link href="#">Profile</x-hub-ui::dropdown-link>
                        <x-hub-ui::dropdown-link href="#">Settings</x-hub-ui::dropdown-link>
                        <x-hub-ui::dropdown-link href="#">Sign out</x-hub-ui::dropdown-link>
                    </x-slot:content>
                </x-hub-ui::dropdown>
            </div>
        </div>

        {{-- Display --}}
        <div x-show="activeTab === 'display'" x-cloak class="space-y-6">
            @include('system.ui-components._section', ['title' => 'Card', 'description' => 'Container with optional header, subtitle, footer.'])
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <x-hub-ui::card title="Account" subtitle="Basic details">
                    <p class="text-sm ui-text-muted">Cards accept slotted content.</p>
                </x-hub-ui::card>
                <x-hub-ui::card title="With footer" subtitle="Actions below">
                    <p class="text-sm ui-text-muted">Anything can go in the body.</p>
                    <x-slot:footer>
                        <div class="flex justify-end gap-2">
                            <button class="ui-btn ui-btn-ghost ui-btn-sm">Cancel</button>
                            <button class="ui-btn ui-btn-primary ui-btn-sm">Save</button>
                        </div>
                    </x-slot:footer>
                </x-hub-ui::card>
            </div>

            @include('system.ui-components._section', ['title' => 'Stat Metric', 'description' => 'Labelled KPI with optional hint/delta.'])
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
                <div class="ui-card p-4"><x-hub-ui::stat-metric label="Positions" value="12" /></div>
                <div class="ui-card p-4"><x-hub-ui::stat-metric label="Tradeable symbols" value="347" color="success" /></div>
                <div class="ui-card p-4"><x-hub-ui::stat-metric label="Saturation" value="63%" color="warning" /></div>
                <div class="ui-card p-4"><x-hub-ui::stat-metric label="Failures (last hour)" value="0" color="muted" /></div>
            </div>

            @include('system.ui-components._section', ['title' => 'Animated number & trend delta', 'description' => 'Counter + up/down pill.'])
            <div class="ui-card p-6 flex flex-wrap items-center gap-8">
                <div>
                    <div class="text-3xl font-bold ui-text ui-tabular">
                        <span x-data="hubUiCounter()" x-effect="target = 1337" x-text="Math.round(shown).toLocaleString()"></span>
                    </div>
                    <div class="text-[10px] ui-text-subtle uppercase tracking-wider mt-1">animated counter</div>
                </div>
                <div class="flex items-center gap-2">
                    <x-hub-ui::trend-delta value="2.4" suffix="pt" precision="1" />
                    <x-hub-ui::trend-delta value="-1.1" suffix="pt" precision="1" />
                    <x-hub-ui::trend-delta value="0" suffix="pt" precision="1" />
                </div>
            </div>

            @include('system.ui-components._section', ['title' => 'Gauge & speedometer', 'description' => 'Radial progress primitives.'])
            <div class="ui-card p-6 grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-6 gap-4">
                <x-hub-ui::speedometer value="12" label="'Low'" />
                <x-hub-ui::speedometer value="48" label="'Medium'" />
                <x-hub-ui::speedometer value="82" label="'High'" />
                <x-hub-ui::speedometer value="95" label="'Critical'" />
                <x-hub-ui::speedometer value="0" empty="true" label="'No data'" />
                <x-hub-ui::speedometer value="55" stale="true" label="'Stale'" />
            </div>

            @include('system.ui-components._section', ['title' => 'Progress bar', 'description' => 'Segmented tick bar. Pass a % and tick count; the bar fills Math.round(ticks × value / 100) bars.'])
            <div class="ui-card p-6 space-y-3">
                <div class="flex items-center gap-4 flex-wrap">
                    <div class="w-20 text-xs ui-text-muted">5 ticks</div>
                    <x-hub-ui::progress-bar value="20" ticks="5" tick-width="10" tick-height="20" tick-gap="3" />
                    <span class="text-[11px] ui-text-subtle font-mono">20%</span>
                </div>
                <div class="flex items-center gap-4 flex-wrap">
                    <div class="w-20 text-xs ui-text-muted">10 ticks</div>
                    <x-hub-ui::progress-bar value="45" ticks="10" tick-width="8" tick-height="20" tick-gap="2" />
                    <span class="text-[11px] ui-text-subtle font-mono">45%</span>
                </div>
                <div class="flex items-center gap-4 flex-wrap">
                    <div class="w-20 text-xs ui-text-muted">15 ticks</div>
                    <x-hub-ui::progress-bar value="80" ticks="15" tick-width="6" tick-height="18" tick-gap="2" />
                    <span class="text-[11px] ui-text-subtle font-mono">80%</span>
                </div>
                <div class="flex items-center gap-4 flex-wrap">
                    <div class="w-20 text-xs ui-text-muted">Stale</div>
                    <x-hub-ui::progress-bar value="50" ticks="10" stale="true" tick-width="8" tick-height="20" tick-gap="2" />
                    <span class="text-[11px] ui-text-subtle font-mono">50% · last reading</span>
                </div>
                <div class="flex items-center gap-4 flex-wrap">
                    <div class="w-20 text-xs ui-text-muted">Empty</div>
                    <x-hub-ui::progress-bar value="0" ticks="10" empty="true" tick-width="8" tick-height="20" tick-gap="2" />
                    <span class="text-[11px] ui-text-subtle font-mono">no data</span>
                </div>
            </div>
        </div>

        {{-- Data --}}
        <div x-show="activeTab === 'data'" x-cloak class="space-y-6" x-data="{
            page: 2, lastPage: 7, perPage: 25, total: 168,
            goToPage(p) { if (p === '...' || p < 1 || p > this.lastPage) return; this.page = p; },
            get visiblePages() {
                const total = this.lastPage; if (total <= 7) return Array.from({length: total}, (_, i) => i + 1);
                const pages = [1]; const cur = this.page;
                if (cur > 3) pages.push('...');
                for (let i = Math.max(2, cur - 1); i <= Math.min(total - 1, cur + 1); i++) pages.push(i);
                if (cur < total - 2) pages.push('...');
                pages.push(total);
                return pages;
            },
        }">
            @include('system.ui-components._section', ['title' => 'Data table', 'description' => 'Horizontal-scroll table with size variants.'])
            <x-hub-ui::data-table size="sm">
                <x-slot:head>
                    <tr>
                        <th>Symbol</th>
                        <th>Direction</th>
                        <th class="text-right">Entry</th>
                        <th class="text-right">Qty</th>
                        <th>Status</th>
                    </tr>
                </x-slot:head>

                <tr>
                    <td class="font-mono font-semibold">BTCUSDT</td>
                    <td><x-hub-ui::badge type="success">LONG</x-hub-ui::badge></td>
                    <td class="text-right font-mono ui-tabular">48,120.5</td>
                    <td class="text-right font-mono ui-tabular">0.021</td>
                    <td><x-hub-ui::status type="success" label="Active" /></td>
                </tr>
                <tr>
                    <td class="font-mono font-semibold">ETHUSDT</td>
                    <td><x-hub-ui::badge type="danger">SHORT</x-hub-ui::badge></td>
                    <td class="text-right font-mono ui-tabular">2,410.7</td>
                    <td class="text-right font-mono ui-tabular">0.45</td>
                    <td><x-hub-ui::status type="warning" label="Closing" /></td>
                </tr>
                <tr>
                    <td class="font-mono font-semibold">AIOTUSDT</td>
                    <td><x-hub-ui::badge type="success">LONG</x-hub-ui::badge></td>
                    <td class="text-right font-mono ui-tabular">0.03968</td>
                    <td class="text-right font-mono ui-tabular">1,250</td>
                    <td><x-hub-ui::status type="info" label="Syncing" :animated="true" /></td>
                </tr>
            </x-hub-ui::data-table>

            @include('system.ui-components._section', ['title' => 'Pager', 'description' => 'Compact pagination with total-page awareness.'])
            <div class="ui-card p-6">
                <x-hub-ui::pager duration="28" />
                <div class="text-xs ui-text-subtle mt-2">Page <span class="font-mono" x-text="page"></span> of <span class="font-mono" x-text="lastPage"></span></div>
            </div>
        </div>

        {{-- Headers --}}
        <div x-show="activeTab === 'headers'" x-cloak class="space-y-6">
            @include('system.ui-components._section', ['title' => 'Page header', 'description' => 'Non-flush page title + description.'])
            <div class="ui-card overflow-hidden">
                <div class="p-6">
                    <x-hub-ui::page-header title="Example Page" description="Scales responsively from text-3xl on mobile up to text-5xl on lg." class="!mb-0" />
                </div>
            </div>

            @include('system.ui-components._section', ['title' => 'Live header', 'description' => 'Flush-mode header with actions + live pulse.'])
            <div class="ui-card overflow-hidden">
                <x-hub-ui::live-header
                    title="Heartbeat"
                    description="Example live-header used on flush dashboards."
                    :refreshSeconds="5"
                >
                    <x-slot:actions>
                        <x-hub-ui::badge type="success">OPERATIONAL</x-hub-ui::badge>
                    </x-slot:actions>
                </x-hub-ui::live-header>
            </div>
        </div>
    </div>
</x-app-layout>
