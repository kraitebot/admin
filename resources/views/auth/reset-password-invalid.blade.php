<x-auth-layout title="Kraite — Reset link expired">
    <div class="mb-5">
        <div class="font-mono text-[10px] font-medium tracking-[0.12em] uppercase text-fg-3 mb-1.5">Password reset</div>
        <h1 class="font-sans font-bold text-[22px] tracking-[-0.02em] text-ink-9 leading-tight">Reset link no longer valid</h1>
        <p class="text-[13px] text-ink-7 mt-2 leading-[1.5]">This password reset link has expired or has already been used. Request a new one — links expire 15 minutes after they're issued and only work once.</p>
    </div>

    <a href="{{ route('password.request') }}" class="appearance-none inline-flex items-center justify-center h-10 px-3 rounded-control bg-accent text-accent-on font-sans font-semibold text-[13px] no-underline transition-colors duration-fast ease-out hover:bg-accent-hover">
        Request a new link
    </a>

    <a href="{{ route('login') }}" class="block text-center mt-4 font-mono text-[11px] text-ink-7 no-underline tracking-[0.04em] hover:text-accent">← Back to sign in</a>
</x-auth-layout>
