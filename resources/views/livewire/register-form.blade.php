<form
    novalidate
    class="w-full max-w-3xl rounded-xl border border-white/10 bg-white p-5 text-gray-900 shadow-2xl sm:p-8"
    x-data="{
        password: @entangle('password'),
        exchange: @entangle('exchange').live,
        apiKey: @entangle('api_key'),
        apiSecret: @entangle('api_secret'),
        passphrase: @entangle('passphrase'),
        connectivityVerified: @entangle('connectivity_verified').live,
        connectivityComplete: @entangle('connectivity_complete').live,
        connectivityPassed: @entangle('connectivity_passed').live,
        connectivityTestUuid: @entangle('connectivity_test_uuid').live,
        continueWithoutConnectivity: @entangle('continue_without_connectivity').live,
        passwordStrengthThreshold: @js(\App\Support\Registration\PasswordStrength::THRESHOLD),
        connectivity: 'idle',
        connectivityMessage: '',
        connectivityServers: [],
        connectivityPollTimer: null,
        connectivityUrl: @js(route('register.connectivity', $user->uuid)),
        connectivityStatusUrlTemplate: @js(route('register.connectivity.status', [$user->uuid, '__BLOCK_UUID__'])),
        passwordStrengthScore() {
            const value = this.password || '';

            if (value.length === 0) {
                return 0;
            }

            const uniqueCharacters = new Set([...value]).size;
            let score = Math.min(40, value.length * 4);
            score += /[a-z]/.test(value) ? 10 : 0;
            score += /[A-Z]/.test(value) ? 10 : 0;
            score += /[0-9]/.test(value) ? 10 : 0;
            score += /[^a-zA-Z0-9]/.test(value) ? 10 : 0;
            score += Math.min(15, uniqueCharacters * 1.5);

            if (/(.)\1{2,}/.test(value)) {
                score -= 15;
            }

            if (['password', 'password1', '12345678', 'qwerty', 'letmein', 'admin', 'welcome'].includes(value.toLowerCase())) {
                score -= 20;
            }

            return Math.max(0, Math.min(100, Math.round(score)));
        },
        passwordStrengthLabel() {
            const score = this.passwordStrengthScore();

            if (score >= this.passwordStrengthThreshold) {
                return 'Strong enough';
            }

            if (score >= 50) {
                return 'Almost there';
            }

            if (score >= 30) {
                return 'Weak';
            }

            return score > 0 ? 'Too weak' : 'Start typing';
        },
        passwordStrengthColor() {
            const score = this.passwordStrengthScore();

            if (score >= this.passwordStrengthThreshold) {
                return 'bg-emerald-600';
            }

            if (score >= 50) {
                return 'bg-amber-500';
            }

            return 'bg-red-500';
        },
        needsPassphrase() {
            return this.exchange === 'kucoin' || this.exchange === 'bitget';
        },
        hasRequiredApiKeys() {
            return (this.apiKey || '').trim().length > 0 && (this.apiSecret || '').trim().length > 0 && (! this.needsPassphrase() || (this.passphrase || '').trim().length > 0);
        },
        selectExchange(exchange) {
            this.exchange = exchange;
            this.resetConnectivity();
        },
        resetConnectivity() {
            this.stopConnectivityPolling();
            this.connectivity = 'idle';
            this.connectivityMessage = '';
            this.connectivityServers = [];
            this.connectivityVerified = false;
            this.connectivityComplete = false;
            this.connectivityPassed = false;
            this.connectivityTestUuid = null;
            this.continueWithoutConnectivity = false;
        },
        stopConnectivityPolling() {
            if (this.connectivityPollTimer !== null) {
                clearInterval(this.connectivityPollTimer);
                this.connectivityPollTimer = null;
            }
        },
        async testConnectivity() {
            if (! this.hasRequiredApiKeys() || this.connectivity === 'testing') {
                return;
            }

            this.stopConnectivityPolling();
            this.connectivity = 'testing';
            this.connectivityMessage = 'Testing connectivity from required servers...';
            this.connectivityServers = [];
            this.connectivityComplete = false;
            this.connectivityPassed = false;
            this.connectivityVerified = false;
            this.continueWithoutConnectivity = false;

            try {
                const response = await fetch(this.connectivityUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    body: JSON.stringify({
                        exchange: this.exchange,
                        api_key: this.apiKey,
                        api_secret: this.apiSecret,
                        passphrase: this.passphrase,
                    }),
                });
                const data = await response.json().catch(() => ({}));

                if (! response.ok) {
                    this.connectivity = 'failed';
                    this.connectivityMessage = data.message || 'Connectivity failed.';

                    return;
                }

                this.applyConnectivityStatus(data);

                if (data.block_uuid && ! data.is_complete) {
                    this.pollConnectivityStatus(data.block_uuid);
                }
            } catch (error) {
                this.connectivity = 'failed';
                this.connectivityVerified = false;
                this.connectivityMessage = 'Connectivity failed. Try again in a moment.';
            }
        },
        pollConnectivityStatus(blockUuid) {
            this.connectivityTestUuid = blockUuid;
            this.connectivityPollTimer = setInterval(async () => {
                try {
                    const response = await fetch(this.connectivityStatusUrlTemplate.replace('__BLOCK_UUID__', blockUuid), {
                        headers: {
                            'Accept': 'application/json',
                        },
                    });
                    const data = await response.json().catch(() => ({}));

                    if (! response.ok) {
                        throw new Error(data.message || 'Connectivity status failed.');
                    }

                    this.applyConnectivityStatus(data);

                    if (data.is_complete) {
                        this.stopConnectivityPolling();
                    }
                } catch (error) {
                    this.stopConnectivityPolling();
                    this.connectivity = 'failed';
                    this.connectivityVerified = false;
                    this.connectivityComplete = false;
                    this.connectivityPassed = false;
                    this.connectivityMessage = 'Connectivity status failed. Try again in a moment.';
                }
            }, 2000);
        },
        applyConnectivityStatus(data) {
            this.connectivityTestUuid = data.block_uuid || this.connectivityTestUuid;
            this.connectivityServers = Array.isArray(data.servers) ? data.servers : [];
            this.connectivityComplete = !! data.is_complete;
            this.connectivityPassed = !! data.all_connected;
            this.connectivityVerified = this.connectivityComplete && this.connectivityPassed;

            if (! this.connectivityComplete) {
                this.connectivity = 'testing';
                this.connectivityMessage = this.connectivityServers.length > 0
                    ? `Testing connectivity from ${this.connectivityServers.length} required servers...`
                    : 'Testing connectivity from required servers...';

                return;
            }

            this.connectivity = this.connectivityPassed ? 'okay' : 'failed';
            this.connectivityMessage = this.connectivityPassed
                ? 'Connectivity verified, all good!'
                : 'Some servers could not connect. You can still create the account but the trading will be disabled.';
        },
    }"
    x-on:submit.prevent
>
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-emerald-700">Private beta</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-gray-950">Welcome to Kraite!</h1>
            <p class="mt-1 text-sm text-gray-600">{{ $user->email }}</p>
        </div>
        <div class="flex items-center gap-3">
            <div class="flex rounded-full border border-gray-200 bg-gray-50 p-1 text-xs font-semibold">
                <span @class([
                    'rounded-full px-3 py-1',
                    'bg-emerald-600 text-white' => $step === 'profile',
                    'text-gray-500' => $step !== 'profile',
                ])>1. Profile</span>
                <span @class([
                    'rounded-full px-3 py-1',
                    'bg-emerald-600 text-white' => $step === 'credentials',
                    'text-gray-500' => $step !== 'credentials',
                ])>2. API keys</span>
                <span @class([
                    'rounded-full px-3 py-1',
                    'bg-emerald-600 text-white' => $step === 'confirmation',
                    'text-gray-500' => $step !== 'confirmation',
                ])>3. Done</span>
            </div>
            <img src="{{ asset('logos/snake-green.svg') }}" alt="Kraite" class="h-12 w-12">
        </div>
    </div>

    @if ($step === 'profile')
        <div class="space-y-7">
            <section>
                <h2 class="text-sm font-semibold text-gray-950">Identity</h2>
                <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="name" class="mb-1 block text-xs font-medium text-gray-700">Name</label>
                        <input id="name" wire:model.blur="name" autocomplete="name" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20">
                        @error('name')
                            <p class="mt-1 text-xs text-red-700">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="password" class="mb-1 block text-xs font-medium text-gray-700">Password</label>
                        <input id="password" x-model="password" type="password" autocomplete="new-password" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20">
                        <div class="mt-2" data-password-strength>
                            <div class="flex items-center justify-between gap-3 text-[11px] font-medium">
                                <span class="text-gray-500">Password strength</span>
                                <span
                                    :class="passwordStrengthScore() >= passwordStrengthThreshold ? 'text-emerald-700' : 'text-gray-500'"
                                    x-text="passwordStrengthLabel()"
                                    data-password-strength-label
                                ></span>
                            </div>
                            <div
                                class="mt-1 h-2 overflow-hidden rounded-full bg-gray-100"
                                role="progressbar"
                                aria-label="Password strength"
                                :aria-valuenow="passwordStrengthScore()"
                                aria-valuemin="0"
                                aria-valuemax="100"
                            >
                                <div
                                    class="h-full rounded-full transition-all duration-300"
                                    :class="passwordStrengthColor()"
                                    :style="`width: ${passwordStrengthScore()}%`"
                                    data-password-strength-bar
                                ></div>
                            </div>
                        </div>
                        @error('password')
                            <p class="mt-1 text-xs text-red-700">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="password_confirmation" class="mb-1 block text-xs font-medium text-gray-700">Confirm password</label>
                        <input id="password_confirmation" wire:model="password_confirmation" type="password" autocomplete="new-password" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20">
                        @error('password_confirmation')
                            <p class="mt-1 text-xs text-red-700">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </section>

            <section>
                <h2 class="text-sm font-semibold text-gray-950">Plan</h2>
                <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    @foreach ($subscriptions as $subscription)
                        <label wire:key="registration-subscription-{{ $subscription->id }}" class="cursor-pointer rounded-lg border border-gray-200 p-4 transition has-[:checked]:border-emerald-600 has-[:checked]:bg-emerald-50 has-[:checked]:ring-2 has-[:checked]:ring-emerald-600/20">
                            <input type="radio" wire:model="subscription_id" value="{{ $subscription->id }}" class="sr-only">
                            <span class="block text-sm font-semibold text-gray-950">{{ $subscription->name }}</span>
                            <span class="mt-1 block text-xs text-gray-600">{{ $subscription->description }}</span>
                            <span class="mt-3 block font-mono text-sm text-gray-900">{{ number_format((float) $subscription->monthly_rate_usdt, 2) }} USDT/month</span>
                        </label>
                    @endforeach
                </div>
                @error('subscription_id')
                    <p class="mt-2 text-xs text-red-700">{{ $message }}</p>
                @enderror
            </section>

            <div class="rounded-lg border border-amber-300 bg-amber-100 px-4 py-3 text-sm font-semibold text-amber-950 shadow-sm">
                Since you registered for private beta, you get a 7-day free trial and any top-up will add 25% FREE to your account balance, forever.
            </div>

            <div>
                <label class="flex items-start gap-3 text-sm text-gray-700">
                    <input type="checkbox" wire:model="terms" class="mt-1 rounded border-gray-300 text-emerald-600 focus:ring-emerald-600">
                    <span>
                        I read the
                        <a
                            href="{{ rtrim((string) config('kraite.website_url'), '/') }}/terms-and-conditions"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="font-semibold text-emerald-700 underline decoration-emerald-300 underline-offset-2 transition hover:text-emerald-900"
                        >Terms & Conditions</a>
                    </span>
                </label>
                @error('terms')
                    <p class="mt-1 text-xs text-red-700">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex justify-end border-t border-gray-200 pt-5">
                <button
                    type="button"
                    wire:click="continueToCredentials"
                    wire:loading.attr="disabled"
                    wire:target="continueToCredentials"
                    class="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    Configure API keys
                </button>
            </div>
        </div>
    @elseif ($step === 'credentials')
        <div class="space-y-7">
            <section>
                <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 class="text-sm font-semibold text-gray-950">Trading exchange</h2>
                        <p class="mt-1 text-xs text-gray-500">Select the exchange that matches the API keys you are adding.</p>
                    </div>
                    <button
                        type="button"
                        wire:click="backToProfile"
                        class="text-sm font-semibold text-gray-500 transition hover:text-gray-950"
                    >
                        Back
                    </button>
                </div>

                <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    @foreach ($exchanges as $exchangeOption)
                        @php($exchangeEnabled = \App\Support\Registration\RegistrationExchange::isEnabled($exchangeOption->canonical))
                        <button
                            type="button"
                            wire:key="registration-exchange-{{ $exchangeOption->id }}"
                            @click="selectExchange(@js($exchangeOption->canonical))"
                            @disabled(! $exchangeEnabled)
                            @class([
                                'relative flex h-24 items-center justify-center rounded-lg border p-3 transition',
                                'cursor-pointer' => $exchangeEnabled,
                                'cursor-not-allowed border-gray-200 bg-gray-50' => ! $exchangeEnabled,
                            ])
                            :class="@js($exchangeEnabled) ? (exchange === @js($exchangeOption->canonical) ? 'border-emerald-600 bg-emerald-50 ring-2 ring-emerald-600/20' : 'border-gray-200 bg-white hover:border-gray-300') : 'border-gray-200 bg-gray-50'"
                            aria-label="{{ $exchangeEnabled ? 'Select' : 'Coming soon' }} {{ $exchangeOption->name }}"
                        >
                            @unless ($exchangeEnabled)
                                <span class="absolute right-2 top-2 rounded-full border border-gray-200 bg-white px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-gray-500">
                                    Coming soon
                                </span>
                            @endunless
                            <img
                                src="{{ asset('logos/exchanges/'.$exchangeOption->canonical.'.png') }}"
                                alt="{{ $exchangeOption->name }}"
                                @class([
                                    'max-h-12 w-auto object-contain',
                                    'grayscale opacity-40' => ! $exchangeEnabled,
                                ])
                            >
                        </button>
                    @endforeach
                </div>
                @error('exchange')
                    <p class="mt-2 text-xs text-red-700">{{ $message }}</p>
                @enderror
            </section>

            <section>
                <h2 class="text-sm font-semibold text-gray-950">API credentials</h2>
                <div class="mt-3 grid grid-cols-1 gap-4">
                    <div>
                        <label for="api_key" class="mb-1 block text-xs font-medium text-gray-700">API key</label>
                        <input id="api_key" x-model="apiKey" @input="resetConnectivity()" autocomplete="off" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20">
                        @error('api_key')
                            <p class="mt-1 text-xs text-red-700">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="api_secret" class="mb-1 block text-xs font-medium text-gray-700">API secret</label>
                        <input id="api_secret" x-model="apiSecret" @input="resetConnectivity()" autocomplete="off" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20">
                        @error('api_secret')
                            <p class="mt-1 text-xs text-red-700">{{ $message }}</p>
                        @enderror
                    </div>
                    <div x-show="needsPassphrase()" x-cloak>
                        <label for="passphrase" class="mb-1 block text-xs font-medium text-gray-700">Passphrase</label>
                        <input id="passphrase" x-model="passphrase" @input="resetConnectivity()" autocomplete="off" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20">
                        @error('passphrase')
                            <p class="mt-1 text-xs text-red-700">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-4">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="text-xs font-medium leading-5 text-gray-500">
                            @php($hasConnectivityError = $errors->has('connectivity_verified'))
                            @error('connectivity_verified')
                                <span x-show="connectivity === 'idle'" class="text-red-700">{{ $message }}</span>
                            @enderror
                            <span x-show="! hasRequiredApiKeys() && ! @js($hasConnectivityError)">Add credentials before testing connectivity.</span>
                            <span x-show="hasRequiredApiKeys() && connectivity === 'idle' && ! @js($hasConnectivityError)" x-cloak>Ready to test required servers.</span>
                            <span x-show="connectivity === 'testing'" x-cloak class="text-gray-700" x-text="connectivity === 'testing' ? (connectivityMessage || 'Testing connectivity from required servers...') : ''"></span>
                            <span
                                x-show="connectivity === 'okay' || connectivity === 'failed'"
                                x-cloak
                                :class="connectivity === 'okay' ? 'text-emerald-700' : 'text-red-700'"
                                x-text="connectivityMessage"
                            ></span>
                        </div>
                        <button
                            type="button"
                            class="inline-flex h-10 items-center justify-center rounded-lg border border-gray-300 bg-white px-3 text-sm font-medium text-gray-800 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 sm:min-w-36"
                            :disabled="! hasRequiredApiKeys() || connectivity === 'testing'"
                            @click="testConnectivity()"
                        >
                            <span x-show="connectivity !== 'testing'">Test connectivity</span>
                            <span x-show="connectivity === 'testing'" x-cloak>Testing...</span>
                        </button>
                    </div>
                    <div x-show="connectivityServers.length > 0" x-cloak class="mt-4 border-t border-gray-200 pt-3">
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <template x-for="server in connectivityServers" :key="server.id || server.hostname || server.name || server.ip_address || server.ip">
                                <div class="flex items-center justify-between gap-3 rounded-md border border-gray-200 bg-white px-3 py-2 text-xs">
                                    <span class="min-w-0 truncate font-medium text-gray-700" x-text="server.hostname || server.name || server.ip_address || server.ip || 'Server'"></span>
                                    <span
                                        class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                                        :class="{
                                            'bg-emerald-100 text-emerald-800': server.status === 'connected',
                                            'bg-red-100 text-red-800': server.status === 'not_connected',
                                            'bg-gray-100 text-gray-700': server.status !== 'connected' && server.status !== 'not_connected',
                                        }"
                                        x-text="server.status === 'connected' ? 'Connected' : (server.status === 'not_connected' ? 'Failed' : 'Testing')"
                                    ></span>
                                </div>
                            </template>
                        </div>
                    </div>
                    <label x-show="connectivity === 'failed' && connectivityComplete" x-cloak class="mt-4 flex items-start gap-3 border-t border-gray-200 pt-3 text-sm text-gray-700">
                        <input type="checkbox" wire:model.live="continue_without_connectivity" x-model="continueWithoutConnectivity" class="mt-1 rounded border-gray-300 text-emerald-600 focus:ring-emerald-600">
                        <span>You can still create the account but the trading will be disabled until all required servers can connect.</span>
                    </label>
                </div>
            </section>

            <div class="flex flex-col-reverse gap-2 border-t border-gray-200 pt-5 sm:flex-row sm:justify-between">
                <button
                    type="button"
                    wire:click="backToProfile"
                    class="inline-flex h-10 items-center justify-center rounded-lg border border-gray-300 bg-white px-4 text-sm font-semibold text-gray-800 transition hover:bg-gray-50"
                >
                    Back
                </button>
                <button
                    type="button"
                    wire:click="register"
                    wire:loading.attr="disabled"
                    wire:target="register"
                    :disabled="connectivity === 'testing'"
                    class="inline-flex h-10 items-center justify-center rounded-lg bg-emerald-600 px-5 text-sm font-semibold text-white transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    Create account
                </button>
            </div>
        </div>
    @else
        <div class="space-y-7">
            <section class="rounded-lg border border-emerald-200 bg-emerald-50 p-5">
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-emerald-700">Registration complete</p>
                <h2 class="mt-2 text-xl font-semibold tracking-tight text-gray-950">{{ $connectivity_passed ? 'Your bot is active' : 'Your account is ready' }}</h2>
                <p class="mt-2 text-sm leading-6 text-emerald-950">
                    @if ($connectivity_passed)
                        Your Kraite account is ready and your {{ ucfirst($exchange) }} API credentials were accepted. We will keep checking the connection and notify you if something stops working.
                    @else
                        Your Kraite account was created with trading disabled until all required servers can connect to your {{ ucfirst($exchange) }} API.
                    @endif
                </p>
            </section>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div class="rounded-lg border border-gray-200 p-4">
                    <p class="text-xs font-medium text-gray-500">Account</p>
                    <p class="mt-1 text-sm font-semibold text-gray-950">Created</p>
                </div>
                <div class="rounded-lg border border-gray-200 p-4">
                    <p class="text-xs font-medium text-gray-500">API credentials</p>
                    <p class="mt-1 text-sm font-semibold text-gray-950">Verified</p>
                </div>
                <div class="rounded-lg border border-gray-200 p-4">
                    <p class="text-xs font-medium text-gray-500">Trading bot</p>
                    <p class="mt-1 text-sm font-semibold text-gray-950">{{ $connectivity_passed ? 'Active' : 'Disabled' }}</p>
                </div>
            </div>

            <div class="flex justify-end border-t border-gray-200 pt-5">
                <a
                    href="{{ route('dashboard') }}"
                    class="inline-flex h-10 items-center justify-center rounded-lg bg-emerald-600 px-5 text-sm font-semibold text-white transition hover:bg-emerald-700"
                >
                    Go to dashboard
                </a>
            </div>
        </div>
    @endif
</form>
