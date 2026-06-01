<x-auth-layout title="Kraite — Forgot password">
    <div class="mb-5">
        <div class="font-mono text-[10px] font-medium tracking-[0.12em] uppercase text-fg-3 mb-1.5">Password reset</div>
        <h1 class="font-sans font-bold text-[22px] tracking-[-0.02em] text-ink-9 leading-tight">Forgot password?</h1>
        <p class="text-[13px] text-ink-7 mt-2 leading-[1.5]">Enter your email and we'll send you a reset link. If your email is part of our system, you will receive a reset link shortly.</p>
    </div>

    @if(session('status'))
        <div class="mb-4 py-2.5 px-3 rounded-control bg-pnlup-bg text-pnlup text-[12.5px] font-medium border" style="border-color: color-mix(in srgb, var(--accent) 38%, transparent);">
            {{ session('status') }}
        </div>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-4">
        @csrf
        <label class="flex flex-col gap-1.5">
            <span class="font-mono text-[10px] font-medium tracking-[0.08em] uppercase text-fg-3">Email</span>
            <input type="email" name="email" value="{{ old('email') }}" required autofocus
                   class="h-10 px-3 bg-ink-0 border border-ink-3 rounded-control text-[13px] text-ink-9 font-sans transition-colors duration-fast ease-out focus:border-accent focus:outline-none"/>
            @error('email')<span class="font-mono text-[11px] text-pnldown">{{ $message }}</span>@enderror
        </label>

        <button type="submit" class="appearance-none h-10 px-3 rounded-control bg-accent text-accent-on font-sans font-semibold text-[13px] cursor-pointer transition-colors duration-fast ease-out hover:bg-accent-hover active:translate-y-px">
            Email password reset link
        </button>

        <a href="{{ route('login') }}" class="text-center font-mono text-[11px] text-ink-7 no-underline tracking-[0.04em] hover:text-accent">← Back to sign in</a>
    </form>
</x-auth-layout>
