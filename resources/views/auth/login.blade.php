@props(['devUsers' => collect()])
<x-auth-layout title="Kraite — Sign in">
    <div class="mb-5">
        <div class="font-mono text-[10px] font-medium tracking-[0.12em] uppercase text-fg-3 mb-1.5">Operator console</div>
        <h1 class="font-sans font-bold text-[22px] tracking-[-0.02em] text-ink-9 leading-tight">Sign in</h1>
    </div>

    @if(session('status'))
        <div class="mb-4 py-2.5 px-3 rounded-control bg-pnlup-bg text-pnlup text-[12.5px] font-medium border" style="border-color: color-mix(in srgb, var(--accent) 38%, transparent);">
            {{ session('status') }}
        </div>
    @endif

    <form method="POST" action="{{ route('login') }}" class="flex flex-col gap-4">
        @csrf

        <label class="flex flex-col gap-1.5">
            <span class="font-mono text-[10px] font-medium tracking-[0.08em] uppercase text-fg-3">Email</span>
            <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                   class="h-10 px-3 bg-ink-0 border border-ink-3 rounded-control text-[13px] text-ink-9 font-sans transition-colors duration-fast ease-out focus:border-accent focus:outline-none"/>
            @error('email')<span class="font-mono text-[11px] text-pnldown">{{ $message }}</span>@enderror
        </label>

        <label class="flex flex-col gap-1.5">
            <div class="flex items-center justify-between">
                <span class="font-mono text-[10px] font-medium tracking-[0.08em] uppercase text-fg-3">Password</span>
                <a href="{{ route('password.request') }}" class="font-mono text-[10px] tracking-[0.04em] text-ink-7 no-underline hover:text-accent">Forgot password?</a>
            </div>
            <input type="password" name="password" required autocomplete="current-password"
                   class="h-10 px-3 bg-ink-0 border border-ink-3 rounded-control text-[13px] text-ink-9 font-sans transition-colors duration-fast ease-out focus:border-accent focus:outline-none"/>
            @error('password')<span class="font-mono text-[11px] text-pnldown">{{ $message }}</span>@enderror
        </label>

        <label class="flex items-center gap-2 text-[12.5px] text-ink-7">
            <input type="checkbox" name="remember" class="w-3.5 h-3.5 accent-accent"/>
            Remember me on this machine
        </label>

        <button type="submit" class="appearance-none h-10 mt-1 px-3 rounded-control bg-accent text-accent-on font-sans font-semibold text-[13px] cursor-pointer transition-colors duration-fast ease-out hover:bg-accent-hover active:translate-y-px">
            Sign in
        </button>
    </form>

    @if($devUsers->isNotEmpty())
        <div class="mt-7 pt-5 border-t border-ink-3">
            <div class="font-mono text-[10px] font-medium tracking-[0.12em] uppercase text-fg-3 mb-2.5">Dev quick-pick (local only)</div>
            <div class="flex flex-col gap-1.5">
                @foreach($devUsers as $u)
                    <button type="button"
                            onclick="document.querySelector('input[name=email]').value = '{{ $u['email'] }}'; document.querySelector('input[name=password]').value = 'password';"
                            class="appearance-none text-left py-2 px-2.5 bg-ink-0 border border-ink-3 rounded-control cursor-pointer transition-colors duration-fast ease-out hover:border-line-strong hover:bg-ink-2">
                        <div class="flex items-baseline gap-2">
                            <span class="font-sans font-semibold text-[12.5px] text-ink-9">{{ $u['name'] ?: $u['email'] }}</span>
                            @if($u['is_admin'])<span class="font-mono text-[9px] tracking-[0.08em] uppercase text-accent">ADMIN</span>@endif
                        </div>
                        <div class="font-mono text-[10.5px] text-ink-6">{{ $u['subtitle'] }} · {{ $u['email'] }}</div>
                    </button>
                @endforeach
            </div>
        </div>
    @endif
</x-auth-layout>
