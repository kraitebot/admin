<x-auth-layout title="Kraite — Verify email">
    <div class="mb-5">
        <div class="font-mono text-[10px] font-medium tracking-[0.12em] uppercase text-fg-3 mb-1.5">Verification</div>
        <h1 class="font-sans font-bold text-[22px] tracking-[-0.02em] text-ink-9 leading-tight">Verify your email</h1>
        <p class="text-[13px] text-ink-7 mt-2 leading-[1.5]">Thanks for signing up. Before getting started, please verify your email by clicking the link we just sent you. If you didn't receive it, we can send another.</p>
    </div>

    @if(session('status') === 'verification-link-sent')
        <div class="mb-4 py-2.5 px-3 rounded-control bg-pnlup-bg text-pnlup text-[12.5px] font-medium border" style="border-color: color-mix(in srgb, var(--accent) 38%, transparent);">
            A new verification link has been sent to your email.
        </div>
    @endif

    <div class="flex flex-col gap-3">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button type="submit" class="w-full appearance-none h-10 px-3 rounded-control bg-accent text-accent-on font-sans font-semibold text-[13px] cursor-pointer transition-colors duration-fast ease-out hover:bg-accent-hover active:translate-y-px">
                Resend verification email
            </button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="w-full appearance-none h-10 px-3 rounded-control bg-transparent border border-line-strong text-fg-1 font-sans font-semibold text-[13px] cursor-pointer transition-colors duration-fast ease-out hover:bg-hover">
                Log out
            </button>
        </form>
    </div>
</x-auth-layout>
