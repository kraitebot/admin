@props(['request', 'requiresName' => false])
<x-auth-layout title="Kraite — Reset password">
    <div class="mb-5">
        <div class="font-mono text-[10px] font-medium tracking-[0.12em] uppercase text-fg-3 mb-1.5">Password reset</div>
        <h1 class="font-sans font-bold text-[22px] tracking-[-0.02em] text-ink-9 leading-tight">Set a new password</h1>
        @if($requiresName)
            <p class="text-[13px] text-ink-7 mt-2 leading-[1.5]">We don't have your name on file — please enter it below so we can address you properly.</p>
        @endif
    </div>

    <form method="POST" action="{{ route('password.store') }}" class="flex flex-col gap-4">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}"/>

        <label class="flex flex-col gap-1.5">
            <span class="font-mono text-[10px] font-medium tracking-[0.08em] uppercase text-fg-3">Email</span>
            <input type="email" name="email" value="{{ old('email', $request->email) }}" required readonly
                   class="h-10 px-3 bg-ink-0 border border-ink-3 rounded-control text-[13px] text-ink-9 font-sans opacity-70"/>
            @error('email')<span class="font-mono text-[11px] text-pnldown">{{ $message }}</span>@enderror
        </label>

        @if($requiresName)
            <label class="flex flex-col gap-1.5">
                <span class="font-mono text-[10px] font-medium tracking-[0.08em] uppercase text-fg-3">Full name</span>
                <input type="text" name="name" value="{{ old('name') }}" required autofocus
                       class="h-10 px-3 bg-ink-0 border border-ink-3 rounded-control text-[13px] text-ink-9 font-sans transition-colors duration-fast ease-out focus:border-accent focus:outline-none"/>
                @error('name')<span class="font-mono text-[11px] text-pnldown">{{ $message }}</span>@enderror
            </label>
        @endif

        <label class="flex flex-col gap-1.5">
            <span class="font-mono text-[10px] font-medium tracking-[0.08em] uppercase text-fg-3">New password</span>
            <input type="password" name="password" required autocomplete="new-password" @if(!$requiresName) autofocus @endif
                   class="h-10 px-3 bg-ink-0 border border-ink-3 rounded-control text-[13px] text-ink-9 font-sans transition-colors duration-fast ease-out focus:border-accent focus:outline-none"/>
            @error('password')<span class="font-mono text-[11px] text-pnldown">{{ $message }}</span>@enderror
        </label>

        <label class="flex flex-col gap-1.5">
            <span class="font-mono text-[10px] font-medium tracking-[0.08em] uppercase text-fg-3">Confirm new password</span>
            <input type="password" name="password_confirmation" required autocomplete="new-password"
                   class="h-10 px-3 bg-ink-0 border border-ink-3 rounded-control text-[13px] text-ink-9 font-sans transition-colors duration-fast ease-out focus:border-accent focus:outline-none"/>
        </label>

        <button type="submit" class="appearance-none h-10 px-3 rounded-control bg-accent text-accent-on font-sans font-semibold text-[13px] cursor-pointer transition-colors duration-fast ease-out hover:bg-accent-hover active:translate-y-px">
            Reset password
        </button>
    </form>
</x-auth-layout>
