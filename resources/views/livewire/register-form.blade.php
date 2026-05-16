<form
    wire:submit="register"
    novalidate
    class="w-full max-w-3xl rounded-xl border border-white/10 bg-white p-5 text-gray-900 shadow-2xl sm:p-8"
    x-data="{
        password: @entangle('password'),
        exchange: @entangle('exchange').live,
        apiKey: @entangle('api_key'),
        apiSecret: @entangle('api_secret'),
        passphrase: @entangle('passphrase'),
        passwordStrengthThreshold: @js(\App\Support\Registration\PasswordStrength::THRESHOLD),
        connectivity: 'idle',
        connectivityMessage: '',
        apiKeysModalOpen: @js($errors->has('api_key') || $errors->has('api_secret') || $errors->has('passphrase')),
        connectivityUrl: @js(route('register.connectivity', $user->uuid)),
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
            this.connectivity = 'idle';
            this.connectivityMessage = '';
        },
        async testConnectivity() {
            if (! this.hasRequiredApiKeys() || this.connectivity === 'testing') {
                return;
            }

            this.connectivity = 'testing';
            this.connectivityMessage = 'Testing connectivity...';

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

                this.connectivity = response.ok && data.connected ? 'okay' : 'failed';
                this.connectivityMessage = data.message || (this.connectivity === 'okay' ? 'Connectivity verified, all good!' : 'Connectivity failed.');
            } catch (error) {
                this.connectivity = 'failed';
                this.connectivityMessage = 'Connectivity failed. Try again in a moment.';
            }
        },
    }"
    x-on:keydown.escape.window="if (connectivity !== 'testing') apiKeysModalOpen = false"
>
    @if ($errors->has('api_key') || $errors->has('api_secret') || $errors->has('passphrase'))
        <span x-init="apiKeysModalOpen = true"></span>
    @endif

    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-emerald-700">Private beta</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-gray-950">Welcome to Kraite!</h1>
            <p class="mt-1 text-sm text-gray-600">{{ $user->email }}</p>
        </div>
        <img src="{{ asset('logos/snake-green.svg') }}" alt="Kraite" class="h-12 w-12">
    </div>

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
            <h2 class="text-sm font-semibold text-gray-950">Trading exchange</h2>

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

            <div class="mt-4 flex flex-col gap-3 border-t border-gray-100 pt-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-3">
                    <span class="flex h-8 w-8 items-center justify-center rounded-full border" :class="hasRequiredApiKeys() ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-gray-200 bg-gray-50 text-gray-400'">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 7.5a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 20.25a8.25 8.25 0 0 1 15 0" />
                        </svg>
                    </span>
                    <div>
                        <p class="text-sm font-semibold text-gray-950">API keys</p>
                        <p class="text-xs text-gray-500" x-text="connectivity === 'okay' ? 'Connection verified' : (hasRequiredApiKeys() ? 'Ready to test' : (needsPassphrase() ? 'Key, secret and passphrase' : 'Key and secret'))"></p>
                    </div>
                </div>
                <button
                    type="button"
                    class="inline-flex h-10 items-center justify-center rounded-lg border border-gray-950 bg-gray-950 px-4 text-sm font-semibold text-white transition hover:bg-gray-800 sm:w-auto"
                    @click="apiKeysModalOpen = true"
                >
                    Add API Keys
                </button>
            </div>

            <div
                x-show="apiKeysModalOpen"
                x-cloak
                class="fixed inset-0 z-50 flex items-center justify-center p-4"
                aria-labelledby="api-keys-modal-title"
                role="dialog"
                aria-modal="true"
                data-api-keys-modal
                x-on:keydown.enter.prevent
            >
                <div class="absolute inset-0 bg-gray-950/65 backdrop-blur-sm" @click="if (connectivity !== 'testing') apiKeysModalOpen = false"></div>

                <div class="relative w-full max-w-xl overflow-hidden rounded-xl border border-white/10 bg-white text-gray-900 shadow-2xl">
                    <div class="flex items-start justify-between gap-4 border-b border-gray-100 px-5 py-4">
                        <div>
                            <h3 id="api-keys-modal-title" class="text-base font-semibold text-gray-950">API keys</h3>
                            <p class="mt-1 text-xs font-medium uppercase tracking-[0.16em] text-emerald-700" x-text="exchange"></p>
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 items-center justify-center rounded-full text-gray-400 transition hover:bg-gray-100 hover:text-gray-950 disabled:cursor-not-allowed disabled:opacity-40"
                            :disabled="connectivity === 'testing'"
                            @click="apiKeysModalOpen = false"
                            aria-label="Close API keys modal"
                        >
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.25">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="grid grid-cols-1 gap-4 px-5 py-5">
                        <div>
                            <label for="api_key" class="mb-1 block text-xs font-medium text-gray-700">API key</label>
                            <input id="api_key" x-model="apiKey" @input="resetConnectivity()" :disabled="connectivity === 'testing'" autocomplete="off" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20 disabled:cursor-not-allowed disabled:bg-gray-100">
                            @error('api_key')
                                <p class="mt-1 text-xs text-red-700">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="api_secret" class="mb-1 block text-xs font-medium text-gray-700">API secret</label>
                            <input id="api_secret" x-model="apiSecret" @input="resetConnectivity()" :disabled="connectivity === 'testing'" autocomplete="off" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20 disabled:cursor-not-allowed disabled:bg-gray-100">
                            @error('api_secret')
                                <p class="mt-1 text-xs text-red-700">{{ $message }}</p>
                            @enderror
                        </div>
                        <div x-show="needsPassphrase()" x-cloak>
                            <label for="passphrase" class="mb-1 block text-xs font-medium text-gray-700">Passphrase</label>
                            <input id="passphrase" x-model="passphrase" @input="resetConnectivity()" :disabled="connectivity === 'testing'" autocomplete="off" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20 disabled:cursor-not-allowed disabled:bg-gray-100">
                            @error('passphrase')
                                <p class="mt-1 text-xs text-red-700">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="flex flex-col gap-4 border-t border-gray-100 bg-gray-50 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-h-6 text-xs font-medium leading-5 text-gray-500 sm:max-w-[16rem]">
                            <span x-show="! hasRequiredApiKeys()">Add credentials first</span>
                            <span x-show="hasRequiredApiKeys() && connectivity === 'idle'" x-cloak>Ready to test this server.</span>
                            <span x-show="connectivity === 'testing'" x-cloak class="text-gray-700">Testing connectivity...</span>
                            <span x-show="connectivity === 'okay'" x-cloak class="text-emerald-700" x-text="connectivityMessage"></span>
                            <span x-show="connectivity === 'failed'" x-cloak class="text-red-700" x-text="connectivityMessage"></span>
                        </div>
                        <div class="grid grid-cols-1 gap-2 sm:flex sm:shrink-0 sm:justify-end">
                            <button
                                type="button"
                                class="inline-flex h-10 items-center justify-center rounded-lg border border-gray-300 bg-white px-3 text-sm font-medium text-gray-800 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 sm:min-w-36"
                                :disabled="! hasRequiredApiKeys() || connectivity === 'testing'"
                                @click="testConnectivity()"
                            >
                                <span x-show="connectivity !== 'testing'">Test connectivity</span>
                                <span x-show="connectivity === 'testing'" x-cloak>Testing...</span>
                            </button>
                            <button
                                type="button"
                                class="inline-flex h-10 items-center justify-center rounded-lg bg-emerald-600 px-4 text-sm font-semibold text-white transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50 sm:min-w-20"
                                :disabled="connectivity === 'testing'"
                                @click="apiKeysModalOpen = false"
                            >
                                Done
                            </button>
                        </div>
                    </div>
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

        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-900">
            Since you registered for private beta, any top-up will add 25% FREE to your account balance, forever.
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
                type="submit"
                wire:loading.attr="disabled"
                wire:target="register"
                class="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
            >
                Next
            </button>
        </div>
    </div>
</form>
