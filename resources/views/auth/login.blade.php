<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ config('hub-ui.theme.default_mode', 'dark') === 'light' ? 'light' : '' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>Sign in — {{ config('app.name', 'Kraite Admin') }}</title>

    @include('hub-ui::partials.scripts')
    @include('hub-ui::partials.styles')

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>[x-cloak] { display: none !important; }</style>
</head>
<body class="font-sans antialiased" style="background-color: rgb(var(--ui-bg-body)); color: rgb(var(--ui-text))">

    {{-- Subtle ambient glow — anchors the brand without dominating. --}}
    <div aria-hidden="true" class="pointer-events-none fixed inset-0 z-0"
         style="background:
            radial-gradient(60% 50% at 50% 0%, rgb(var(--ui-primary) / 0.08) 0%, transparent 60%),
            radial-gradient(40% 30% at 80% 100%, rgb(var(--ui-primary) / 0.04) 0%, transparent 60%);">
    </div>

    <div class="relative z-10 min-h-screen flex flex-col items-center justify-center px-4 py-10">

        <div class="mb-6 flex flex-col items-center gap-3">
            <img src="{{ asset('logos/snake-green.svg') }}" alt="Kraite" class="w-12 h-12" />
            <span class="text-[0.68rem] font-semibold tracking-[0.18em] uppercase ui-text-subtle">Kraite Admin Console</span>
        </div>

        <div class="sm:relative" x-data="{ devPanelOpen: true }">

            {{-- Login card --}}
            <div class="ui-card w-full sm:max-w-md mx-auto p-8 sm:p-10 relative z-10"
                 style="box-shadow: 0 1px 0 0 rgb(var(--ui-border) / 0.4) inset, 0 24px 48px rgba(0,0,0,0.35);">

                <div class="mb-7">
                    <h1 class="text-xl font-semibold tracking-tight ui-text">Welcome back</h1>
                    <p class="text-sm ui-text-subtle mt-1">Sign in to continue.</p>
                </div>

                @if (session('status'))
                    <x-hub-ui::alert type="success" class="mb-4">{{ session('status') }}</x-hub-ui::alert>
                @endif

                @if ($errors->any())
                    <x-hub-ui::alert type="error" class="mb-4">{{ $errors->first() }}</x-hub-ui::alert>
                @endif

                <form method="POST" action="{{ route('login') }}" class="space-y-4">
                    @csrf

                    <x-hub-ui::input
                        name="email"
                        :label="__('Email')"
                        type="email"
                        :value="old('email')"
                        required
                        autofocus
                        autocomplete="username"
                        placeholder="you@kraite.com"
                    />

                    <x-hub-ui::input
                        name="password"
                        :label="__('Password')"
                        type="password"
                        required
                        autocomplete="current-password"
                        placeholder="••••••••"
                    />

                    <div class="flex items-center justify-between pt-1">
                        <x-hub-ui::checkbox name="remember" :label="__('Remember me')" />

                        @if (Route::has('password.request'))
                            <a class="text-xs ui-text-subtle hover:ui-text-primary transition-colors" href="{{ route('password.request') }}">
                                {{ __('Forgot password?') }}
                            </a>
                        @endif
                    </div>

                    <x-hub-ui::button type="submit" variant="primary" size="md" class="w-full mt-2">
                        {{ __('Sign in') }}
                    </x-hub-ui::button>
                </form>
            </div>

            {{-- Quick-login slide panel — every seeded user with their account
                 membership. Click → autofill email + password (shared 'password').
                 Mobile: stacks below. Desktop: pinned to right edge of card. --}}
            @if($devUsers->isNotEmpty())
                <aside class="ui-card overflow-hidden flex flex-col mt-4 w-full
                              sm:mt-0 sm:absolute sm:top-0 sm:left-full sm:ml-4 sm:w-[320px] sm:h-full
                              sm:z-0 sm:transition-transform sm:duration-[450ms] sm:will-change-transform"
                       style="transition-timing-function: cubic-bezier(0.22, 1, 0.36, 1);"
                       :class="devPanelOpen ? 'sm:translate-x-0' : 'sm:-translate-x-[340px]'">
                    <div class="px-4 py-3 ui-card-header shrink-0">
                        <div class="text-[0.68rem] font-semibold ui-text-muted tracking-[0.12em] uppercase">
                            Quick sign-in
                        </div>
                        <div class="text-[0.68rem] ui-text-subtle mt-1">
                            Click a user · password
                            <span class="px-1.5 py-0.5 rounded ui-bg-elevated font-mono ui-text-muted ml-0.5" style="font-family: 'JetBrains Mono', ui-monospace, monospace;">password</span>
                        </div>
                    </div>
                    <div class="flex-1 min-h-0 overflow-y-auto ui-scrollbar">
                        @foreach($devUsers as $user)
                            <button type="button"
                                    @click="
                                        document.getElementById('email').value = @js($user['email']);
                                        document.getElementById('password').value = 'password';
                                    "
                                    class="w-full text-left px-4 py-3 transition-colors duration-100 cursor-pointer border-b ui-border hover:bg-[rgb(var(--ui-primary)/0.06)] last:border-b-0 group">
                                <div class="flex items-center gap-2 mb-1">
                                    <div class="text-[0.78rem] font-medium ui-text truncate"
                                         style="font-family: 'JetBrains Mono', ui-monospace, monospace;">{{ $user['email'] }}</div>
                                    @if($user['is_admin'])
                                        <span class="text-[0.58rem] uppercase font-semibold tracking-[0.1em] px-1.5 py-0.5 rounded shrink-0"
                                              style="background-color: rgb(var(--ui-primary) / 0.14); color: rgb(var(--ui-primary))">admin</span>
                                    @endif
                                </div>
                                <div class="text-[0.68rem] ui-text-subtle truncate">{{ $user['subtitle'] }}</div>
                            </button>
                        @endforeach
                    </div>
                </aside>

                {{-- Toggle handle pinned to the card's right edge. --}}
                <button type="button"
                        @click="devPanelOpen = !devPanelOpen"
                        :aria-label="devPanelOpen ? 'Collapse quick sign-in' : 'Expand quick sign-in'"
                        class="hidden sm:flex absolute top-1/2 left-full -translate-y-1/2 -translate-x-1/2 z-30 w-7 h-14 items-center justify-center ui-card !rounded-[10px] hover:bg-[rgb(var(--ui-bg-elevated))] transition-all duration-150 cursor-pointer">
                    <svg :class="devPanelOpen ? 'rotate-0' : 'rotate-180'"
                         class="w-3.5 h-3.5 ui-text-muted transition-transform duration-300 ease-out"
                         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="15 18 9 12 15 6"></polyline>
                    </svg>
                </button>
            @endif

        </div>

        <p class="mt-8 text-[0.68rem] ui-text-subtle tracking-[0.06em]">
            &copy; {{ date('Y') }} Kraite — internal admin
        </p>
    </div>
</body>
</html>
