@php
    // Billing display and actions are both backed by BillingController.

    // ---- plans (real subscription tiers) ----
    $plans = $subscriptions->map(fn ($s) => [
        'id' => $s->canonical ?? (string) $s->id,
        'name' => $s->name,
        'price' => (float) $s->monthly_rate_usdt,
        'trial_days' => (int) $s->trial_days,
        'popular' => $s->max_accounts === null,
        'blurb' => $s->description ?? '',
        // Account-count feature is real (max_accounts); the rest are
        // platform-wide copy (not modelled per-tier in the DB).
        'features' => [
            $s->max_accounts === null ? 'Unlimited exchange accounts' : ($s->max_accounts . ' exchange account' . ($s->max_accounts === 1 ? '' : 's')),
            'Full autonomous trading',
            'Priority support',
        ],
    ])->values()->all();

    // ---- accepted coins strip (real curated list) ----
    $coinColors = ['USDT' => '#26a17b', 'USDC' => '#2775ca', 'BTC' => '#f7931a', 'ETH' => '#627eea', 'SOL' => '#9945ff', 'LTC' => '#345d9d', 'BNB' => '#f3ba2f', 'TRX' => '#ff060a', 'XRP' => '#23292f'];
    $coins = $topUpCoins->map(function ($c) use ($coinColors) {
        $label = strtoupper($c->display_name ?: $c->canonical);
        $key = collect($coinColors)->keys()->first(fn ($k) => str_contains($label, $k));

        return ['sym' => $label, 'color' => $coinColors[$key] ?? 'var(--fg-mute)'];
    })->values()->all();

    // ---- downgrade "keep which active?" picker (real accounts) ----
    $pickerAccounts = $accounts->map(fn ($a) => [
        'id' => $a->id,
        'mono' => mb_strtoupper(mb_substr($a->apiSystem?->name ?? '?', 0, 1)),
        'ex' => $a->apiSystem?->name ?? 'Unknown',
        'tag' => $a->name,
        'equity' => '—',   // per-account equity not surfaced here yet
    ])->values()->all();

    // ---- wallet ledger (real transactions, newest first) ----
    $ledger = $transactions->map(fn ($t) => [
        'date' => optional($t->created_at)->format('M j'),
        'type' => $t->type,
        'desc' => $t->description,
        'amount' => (float) $t->amount_usdt,
        'balance' => $t->balance_after !== null ? (float) $t->balance_after : null,
    ])->values()->all();

    // ---- derived state machine + Alpine config ----
    $currentPlan = $tier ? ($tier->canonical ?? (string) $tier->id) : null;
    $view = ! $tier ? 'no-plan'
        : ($isComplimentaryPlan
            ? ($isPaused ? 'paused' : 'complimentary')
            : ($user->trial_started_at === null ? 'trial-ready'
            : ($trialActive ? 'trial'
            : ($isPaused ? 'paused'
            : ($inClosingMode ? 'read-only' : 'active')))));

    $stateSubscriptions = $subscriptions->values();
    if ($tier && ! $stateSubscriptions->contains('id', $tier->id)) {
        $stateSubscriptions = $stateSubscriptions->push($tier);
    }

    $trialSeconds = $trialEndsAt?->isFuture()
        ? (int) now()->diffInSeconds($trialEndsAt)
        : 0;

    $billingCfg = [
        'view' => $view,
        'plan' => $currentPlan,
        'wallet' => (float) $user->wallet_balance_usdt,
        'rates' => $stateSubscriptions->mapWithKeys(fn ($s) => [($s->canonical ?? (string) $s->id) => (float) $s->monthly_rate_usdt])->all(),
        'names' => $stateSubscriptions->mapWithKeys(fn ($s) => [($s->canonical ?? (string) $s->id) => $s->name])->all(),
        'planIds' => $subscriptions->mapWithKeys(fn ($s) => [($s->canonical ?? (string) $s->id) => $s->id])->all(),
        'maxAccounts' => $subscriptions->mapWithKeys(fn ($s) => [($s->canonical ?? (string) $s->id) => $s->max_accounts])->all(),
        'trialDays' => $stateSubscriptions->mapWithKeys(fn ($s) => [($s->canonical ?? (string) $s->id) => $s->trial_days])->all(),
        'accountIds' => collect($pickerAccounts)->pluck('id')->all(),
        'activeAccountId' => $user->active_account_id,
        'minimumTopUp' => $topUpMinimum,
        'trialSecs' => $trialSeconds,
        'pausedSince' => $user->subscription_paused_at?->format('M j, Y'),
        'renewalLabel' => $renewsAt?->format('M j, Y') ?? '—',
        'daysLeft' => $renewsAt ? max(0, (int) ceil(now()->floatDiffInDays($renewsAt, false))) : 0,
        'ledger' => $ledger,
    ];

    // billing terms (fine print)
    $terms = [
        ['icon' => 'refresh-cw', 'title' => 'Monthly prepaid model',
         'body' => 'Your plan rate is debited from the prepaid USDT wallet once per month on the renewal date. There are no cards and no recurring card charges — you fund the wallet ahead of time and the engine draws from it.'],
        ['icon' => 'clock', 'title' => 'Free trial',
         'body' => 'Each plan shows its trial duration. The wallet is never debited during the trial and switching plans mid-trial is free and instant. The first renewal — and first debit — lands when the trial ends.'],
        ['icon' => 'database', 'title' => 'Gateway & network fees',
         'body' => 'Top-ups are processed by NOWPayments, which takes roughly 0.5% of the transacted amount. You also pay the network gas for the chain you send on. Only the amount that settles on-chain credits your wallet.'],
        ['icon' => 'activity', 'title' => 'Conversion spread',
         'body' => 'Paying in a non-USDT coin (BTC, ETH, SOL, LTC, BNB) converts to USDT at the gateway rate at confirmation time. That rate carries a small spread and moves with the market, so the credited USDT can differ slightly from the quoted estimate.'],
        ['icon' => 'lock', 'title' => 'Read-only mode',
         'body' => 'If the wallet can\'t cover a renewal, the account drops to read-only: the bot stops opening new positions, but existing positions keep closing at their take-profit or stop-loss. Top up to clear the shortfall and the renewal retries immediately.'],
    ];

    // shared class strings
    $eyebrow = 'font-mono text-[10px] font-semibold tracking-[0.11em] uppercase text-fg-mute';
    $cardHead = 'flex items-center justify-between gap-3 py-[15px] px-5 border-b border-line-soft';
    $cardTitle = 'font-sans font-semibold text-[14px] text-fg-1 flex items-center gap-[9px] whitespace-nowrap';
    $btnPrimary = 'appearance-none font-sans font-semibold rounded-control border border-transparent cursor-pointer inline-flex items-center gap-[7px] whitespace-nowrap transition-colors duration-fast ease-out active:translate-y-px text-[12px] bg-accent text-accent-on hover:bg-accent-hover';
    $btnSecondary = 'appearance-none font-sans font-semibold rounded-control border cursor-pointer inline-flex items-center gap-[7px] whitespace-nowrap transition-colors duration-fast ease-out active:translate-y-px text-[12px] bg-transparent text-fg-1 border-line-strong hover:bg-hover';

@endphp

<x-app-layout active="billing" :title="'Kraite — Billing'">

    <script>
        // Billing page state machine — prepaid USDT wallet, monthly debits.
        // Views: no-plan · trial-ready · trial · complimentary · paused · read-only · active.
        window.billingPage = (cfg) => {
            const RATES = cfg.rates || {};
            const NAMES = cfg.names || {};
            const PLAN_IDS = cfg.planIds || {};
            const MAX_ACCOUNTS = cfg.maxAccounts || {};
            const TRIAL_DAYS = cfg.trialDays || {};
            const ACCOUNT_IDS = cfg.accountIds || [];
            const CYCLE_DAYS = 30;
            const BASE_LEDGER = cfg.ledger || [];

            const fmt = (n, dp = 4) => Number(n).toLocaleString('en-US', { minimumFractionDigits: dp, maximumFractionDigits: dp });
            const defaultAmount = (cfg.plan && RATES[cfg.plan]) ? RATES[cfg.plan] : 20;

            return {
                view: cfg.view,
                plan: cfg.plan,
                wallet: Number(cfg.wallet) || 0,
                pausedSince: cfg.pausedSince,
                pausing: false,
                switchTo: null,
                keepAcct: cfg.activeAccountId || null,
                credited: null,
                trialSecs: Number(cfg.trialSecs) || 0,
                invoice: null,
                amount: String(defaultAmount),
                busy: false,
                renewalLabel: cfg.renewalLabel,
                daysLeft: cfg.daysLeft,
                _timers: [],

                init() {
                    // trial countdown tick (display only)
                    this._timers.push(setInterval(() => {
                        if (this.view === 'trial' && this.trialSecs > 0) this.trialSecs--;
                    }, 1000));
                },
                // wire:navigate swaps the body but timers outlive the DOM
                destroy() {
                    this._timers.forEach(t => { clearTimeout(t); clearInterval(t); });
                    this._timers = [];
                },

                // ---- formatters ----
                usdt: fmt,
                usdt2: (n) => fmt(n, 2),
                usdtSigned: (n, dp = 4) => (n >= 0 ? '+' : '−') + fmt(Math.abs(n), dp),
                countdown(secs) {
                    if (secs <= 0) return 'now';
                    const d = Math.floor(secs / 86400), h = Math.floor((secs % 86400) / 3600), m = Math.floor((secs % 3600) / 60), s = Math.floor(secs % 60);
                    if (d > 0) return `${d}d ${h}h ${m}m`;
                    if (h > 0) return `${h}h ${String(m).padStart(2, '0')}m ${String(s).padStart(2, '0')}s`;
                    return `${m}m ${String(s).padStart(2, '0')}s`;
                },

                // ---- derived ----
                planName() { return this.plan ? NAMES[this.plan] : null; },
                rate() { return this.plan ? RATES[this.plan] : 0; },
                rateOf(id) { return RATES[id]; },
                nameOf(id) { return NAMES[id]; },
                trialDays() { return this.plan ? Number(TRIAL_DAYS[this.plan]) || 0 : 0; },
                planDatabaseId() { return this.switchTo ? PLAN_IDS[this.switchTo] : ''; },
                covered() { return this.wallet >= this.rate(); },
                shortfall() { return Math.max(0, this.rate() - this.wallet); },
                surplus() { return Math.max(0, this.wallet - this.rate()); },
                walletWhole() { return fmt(this.wallet, 4).split('.')[0]; },
                walletFrac() { return fmt(this.wallet, 4).split('.')[1]; },
                isTrialView() { return this.view === 'trial' || this.view === 'trial-ready'; },

                // proration preview for the pending switch
                prorationRefund() {
                    if (!this.switchTo || !this.plan) return 0;
                    return +((RATES[this.plan] * this.daysLeft) / CYCLE_DAYS).toFixed(4);
                },
                prorationDebit() { return this.switchTo ? RATES[this.switchTo] : 0; },
                walletAfter() { return +(this.wallet + this.prorationRefund() - this.prorationDebit()).toFixed(4); },
                switchShort() { return this.view !== 'no-plan' && !this.isTrialView() && this.walletAfter() < 0; },
                requiresAccountSelection() { return !!(this.switchTo && Number(MAX_ACCOUNTS[this.switchTo]) === 1 && ACCOUNT_IDS.length > 1); },
                downgradeAccts() { return this.requiresAccountSelection(); },

                // top-up
                effMin() { return Number(cfg.minimumTopUp) || 0; },
                amtNum() { return parseFloat(this.amount) || 0; },
                belowMin() { return this.amtNum() < this.effMin() - 1e-9; },
                presets() {
                    const r = this.rate();
                    return [...new Set([this.effMin(), r > 0 ? r : 0, r > 0 ? r * 2 : 50, 100].filter(v => v >= this.effMin() - 1e-9))]
                        .sort((a, b) => a - b).slice(0, 4);
                },

                // ledger with running balance (top row = balance now)
                ledgerRows() {
                    // Real transactions carry their own balance_after snapshot;
                    // prefer it, fall back to a client running balance if absent.
                    let bal = this.wallet;
                    return BASE_LEDGER.map(m => {
                        const balance = (m.balance !== null && m.balance !== undefined) ? m.balance : bal;
                        bal = bal - m.amount;
                        return { ...m, balance };
                    });
                },
                emptyLedger() { return BASE_LEDGER.length === 0; },

                // ---- actions ----
                startSwitch(id) {
                    if (id === this.plan) return;
                    this.switchTo = id;
                    if (Number(MAX_ACCOUNTS[id]) === 1) {
                        this.keepAcct = cfg.activeAccountId || ACCOUNT_IDS[0] || null;
                    }
                },
                cancelSwitch() { this.switchTo = null; },
                confirmSwitch() {
                    if (!this.switchTo || (this.requiresAccountSelection() && !this.keepAcct)) return;
                    this.submitForm('subscriptionForm');
                },
                choosePlan(id) { this.startSwitch(id); },
                startTrial() { this.submitForm('startTrialForm'); },
                pauseConfirm() { this.pausing = false; this.submitForm('pauseForm'); },
                resume() { this.submitForm('resumeForm'); },
                topUpGo() { if (!this.belowMin() && this.amtNum() > 0) this.invoice = { amount: this.amtNum() }; },
                continueGateway() { this.submitForm('topUpForm'); },
                submitForm(ref) {
                    if (this.busy) return;
                    this.busy = true;
                    this.$nextTick(() => this.$refs[ref]?.requestSubmit());
                },
                focusTopUp() {
                    this.switchTo = null;
                    this.$nextTick(() => this.$refs.topup?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
                },
                focusPlans() {
                    this.$nextTick(() => this.$refs.plans?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
                },
            };
        };
    </script>

    <div x-data="billingPage(@js($billingCfg))">

        <form x-ref="subscriptionForm" method="POST" action="{{ route('billing.subscription') }}" class="hidden" aria-hidden="true">
            @csrf
            <input type="hidden" name="subscription_id" :value="planDatabaseId()">
            <input type="hidden" name="active_account_id" :value="keepAcct || ''">
        </form>
        <form x-ref="startTrialForm" method="POST" action="{{ route('billing.start-trading') }}" class="hidden" aria-hidden="true">
            @csrf
        </form>
        <form x-ref="pauseForm" method="POST" action="{{ route('billing.pause') }}" class="hidden" aria-hidden="true">
            @csrf
        </form>
        <form x-ref="resumeForm" method="POST" action="{{ route('billing.resume') }}" class="hidden" aria-hidden="true">
            @csrf
        </form>
        <form x-ref="topUpForm" method="POST" action="{{ route('billing.topup') }}" class="hidden" aria-hidden="true">
            @csrf
            <input type="hidden" name="amount_usdt" :value="amount">
        </form>

        {{-- ===================== PAGE HEADER ===================== --}}
        <div class="flex items-end justify-between gap-5 pb-5 mb-6 border-b border-line max-[820px]:flex-col max-[820px]:items-start">
            <div>
                <div class="font-mono text-[11px] font-medium tracking-[0.12em] uppercase text-fg-3 mb-2 flex items-center gap-2">
                    <x-feathericon-credit-card class="w-[13px] h-[13px]" stroke-width="1.75"/>SUBSCRIPTION
                </div>
                <h1 class="font-sans font-bold text-[28px] tracking-[-0.02em] text-fg-1 leading-[1.1] max-[640px]:text-[24px]">Billing</h1>
                <div class="text-[13px] text-fg-3 mt-1.5">Fund and manage your Kraite subscription — prepaid in USDT, debited monthly by your plan.</div>
            </div>
            <div class="flex items-center gap-3 flex-shrink-0 max-[820px]:flex-wrap max-[820px]:gap-y-2.5">
                <a href="{{ route('billing') }}" class="{{ $btnSecondary }} h-[34px] px-3 no-underline">
                    <x-feathericon-refresh-cw class="w-[15px] h-[15px]" stroke-width="1.75"/>Refresh
                </a>
            </div>
        </div>

        @if(session('status'))
            <div class="mb-5 rounded-control border border-accent/40 bg-accent/10 px-4 py-3 text-[13px] text-fg-1">{{ session('status') }}</div>
        @endif
        @if(session('error'))
            <div class="mb-5 rounded-control border border-danger/40 bg-danger/10 px-4 py-3 text-[13px] text-danger">{{ session('error') }}</div>
        @endif
        @if($errors->any())
            <div class="mb-5 rounded-control border border-danger/40 bg-danger/10 px-4 py-3 text-[13px] text-danger">{{ $errors->first() }}</div>
        @endif

        {{-- ===================== STATE BANNERS ===================== --}}
        <template x-if="view === 'no-plan'">
            <div class="rounded-surface border px-5 py-4 mb-6 flex items-center gap-4 max-[760px]:flex-col max-[760px]:items-start"
                 style="border-color: color-mix(in srgb, var(--accent) 42%, transparent); background: color-mix(in srgb, var(--accent) 9%, transparent);">
                <span class="flex-shrink-0 flex text-accent"><x-feathericon-flag class="w-[22px] h-[22px]" stroke-width="1.75"/></span>
                <div class="flex-1 min-w-0">
                    <div class="font-sans font-semibold text-[14.5px] text-fg-1 leading-tight">Welcome to Kraite — pick a plan to get started</div>
                    <div class="text-[12.5px] text-fg-3 mt-1 leading-snug">Choose a plan below to begin its free trial. You won't be charged until the trial ends — and you can fund your wallet any time.</div>
                </div>
                <div class="flex-shrink-0 max-[760px]:w-full">
                    <button type="button" @click="focusPlans()" class="{{ $btnPrimary }} h-[38px] px-4">See plans<x-feathericon-arrow-down class="w-[15px] h-[15px]" stroke-width="1.75"/></button>
                </div>
            </div>
        </template>
        <template x-if="view === 'trial-ready'">
            <div class="rounded-surface border px-5 py-4 mb-6 flex items-center gap-4 max-[760px]:flex-col max-[760px]:items-start"
                 style="border-color: color-mix(in srgb, var(--accent) 42%, transparent); background: color-mix(in srgb, var(--accent) 9%, transparent);">
                <span class="flex-shrink-0 flex text-accent"><x-feathericon-zap class="w-[22px] h-[22px]" stroke-width="1.75"/></span>
                <div class="flex-1 min-w-0">
                    <div class="font-sans font-semibold text-[14.5px] text-fg-1 leading-tight" x-text="`You're on ${planName()} — start your ${trialDays()}-day free trial`"></div>
                    <div class="text-[12.5px] text-fg-3 mt-1 leading-snug">Starting trading begins the trial and the bot goes live. The wallet stays untouched until the first renewal, <span x-text="`${trialDays()} days after you start`"></span>.</div>
                </div>
                <div class="flex-shrink-0 max-[760px]:w-full">
                    <button type="button" @click="startTrial()" class="{{ $btnPrimary }} h-[40px] px-5 text-[13px]"><x-feathericon-play class="w-[15px] h-[15px]" stroke-width="1.75"/>Start trading</button>
                </div>
            </div>
        </template>
        <template x-if="view === 'trial'">
            <div class="rounded-surface border px-5 py-4 mb-6 flex items-center gap-4 max-[760px]:flex-col max-[760px]:items-start"
                 style="border-color: color-mix(in srgb, var(--info) 42%, transparent); background: color-mix(in srgb, var(--info) 9%, transparent);">
                <span class="flex-shrink-0 flex animate-pulse-soft" style="color: var(--info);"><x-feathericon-clock class="w-[22px] h-[22px]" stroke-width="1.75"/></span>
                <div class="flex-1 min-w-0">
                    <div class="font-sans font-semibold text-[14.5px] text-fg-1 leading-tight" x-text="`Free trial active — ${countdown(trialSecs)} left`"></div>
                    <div class="text-[12.5px] text-fg-3 mt-1 leading-snug">The bot is trading live. Your wallet is untouched during the trial; the first renewal lands on <span x-text="renewalLabel"></span>. Fund your wallet now so the first renewal can't fail.</div>
                </div>
            </div>
        </template>
        <template x-if="view === 'paused'">
            <div class="rounded-surface border px-5 py-4 mb-6 flex items-center gap-4 max-[760px]:flex-col max-[760px]:items-start"
                 style="border-color: color-mix(in srgb, var(--warn) 42%, transparent); background: color-mix(in srgb, var(--warn) 9%, transparent);">
                <span class="flex-shrink-0 flex text-warn"><x-feathericon-pause class="w-[22px] h-[22px]" stroke-width="1.75"/></span>
                <div class="flex-1 min-w-0">
                    <div class="font-sans font-semibold text-[14.5px] text-fg-1 leading-tight" x-text="`Subscription paused since ${pausedSince}`"></div>
                    <div class="text-[12.5px] text-fg-3 mt-1 leading-snug">
                        @if($isComplimentaryPlan)
                            Trading is paused. Existing positions keep closing; new positions are blocked. Your complimentary plan stays assigned.
                        @else
                            Renewals are stopped. Existing positions keep trading; new positions are blocked. Resuming moves your renewal date forward by the pause length.
                        @endif
                    </div>
                </div>
                <div class="flex-shrink-0 max-[760px]:w-full">
                    <button type="button" @click="resume()" class="{{ $btnPrimary }} h-[40px] px-5 text-[13px]"><x-feathericon-play class="w-[15px] h-[15px]" stroke-width="1.75"/>Resume subscription</button>
                </div>
            </div>
        </template>
        <template x-if="view === 'read-only'">
            <div class="rounded-surface border px-5 py-4 mb-6 flex items-center gap-4 max-[760px]:flex-col max-[760px]:items-start"
                 style="border-color: color-mix(in srgb, var(--danger) 42%, transparent); background: color-mix(in srgb, var(--danger) 9%, transparent);">
                <span class="flex-shrink-0 flex animate-pulse-soft text-danger"><x-feathericon-lock class="w-[22px] h-[22px]" stroke-width="1.75"/></span>
                <div class="flex-1 min-w-0">
                    <div class="font-sans font-semibold text-[14.5px] text-fg-1 leading-tight" x-text="`Read-only mode — renewal failed, ${usdt2(shortfall())} USDT short`"></div>
                    <div class="text-[12.5px] text-fg-3 mt-1 leading-snug">The wallet couldn't cover the <span x-text="planName()"></span> renewal. The bot has stopped opening new positions — existing positions still close at their take-profit or stop-loss. Top up to clear the shortfall and the renewal retries immediately.</div>
                </div>
                <div class="flex-shrink-0 max-[760px]:w-full">
                    <button type="button" @click="focusTopUp()" class="{{ $btnPrimary }} h-[40px] px-5 text-[13px]" style="background: var(--danger); color: #fff;"><x-feathericon-plus class="w-[15px] h-[15px]" stroke-width="1.75"/>Top up to retry now</button>
                </div>
            </div>
        </template>

        {{-- ===================== WALLET HERO ===================== --}}
        <div class="card card--flat mb-6 overflow-visible">
            <div class="grid grid-cols-[1.15fr_1fr] max-[760px]:grid-cols-1">
                {{-- balance --}}
                <div class="p-6 max-[640px]:p-5 flex flex-col gap-3 relative">
                    <div class="flex items-center gap-2.5">
                        <div class="{{ $eyebrow }}">Prepaid wallet</div>
                        <span class="inline-flex items-center gap-1.5 font-mono text-[10px] text-fg-mute tracking-[0.04em]">
                            <span class="w-1.5 h-1.5 rounded-chip bg-accent animate-pulse-soft"></span>live
                        </span>
                        <template x-if="credited">
                            <span class="inline-flex items-center gap-1 font-mono text-[10.5px] font-bold tracking-[0.04em] py-[2px] px-2 rounded-chip animate-dd-in text-pnlup bg-pnlup-bg">
                                <x-feathericon-arrow-down-left class="w-[11px] h-[11px]" stroke-width="2"/><span x-text="`+${usdt(credited, 4)} credited`"></span>
                            </span>
                        </template>
                    </div>
                    <div class="flex items-baseline gap-2 rounded-control -mx-1 px-1" :class="credited ? 'animate-flash-up' : ''">
                        <span class="font-mono font-semibold tabular-nums tracking-[-0.03em] text-fg-1 leading-none text-[56px] max-[640px]:text-[44px]" x-text="walletWhole()"></span>
                        <span class="font-mono font-semibold tabular-nums tracking-[-0.02em] text-fg-mute leading-none text-[30px] max-[640px]:text-[24px]" x-text="'.' + walletFrac()"></span>
                        <span class="font-mono text-[15px] font-semibold text-fg-3 ml-1 self-end mb-1">USDT</span>
                    </div>
                    <div class="font-mono text-[11.5px] text-fg-mute tabular-nums tracking-[0.02em]" x-text="`≈ $${usdt2(wallet)} · held by Kraite · polled just now`"></div>
                    <button type="button" @click="focusTopUp()" class="{{ $btnPrimary }} h-[38px] px-4 self-start mt-1"><x-feathericon-plus class="w-[15px] h-[15px]" stroke-width="1.75"/>Add funds</button>
                </div>

                {{-- renewal picture (state-driven) --}}
                <div class="p-6 max-[640px]:p-5 border-l border-line-soft max-[760px]:border-l-0 max-[760px]:border-t"
                     :style="view === 'read-only' ? 'background: color-mix(in srgb, var(--danger) 7%, transparent)' : ''">

                    <template x-if="view === 'no-plan'">
                        <div class="flex flex-col items-start justify-center h-full gap-2">
                            <div class="{{ $eyebrow }}">No active plan</div>
                            <div class="text-[13px] text-fg-3 leading-snug max-w-[260px]">Pick a plan below to start its free trial. The wallet is only charged after the trial ends.</div>
                        </div>
                    </template>

                    <template x-if="view === 'trial-ready'">
                        <div class="flex flex-col items-start justify-center h-full gap-2">
                            <div class="{{ $eyebrow }}">Trial not started</div>
                            <div class="font-sans text-[15px] text-fg-1 font-semibold"><span x-text="planName()"></span> · <span x-text="usdt2(rate())"></span> USDT<span class="text-fg-mute font-normal">/mo</span></div>
                            <div class="text-[12.5px] text-fg-3 leading-snug max-w-[260px]">Your <span x-text="trialDays()"></span>-day free trial begins when you start trading. No debit until the first renewal.</div>
                        </div>
                    </template>

                    <template x-if="view === 'trial'">
                        <div class="flex flex-col items-start justify-center h-full gap-2.5">
                            <div class="{{ $eyebrow }} flex items-center gap-2"><span class="w-1.5 h-1.5 rounded-chip bg-info animate-pulse-soft"></span>Free trial · ends in</div>
                            <div class="font-mono text-[26px] font-semibold tabular-nums tracking-[-0.01em] text-fg-1 leading-none" x-text="countdown(trialSecs)"></div>
                            <div class="text-[12px] text-fg-3 leading-snug">First renewal <span class="text-fg-1 font-semibold" x-text="renewalLabel"></span> · <span x-text="`${planName()} ${usdt2(rate())} USDT/mo`"></span>. Wallet untouched during the trial.</div>
                        </div>
                    </template>

                    <template x-if="view === 'complimentary' && !pausing">
                        <div class="flex flex-col h-full">
                            <div class="flex flex-1 flex-col justify-center gap-2">
                                <div class="{{ $eyebrow }} flex items-center gap-2"><x-feathericon-star class="h-3 w-3" stroke-width="2"/>Complimentary access</div>
                                <div class="font-sans text-[17px] font-semibold text-fg-1" x-text="planName()"></div>
                                <div class="max-w-[310px] text-[12.5px] leading-snug text-fg-3">Free forever. No trial, renewal date, or wallet debit. Trading stays enabled while this plan is assigned.</div>
                            </div>
                            <button type="button" @click="pausing = true" class="mt-3 inline-flex cursor-pointer appearance-none self-start items-center gap-1.5 border-0 bg-transparent font-mono text-[10.5px] uppercase tracking-[0.06em] text-fg-mute transition-colors duration-fast hover:text-fg-2"><x-feathericon-pause class="h-3 w-3" stroke-width="2"/>Pause subscription</button>
                        </div>
                    </template>

                    <template x-if="view === 'paused'">
                        <div class="flex flex-col items-start justify-center h-full gap-2">
                            <div class="{{ $eyebrow }} flex items-center gap-2 !text-warn"><x-feathericon-pause class="w-3 h-3" stroke-width="2"/>Subscription paused</div>
                            <div class="font-sans text-[15px] text-fg-1 font-semibold" x-text="`Paused since ${pausedSince}`"></div>
                            <div class="text-[12.5px] text-fg-3 leading-snug max-w-[270px]">
                                @if($isComplimentaryPlan)
                                    Your free access stays assigned. Existing positions keep closing; new positions are blocked until you resume.
                                @else
                                    Renewals are stopped and the wallet is untouched. Existing positions keep trading; new positions are blocked. Resuming pushes the renewal date forward by the pause length.
                                @endif
                            </div>
                        </div>
                    </template>

                    <template x-if="view === 'read-only'">
                        <div class="flex flex-col items-start justify-center h-full gap-2.5">
                            <div class="font-mono text-[10px] font-semibold tracking-[0.11em] uppercase flex items-center gap-2 text-danger"><x-feathericon-lock class="w-3 h-3" stroke-width="2"/><span x-text="`Renewal failed · ${renewalLabel}`"></span></div>
                            <div class="flex items-baseline gap-2">
                                <span class="font-mono text-[28px] font-semibold tabular-nums tracking-[-0.02em] leading-none text-pnldown" x-text="`−${usdt2(shortfall())}`"></span>
                                <span class="font-mono text-[12px] text-fg-mute">USDT short</span>
                            </div>
                            <button type="button" @click="focusTopUp()" class="{{ $btnPrimary }} h-[36px] px-3.5"><x-feathericon-plus class="w-3.5 h-3.5" stroke-width="1.75"/><span x-text="`Top up ${usdt2(shortfall())} USDT to retry`"></span></button>
                        </div>
                    </template>

                    {{-- pause confirm (active view, pausing) --}}
                    <template x-if="(view === 'active' || view === 'complimentary') && pausing">
                        <div class="flex flex-col items-start justify-center h-full gap-3">
                            <div>
                                <div class="font-sans font-semibold text-[14px] text-fg-1">Pause subscription?</div>
                                <div class="text-[12px] text-fg-3 mt-1 leading-snug max-w-[270px]">
                                    @if($isComplimentaryPlan)
                                        Existing positions keep closing; new positions are blocked. Your free plan stays assigned and can be resumed anytime.
                                    @else
                                        Renewals stop and nothing is debited. Existing positions keep trading; new positions are blocked. Resume anytime — your renewal date moves forward by the pause length.
                                    @endif
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <button type="button" @click="pauseConfirm()" class="{{ $btnPrimary }} h-[34px] px-3.5" style="background: var(--warn); color: #1a1200;"><x-feathericon-pause class="w-3.5 h-3.5" stroke-width="1.75"/>Pause now</button>
                                <button type="button" @click="pausing = false" class="{{ $btnSecondary }} h-[34px] px-3">Keep active</button>
                            </div>
                        </div>
                    </template>

                    {{-- active — covered / short --}}
                    <template x-if="view === 'active' && !pausing">
                        <div class="flex flex-col h-full">
                            <div class="flex-1 flex flex-col justify-center gap-2">
                                <div class="{{ $eyebrow }}">Next renewal</div>
                                <div class="flex items-baseline gap-2 flex-wrap">
                                    <span class="font-sans text-[17px] text-fg-1 font-semibold" x-text="planName()"></span>
                                    <span class="font-mono text-[14px] text-fg-2 tabular-nums"><span x-text="usdt2(rate())"></span> USDT<span class="text-fg-mute">/mo</span></span>
                                </div>
                                <div class="font-mono text-[12.5px] text-fg-3 tabular-nums" x-text="`${renewalLabel} · in ${daysLeft} days`"></div>
                                <template x-if="covered()">
                                    <div class="inline-flex items-center gap-2 mt-1 font-mono text-[11.5px] font-semibold text-pnlup">
                                        <x-feathericon-check class="w-3.5 h-3.5" stroke-width="2"/><span x-text="`Wallet covers next renewal · ${usdt2(surplus())} USDT left after`"></span>
                                    </div>
                                </template>
                                <template x-if="!covered()">
                                    <div class="flex items-center gap-2.5 mt-1 flex-wrap">
                                        <span class="inline-flex items-center gap-1.5 font-mono text-[12px] font-semibold text-pnldown"><x-feathericon-alert-triangle class="w-[13px] h-[13px]" stroke-width="1.75"/><span x-text="`Short ${usdt2(shortfall())} USDT`"></span></span>
                                        <button type="button" @click="focusTopUp()" class="{{ $btnPrimary }} h-[30px] px-3 text-[11.5px]"><x-feathericon-plus class="w-[13px] h-[13px]" stroke-width="1.75"/>Top up</button>
                                    </div>
                                </template>
                            </div>
                            <button type="button" @click="pausing = true" class="self-start mt-3 appearance-none bg-transparent border-0 cursor-pointer font-mono text-[10.5px] tracking-[0.06em] uppercase text-fg-mute inline-flex items-center gap-1.5 transition-colors duration-fast hover:text-fg-2">
                                <x-feathericon-pause class="w-3 h-3" stroke-width="2"/>Pause subscription
                            </button>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        {{-- ===================== PLANS ===================== --}}
        <div x-ref="plans" class="scroll-mt-4">
            <div class="flex items-center justify-between gap-3 mb-4">
                <span class="font-mono text-[10.5px] font-semibold tracking-[0.12em] uppercase text-fg-mute" x-text="view === 'no-plan' ? 'Choose a plan' : 'Plans'"></span>
                <span class="font-mono text-[10.5px] text-fg-faint tracking-[0.04em] max-[640px]:hidden">Trial duration shown per plan</span>
            </div>
            <div class="grid grid-cols-2 gap-4 max-[680px]:grid-cols-1">
                @foreach($plans as $p)
                    <div class="card relative flex flex-col p-5 max-[640px]:p-4"
                         :style="plan === '{{ $p['id'] }}' && view !== 'no-plan' ? 'border-color: var(--accent); box-shadow: inset 0 0 0 1px var(--accent), 0 2px 14px rgba(0,0,0,0.35)' : ''">
                        <div class="flex items-center justify-between gap-2 mb-3">
                            <h3 class="font-sans font-semibold text-[16px] tracking-[-0.01em] text-fg-1">{{ $p['name'] }}</h3>
                            <span x-show="plan === '{{ $p['id'] }}' && view !== 'no-plan'" class="font-mono text-[9px] font-bold tracking-[0.1em] uppercase py-[3px] px-2 rounded-chip text-accent" style="background: color-mix(in srgb, var(--accent) 14%, transparent);">Active</span>
                            @if($p['popular'])
                                <span x-show="!(plan === '{{ $p['id'] }}' && view !== 'no-plan')" class="font-mono text-[9px] font-bold tracking-[0.1em] uppercase py-[3px] px-2 rounded-chip text-accent" style="background: color-mix(in srgb, var(--accent) 14%, transparent);">Popular</span>
                            @endif
                        </div>
                        <div class="flex items-baseline gap-1.5 mb-1">
                            <span class="font-mono font-semibold tabular-nums tracking-[-0.03em] text-fg-1 leading-none text-[34px]">${{ $p['price'] }}</span>
                            <span class="font-mono text-[13px] text-fg-mute">/mo</span>
                        </div>
                        <div class="font-mono text-[10px] text-fg-mute mb-3">{{ $p['trial_days'] }}-day free trial</div>
                        <div class="text-[12.5px] text-fg-3 leading-snug mb-4 min-h-[34px]">{{ $p['blurb'] }}</div>
                        <div class="flex flex-col gap-2 mb-5">
                            @foreach($p['features'] as $fi => $f)
                                <div class="flex items-center gap-2.5 text-[12.5px] text-fg-2">
                                    @if($fi === 0 && $p['id'] === 'unlimited')
                                        <span class="text-accent font-mono font-bold text-[14px] leading-none flex-shrink-0 w-3.5 text-center">∞</span>
                                    @else
                                        <span class="text-accent flex-shrink-0"><x-feathericon-check class="w-3.5 h-3.5" stroke-width="2"/></span>
                                    @endif
                                    {{ $f }}
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-auto">
                            <span x-show="plan === '{{ $p['id'] }}' && view !== 'no-plan'"
                                  class="inline-flex items-center justify-center gap-1.5 h-[38px] rounded-control font-sans font-semibold text-[12.5px] w-full whitespace-nowrap text-accent"
                                  style="background: color-mix(in srgb, var(--accent) 12%, transparent);">
                                <x-feathericon-check class="w-[15px] h-[15px]" stroke-width="2"/>Current plan
                            </span>
                            <button type="button" x-show="!(plan === '{{ $p['id'] }}' && view !== 'no-plan')"
                                    @click="view === 'no-plan' ? choosePlan('{{ $p['id'] }}') : startSwitch('{{ $p['id'] }}')"
                                    :class="view === 'no-plan' ? @js($btnPrimary) : @js($btnSecondary)"
                                    class="w-full justify-center h-[38px] whitespace-nowrap"
                                    x-text="view === 'no-plan' ? 'Choose {{ $p['name'] }}' : 'Switch to {{ $p['name'] }}'"></button>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- switch confirm --}}
            <template x-if="switchTo">
                <div class="card card--flat mt-4 overflow-hidden animate-dd-in">
                    <div class="flex items-center justify-between gap-3 py-[13px] px-6 bg-surface-2 border-b border-line-soft max-[640px]:px-4">
                        <h4 class="font-sans font-semibold text-[14px] text-fg-1 flex items-center gap-[9px] whitespace-nowrap leading-none">
                            <x-feathericon-refresh-cw class="w-4 h-4 text-fg-3" stroke-width="1.75"/>
                            <span x-text="`Switch ${planName() ? planName() + ' → ' : 'to '}${nameOf(switchTo)}`"></span>
                        </h4>
                        <button type="button" @click="cancelSwitch()" class="appearance-none bg-transparent border-0 cursor-pointer text-fg-mute hover:text-fg-1 transition-colors rotate-45"><x-feathericon-plus class="w-[18px] h-[18px]" stroke-width="1.75"/></button>
                    </div>

                    <div class="p-6 max-[640px]:p-4 flex flex-col gap-5">
                        {{-- trial: free + instant --}}
                        <template x-if="isTrialView()">
                            <div class="flex items-start gap-3 rounded-control border px-4 py-3.5" style="border-color: color-mix(in srgb, var(--info) 38%, transparent); background: color-mix(in srgb, var(--info) 9%, transparent);">
                                <span class="flex-shrink-0 mt-px" style="color: var(--info);"><x-feathericon-clock class="w-[17px] h-[17px]" stroke-width="1.75"/></span>
                                <div class="text-[12.5px] text-fg-2 leading-snug">During your free trial, plan changes are <span class="font-semibold text-fg-1">free and instant</span> — no proration and no debit. <span x-text="nameOf(switchTo)"></span> takes effect immediately.</div>
                            </div>
                        </template>

                        {{-- paid: proration breakdown --}}
                        <template x-if="!isTrialView() && view !== 'no-plan'">
                            <div class="rounded-control border border-line-soft overflow-hidden">
                                <div class="flex items-center justify-between gap-4 py-3 px-4 border-b border-line-soft bg-surface-2">
                                    <span class="text-[12.5px] text-fg-2" x-text="`Prorate refund · ${daysLeft} unused days of ${planName()}`"></span>
                                    <span class="font-mono text-[13px] font-semibold tabular-nums text-pnlup" x-text="usdtSigned(prorationRefund())"></span>
                                </div>
                                <div class="flex items-center justify-between gap-4 py-3 px-4 border-b border-line-soft">
                                    <span class="text-[12.5px] text-fg-2" x-text="`${nameOf(switchTo)} · one month`"></span>
                                    <span class="font-mono text-[13px] font-semibold tabular-nums text-pnldown" x-text="usdtSigned(-prorationDebit())"></span>
                                </div>
                                <div class="flex items-center justify-between gap-4 py-3.5 px-4" :style="switchShort() ? 'background: color-mix(in srgb, var(--danger) 8%, transparent)' : ''">
                                    <span class="font-sans text-[12.5px] font-semibold text-fg-1 whitespace-nowrap">Wallet after switch</span>
                                    <span class="font-mono text-[15px] font-semibold tabular-nums" :class="switchShort() ? 'text-pnldown' : 'text-fg-1'" x-text="`${switchShort() ? '−' : ''}${usdt(Math.abs(walletAfter()))} USDT`"></span>
                                </div>
                            </div>
                        </template>

                        {{-- downgrade-from-unlimited: pick which account stays active --}}
                        <template x-if="downgradeAccts()">
                            <div>
                                <div class="font-mono text-[10px] font-semibold tracking-[0.11em] uppercase text-fg-mute mb-2.5 flex items-center gap-2">
                                    <span class="text-warn"><x-feathericon-alert-triangle class="w-[13px] h-[13px]" stroke-width="1.75"/></span>
                                    <span x-text="`${nameOf(switchTo)} allows one account — keep which active?`"></span>
                                </div>
                                <div class="grid grid-cols-2 gap-2 max-[560px]:grid-cols-1">
                                    @foreach($pickerAccounts as $pa)
                                        <button type="button" @click="keepAcct = {{ $pa['id'] }}"
                                                class="appearance-none cursor-pointer text-left flex items-center gap-3 rounded-control border bg-surface-2 py-2.5 px-3 transition-colors duration-fast"
                                                :style="keepAcct === {{ $pa['id'] }} ? 'border-color: var(--accent); box-shadow: inset 0 0 0 1px var(--accent)' : 'border-color: var(--border)'">
                                            <span class="w-[28px] h-[28px] rounded-full bg-surface-3 text-fg-2 font-mono font-bold text-[11px] flex items-center justify-center flex-shrink-0">{{ $pa['mono'] }}</span>
                                            <span class="flex flex-col leading-[1.2] min-w-0 flex-1">
                                                <span class="text-[12.5px] font-semibold text-fg-1 whitespace-nowrap">{{ $pa['ex'] }} <span class="text-fg-mute font-normal">· {{ $pa['tag'] }}</span></span>
                                                <span class="font-mono text-[10px] text-fg-mute tabular-nums">{{ $pa['equity'] }}</span>
                                            </span>
                                            <span class="w-4 h-4 rounded-full flex items-center justify-center flex-shrink-0"
                                                  :style="keepAcct === {{ $pa['id'] }} ? 'background: var(--accent)' : 'box-shadow: inset 0 0 0 1.5px var(--border-strong)'">
                                                <span x-show="keepAcct === {{ $pa['id'] }}" class="text-accent-on"><x-feathericon-check class="w-[11px] h-[11px]" stroke-width="2.5"/></span>
                                            </span>
                                        </button>
                                    @endforeach
                                </div>
                                <div class="text-[11.5px] text-fg-mute mt-2 leading-snug">The other {{ count($pickerAccounts) - 1 }} accounts stay connected but the bot stops trading them until you upgrade again.</div>
                            </div>
                        </template>

                        {{-- short: top up first --}}
                        <template x-if="switchShort()">
                            <div class="flex items-center gap-3 rounded-control border px-4 py-3.5 flex-wrap" style="border-color: color-mix(in srgb, var(--danger) 42%, transparent); background: color-mix(in srgb, var(--danger) 9%, transparent);">
                                <span class="flex-shrink-0 text-danger"><x-feathericon-alert-triangle class="w-[17px] h-[17px]" stroke-width="1.75"/></span>
                                <span class="text-[12.5px] text-fg-2 flex-1 min-w-[200px]">The wallet falls <span class="font-mono font-semibold text-pnldown" x-text="`${usdt2(Math.abs(walletAfter()))} USDT`"></span> short of <span x-text="nameOf(switchTo)"></span> after the refund. Top up first, then switch.</span>
                                <button type="button" @click="focusTopUp()" class="{{ $btnPrimary }} h-[34px] px-3.5" style="background: var(--danger); color: #fff;"><x-feathericon-plus class="w-3.5 h-3.5" stroke-width="1.75"/><span x-text="`Top up ${usdt2(Math.abs(walletAfter()))}`"></span></button>
                            </div>
                        </template>
                        <template x-if="!switchShort()">
                            <div class="flex items-center gap-2.5 flex-wrap">
                                <button type="button" @click="confirmSwitch()" :disabled="busy || (requiresAccountSelection() && !keepAcct)" class="{{ $btnPrimary }} h-[40px] px-5 disabled:opacity-40 disabled:cursor-not-allowed"><x-feathericon-check class="w-4 h-4" stroke-width="2"/><span x-text="`Confirm switch to ${nameOf(switchTo)}`"></span></button>
                                <button type="button" @click="cancelSwitch()" class="{{ $btnSecondary }} h-[40px] px-4">Cancel</button>
                                <span x-show="!isTrialView() && view !== 'no-plan'" class="font-mono text-[10.5px] text-fg-mute tracking-[0.03em]" x-text="`Refund credits instantly, then ${nameOf(switchTo)} is debited`"></span>
                            </div>
                        </template>
                    </div>
                </div>
            </template>
        </div>

        <div class="h-6"></div>

        {{-- ===================== TOP UP ===================== --}}
        <div x-ref="topup" class="card card--flat mb-6 overflow-hidden scroll-mt-4">
            {{-- invoice step --}}
            <template x-if="invoice">
                <div>
                    <div class="flex items-center justify-between gap-3 py-[13px] px-6 bg-surface-2 border-b border-line-soft max-[640px]:px-4">
                        <h4 class="font-sans font-semibold text-[14px] text-fg-1 flex items-center gap-[9px] whitespace-nowrap leading-none">
                            <x-feathericon-arrow-up-right class="w-4 h-4 text-fg-3" stroke-width="1.75"/>Leaving Kraite — NOWPayments checkout
                        </h4>
                    </div>
                    <div class="p-6 max-[640px]:p-4 flex flex-col gap-5">
                        <div class="flex items-start gap-3 rounded-control border px-4 py-3.5" style="border-color: color-mix(in srgb, var(--info) 38%, transparent); background: color-mix(in srgb, var(--info) 9%, transparent);">
                            <span class="flex-shrink-0 mt-px" style="color: var(--info);"><x-feathericon-shield class="w-[17px] h-[17px]" stroke-width="1.75"/></span>
                            <div class="text-[12.5px] text-fg-2 leading-snug">You'll be taken to <span class="font-semibold text-fg-1">NOWPayments</span> to complete this top-up — you're leaving Kraite. <span class="font-semibold text-fg-1">Choose your coin and network there</span>; non-USDT coins convert to USDT at the gateway rate. Your wallet credits automatically once the transfer confirms on-chain.</div>
                        </div>
                        <div class="rounded-control border border-line-soft overflow-hidden">
                            <div class="flex items-center justify-between gap-4 py-3 px-4 border-b border-line-soft">
                                <span class="text-[12.5px] text-fg-3">Credit to wallet</span>
                                <span class="font-mono text-[13px] font-semibold text-fg-1 tabular-nums" x-text="`${usdt2(invoice.amount)} USDT`"></span>
                            </div>
                            <div class="flex items-center justify-between gap-4 py-3 px-4 border-b border-line-soft">
                                <span class="text-[12.5px] text-fg-3">Pay with</span>
                                <span class="font-mono text-[13px] font-semibold text-fg-1 tabular-nums">Chosen on NOWPayments</span>
                            </div>
                            <div class="flex items-center justify-between gap-4 py-3 px-4">
                                <span class="text-[12.5px] text-fg-3">Gateway fee</span>
                                <span class="font-mono text-[13px] font-semibold text-fg-1 tabular-nums">≈ 0.5% + network gas</span>
                            </div>
                        </div>
                        <div class="flex items-center gap-2.5 flex-wrap">
                            <button type="button" @click="continueGateway()" :disabled="busy" class="{{ $btnPrimary }} h-[40px] px-5 disabled:opacity-40 disabled:cursor-not-allowed">Continue to NOWPayments<x-feathericon-arrow-up-right class="w-[15px] h-[15px]" stroke-width="1.75"/></button>
                            <button type="button" @click="invoice = null" class="{{ $btnSecondary }} h-[40px] px-4">Back</button>
                        </div>
                    </div>
                </div>
            </template>

            {{-- amount step --}}
            <template x-if="!invoice">
                <div>
                    <div class="flex items-center justify-between gap-3 py-[13px] px-6 bg-surface-2 border-b border-line-soft max-[640px]:px-4">
                        <h4 class="font-sans font-semibold text-[14px] text-fg-1 flex items-center gap-[9px] whitespace-nowrap leading-none">
                            <x-feathericon-plus class="w-4 h-4 text-fg-3" stroke-width="1.75"/>Top up wallet
                        </h4>
                        <span class="font-mono text-[10.5px] text-fg-mute tracking-[0.02em]">crypto · via NOWPayments</span>
                    </div>
                    <div class="p-6 max-[640px]:p-4 flex flex-col gap-5">
                        <p class="text-[12.5px] text-fg-3 leading-snug max-w-[560px]">Enter how much USDT to add to your wallet. You'll pick the coin and network on the NOWPayments checkout — pay in USDT, USDC, BTC, ETH and more.</p>

                        <div class="grid grid-cols-[1fr_auto] gap-5 items-start max-[640px]:grid-cols-1">
                            <div>
                                <div class="font-mono text-[10px] font-semibold tracking-[0.11em] uppercase text-fg-mute mb-2.5">Amount to credit</div>
                                <div class="relative flex items-center">
                                    <input x-model="amount" @input="amount = amount.replace(/[^0-9.]/g, '')" inputmode="decimal"
                                           class="w-full h-[52px] bg-input border rounded-control pl-4 pr-[72px] font-mono text-[22px] font-semibold tabular-nums text-fg-1 outline-none transition-[border-color,box-shadow] duration-fast"
                                           :style="belowMin() ? 'border-color: var(--danger)' : 'border-color: var(--border)'"/>
                                    <span class="absolute right-4 font-mono text-[14px] font-semibold text-fg-mute">USDT</span>
                                </div>
                                <div class="flex items-center gap-1.5 mt-2.5 flex-wrap">
                                    <template x-for="v in presets()" :key="v">
                                        <button type="button" @click="amount = String(v)"
                                                class="appearance-none cursor-pointer font-mono text-[11px] font-semibold tabular-nums rounded-chip border border-line bg-surface-3 text-fg-2 py-1 px-2.5 transition-colors duration-fast hover:border-line-strong hover:text-fg-1"
                                                x-text="(v === effMin() ? 'Min ' : '') + usdt2(v)"></button>
                                    </template>
                                </div>
                                <div class="text-[11.5px] mt-2 leading-snug" :class="belowMin() ? 'text-danger' : 'text-fg-mute'">
                                    <span x-show="belowMin()" class="font-semibold">Below minimum. </span>
                                    <template x-if="!covered()">
                                        <span>Minimum <span class="font-mono text-fg-2" x-text="`${usdt2(effMin())} USDT`"></span> — clears your renewal shortfall.</span>
                                    </template>
                                    <template x-if="covered()">
                                        <span>Minimum top-up is <span class="font-mono text-fg-2" x-text="`${usdt2(effMin())} USDT`"></span>. NOWPayments may set a higher floor for some coins.</span>
                                    </template>
                                </div>
                            </div>

                            <div class="flex flex-col gap-2.5 min-w-[230px] max-[640px]:min-w-0 self-end">
                                <button type="button" :disabled="belowMin() || amtNum() <= 0" @click="topUpGo()"
                                        :class="(belowMin() || amtNum() <= 0) ? 'opacity-40 cursor-not-allowed hover:bg-accent' : ''"
                                        class="{{ $btnPrimary }} h-[52px] px-4 justify-center text-[13.5px]">
                                    <span x-text="`Top up ${usdt2(amtNum())} USDT`"></span><x-feathericon-arrow-up-right class="w-[15px] h-[15px]" stroke-width="1.75"/>
                                </button>
                                <span class="font-mono text-[10px] text-fg-mute tracking-[0.03em] text-center max-[640px]:text-left">Continues to NOWPayments</span>
                            </div>
                        </div>

                        {{-- accepted coins (informational) --}}
                        <div class="flex items-center gap-3 flex-wrap pt-1 border-t border-line-soft mt-1">
                            <span class="font-mono text-[9.5px] font-semibold tracking-[0.1em] uppercase text-fg-mute pt-3">Accepted</span>
                            <div class="flex items-center gap-1.5 flex-wrap pt-3">
                                @foreach($coins as $c)
                                    <span class="inline-flex items-center gap-1.5 rounded-chip border border-line-soft bg-surface-2 pl-1 pr-2.5 py-1">
                                        <span class="rounded-[8px] flex items-center justify-center flex-shrink-0 font-mono font-bold tracking-[0.01em] w-5 h-5 text-[7px]"
                                              style="background: color-mix(in srgb, {{ $c['color'] }} 22%, transparent); color: {{ $c['color'] }}; box-shadow: inset 0 0 0 1px color-mix(in srgb, {{ $c['color'] }} 45%, transparent);">{{ substr($c['sym'], 0, 3) }}</span>
                                        <span class="font-mono text-[11px] font-semibold text-fg-2">{{ $c['sym'] }}</span>
                                    </span>
                                @endforeach
                                <span class="font-mono text-[10.5px] text-fg-faint tracking-[0.03em] ml-1">on Tron · BNB Chain · Solana · Bitcoin · Ethereum &amp; more</span>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        {{-- ===================== TRANSACTION HISTORY ===================== --}}
        <div class="card card--flat mb-6 overflow-hidden">
            <div class="{{ $cardHead }}">
                <div class="{{ $cardTitle }}"><x-feathericon-activity class="w-4 h-4 text-fg-3" stroke-width="1.75"/>Transaction history</div>
                <span x-show="!emptyLedger()" class="font-mono text-[10.5px] text-fg-mute tracking-[0.04em] max-[640px]:hidden" x-text="`Last ${ledgerRows().length} movements`"></span>
            </div>

            {{-- empty state --}}
            <template x-if="emptyLedger()">
                <div class="flex flex-col items-center justify-center text-center py-[64px] px-5">
                    <div class="w-12 h-12 rounded-control border border-line flex items-center justify-center text-fg-mute mb-4"><x-feathericon-activity class="w-6 h-6" stroke-width="1.75"/></div>
                    <h4 class="font-sans font-semibold text-[17px] text-fg-1 mb-1.5">No wallet movements yet</h4>
                    <p class="text-[13px] text-fg-3 max-w-[380px]">Top-ups, subscription debits, refunds and bonuses will appear here. Add funds to get started.</p>
                </div>
            </template>

            <template x-if="!emptyLedger()">
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse min-w-[640px]">
                        <thead>
                            <tr class="border-b border-line-soft">
                                <th class="font-mono text-[9.5px] font-semibold tracking-[0.1em] uppercase text-fg-mute py-2.5 px-4 text-left">Date</th>
                                <th class="font-mono text-[9.5px] font-semibold tracking-[0.1em] uppercase text-fg-mute py-2.5 px-4 text-left">Type</th>
                                <th class="font-mono text-[9.5px] font-semibold tracking-[0.1em] uppercase text-fg-mute py-2.5 px-4 text-left">Description</th>
                                <th class="font-mono text-[9.5px] font-semibold tracking-[0.1em] uppercase text-fg-mute py-2.5 px-4 text-right">Amount</th>
                                <th class="font-mono text-[9.5px] font-semibold tracking-[0.1em] uppercase text-fg-mute py-2.5 px-4 text-right">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(m, i) in ledgerRows()" :key="i + m.date + m.amount">
                                <tr class="border-b border-line-soft last:border-b-0 hover:bg-hover transition-colors duration-fast">
                                    <td class="py-3 px-4 font-mono text-[11.5px] text-fg-3 tabular-nums whitespace-nowrap" x-text="m.date"></td>
                                    <td class="py-3 px-4">
                                        <span class="inline-flex items-center gap-1.5 py-[3px] pl-1.5 pr-2.5 rounded-chip font-mono text-[10px] font-bold tracking-[0.05em] uppercase whitespace-nowrap"
                                              :class="m.amount > 0 ? 'text-pnlup bg-pnlup-bg' : 'text-pnldown bg-pnldown-bg'">
                                            <template x-if="m.type === 'credit_topup'"><span class="inline-flex"><x-feathericon-arrow-down-left class="w-[11px] h-[11px]" stroke-width="2"/></span></template>
                                            <template x-if="m.type === 'credit_topup_bonus'"><span class="inline-flex"><x-feathericon-gift class="w-[11px] h-[11px]" stroke-width="2"/></span></template>
                                            <template x-if="m.type === 'debit_subscription' || m.type === 'credit_prorate_refund'"><span class="inline-flex"><x-feathericon-refresh-cw class="w-[11px] h-[11px]" stroke-width="2"/></span></template>
                                            <template x-if="m.type === 'credit_admin' || m.type === 'debit_admin'"><span class="inline-flex"><x-feathericon-shield class="w-[11px] h-[11px]" stroke-width="2"/></span></template>
                                            <span x-text="({'debit_subscription':'Subscription','credit_topup':'Top-up','credit_topup_bonus':'Bonus','credit_prorate_refund':'Prorate refund','credit_admin':'Admin credit','debit_admin':'Admin debit'})[m.type] || m.type"></span>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-[12.5px] text-fg-2" x-text="m.desc"></td>
                                    <td class="py-3 px-4 text-right font-mono text-[12.5px] font-semibold tabular-nums whitespace-nowrap"
                                        :class="m.amount > 0 ? 'text-pnlup' : 'text-pnldown'" x-text="usdtSigned(m.amount)"></td>
                                    <td class="py-3 px-4 text-right font-mono text-[12px] text-fg-3 tabular-nums whitespace-nowrap" x-text="usdt(m.balance)"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </template>
        </div>

        {{-- ===================== BILLING TERMS ===================== --}}
        <div class="card card--flat overflow-hidden" x-data="{ termsOpen: false }">
            <button type="button" @click="termsOpen = !termsOpen" class="w-full flex items-center gap-3 py-[15px] px-5 bg-transparent border-0 cursor-pointer text-left transition-colors duration-fast hover:bg-hover">
                <x-feathericon-shield class="w-4 h-4 text-fg-3 flex-shrink-0" stroke-width="1.75"/>
                <span class="font-sans font-semibold text-[14px] text-fg-1">Billing terms &amp; fine print</span>
                <span class="font-mono text-[10.5px] text-fg-mute tracking-[0.04em] ml-1 max-[560px]:hidden">monthly model · trial · fees · read-only</span>
                <span class="ml-auto text-fg-mute transition-transform duration-[220ms] ease-[cubic-bezier(0.16,1,0.3,1)]" :class="termsOpen ? 'rotate-180' : ''">
                    <x-feathericon-chevron-down class="w-[18px] h-[18px]" stroke-width="1.75"/>
                </span>
            </button>
            <div class="grid transition-[grid-template-rows] duration-[320ms] ease-[cubic-bezier(0.16,1,0.3,1)]" :style="`grid-template-rows: ${termsOpen ? '1fr' : '0fr'}`">
                <div class="min-h-0 overflow-hidden">
                    <div class="border-t border-line-soft px-5 py-5 grid grid-cols-2 gap-x-7 gap-y-5 max-[760px]:grid-cols-1">
                        @foreach($terms as $t)
                            <div class="flex items-start gap-3">
                                <div class="w-[30px] h-[30px] rounded-control bg-surface-3 border border-line flex items-center justify-center text-fg-2 flex-shrink-0 mt-0.5">
                                    <x-dynamic-component :component="'feathericon-' . $t['icon']" class="w-[15px] h-[15px]" stroke-width="1.75"/>
                                </div>
                                <div>
                                    <div class="font-sans font-semibold text-[13px] text-fg-1 mb-1">{{ $t['title'] }}</div>
                                    <div class="text-[12px] text-fg-3 leading-[1.5]">{{ $t['body'] }}</div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

    </div>

    <style>[x-cloak] { display: none !important; }</style>
</x-app-layout>
