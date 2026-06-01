<x-auth-layout title="Kraite — Confirm password">
    <div class="mb-5">
        <div class="font-mono text-[10px] font-medium tracking-[0.12em] uppercase text-fg-3 mb-1.5">Security check</div>
        <h1 class="font-sans font-bold text-[22px] tracking-[-0.02em] text-ink-9 leading-tight">Confirm your password</h1>
        <p class="text-[13px] text-ink-7 mt-2 leading-[1.5]">This is a secure area of the application. Please confirm your password before continuing.</p>
    </div>

    <form method="POST" action="{{ route('password.confirm') }}" class="flex flex-col gap-4">
        @csrf
        <label class="flex flex-col gap-1.5">
            <span class="font-mono text-[10px] font-medium tracking-[0.08em] uppercase text-fg-3">Password</span>
            <input type="password" name="password" required autofocus autocomplete="current-password"
                   class="h-10 px-3 bg-ink-0 border border-ink-3 rounded-control text-[13px] text-ink-9 font-sans transition-colors duration-fast ease-out focus:border-accent focus:outline-none"/>
            @error('password')<span class="font-mono text-[11px] text-pnldown">{{ $message }}</span>@enderror
        </label>

        <button type="submit" class="appearance-none h-10 px-3 rounded-control bg-accent text-accent-on font-sans font-semibold text-[13px] cursor-pointer transition-colors duration-fast ease-out hover:bg-accent-hover active:translate-y-px">
            Confirm
        </button>
    </form>
</x-auth-layout>
