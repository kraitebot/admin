<x-layouts.guest title="Reset Password - {{ config('app.name') }}">

    <form method="POST" action="{{ route('password.update') }}" class="space-y-6">
        @csrf

        <input type="hidden" name="token" value="{{ $token }}">

        {{-- Email --}}
        <div>
            <label for="email" class="block text-sm font-medium text-zinc-300 mb-2">Email</label>
            <input
                id="email"
                name="email"
                type="email"
                value="{{ old('email', $email) }}"
                autocomplete="email"
                required
                autofocus
                class="w-full px-5 py-3.5 rounded-xl bg-zinc-800/50 border border-zinc-700/50 text-neutral-100 placeholder-zinc-500 focus:border-krait-500/50 focus:ring-2 focus:ring-krait-500/20 focus:outline-none transition"
            />
            @error('email')
                <p class="mt-1.5 text-sm text-red-400">{{ $message }}</p>
            @enderror
        </div>

        {{-- New password --}}
        <div>
            <label for="password" class="block text-sm font-medium text-zinc-300 mb-2">New password</label>
            <input
                id="password"
                name="password"
                type="password"
                placeholder="Minimum 8 characters"
                autocomplete="new-password"
                required
                class="w-full px-5 py-3.5 rounded-xl bg-zinc-800/50 border border-zinc-700/50 text-neutral-100 placeholder-zinc-500 focus:border-krait-500/50 focus:ring-2 focus:ring-krait-500/20 focus:outline-none transition"
            />
            @error('password')
                <p class="mt-1.5 text-sm text-red-400">{{ $message }}</p>
            @enderror
        </div>

        {{-- Confirm password --}}
        <div>
            <label for="password_confirmation" class="block text-sm font-medium text-zinc-300 mb-2">Confirm password</label>
            <input
                id="password_confirmation"
                name="password_confirmation"
                type="password"
                placeholder="Repeat your new password"
                autocomplete="new-password"
                required
                class="w-full px-5 py-3.5 rounded-xl bg-zinc-800/50 border border-zinc-700/50 text-neutral-100 placeholder-zinc-500 focus:border-krait-500/50 focus:ring-2 focus:ring-krait-500/20 focus:outline-none transition"
            />
            @error('password_confirmation')
                <p class="mt-1.5 text-sm text-red-400">{{ $message }}</p>
            @enderror
        </div>

        {{-- Submit --}}
        <button type="submit"
            class="w-full px-5 py-3.5 rounded-xl bg-krait-500 hover:bg-krait-400 text-black font-bold text-sm tracking-wide glow-green-strong transition cursor-pointer">
            Reset password
        </button>

        <div class="text-center">
            <a href="{{ route('login') }}" class="text-sm text-krait-400 hover:text-krait-300 transition">
                Back to login
            </a>
        </div>
    </form>
</x-layouts.guest>
