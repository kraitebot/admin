<x-layouts.guest title="Forgot Password - {{ config('app.name') }}">

    <form method="POST" action="{{ route('password.email') }}" class="space-y-6">
        @csrf

        <p class="text-sm text-zinc-400">
            Enter your email and we'll send you a link to reset your password.
        </p>

        @if(session('status'))
            <div class="rounded-xl border border-krait-500/30 bg-krait-500/10 px-4 py-3 text-sm text-krait-300">
                {{ session('status') }}
            </div>
        @endif

        {{-- Email --}}
        <div>
            <label for="email" class="block text-sm font-medium text-zinc-300 mb-2">Email</label>
            <input
                id="email"
                name="email"
                type="email"
                value="{{ old('email') }}"
                placeholder="admin@kraite.com"
                autocomplete="email"
                required
                autofocus
                class="w-full px-5 py-3.5 rounded-xl bg-zinc-800/50 border border-zinc-700/50 text-neutral-100 placeholder-zinc-500 focus:border-krait-500/50 focus:ring-2 focus:ring-krait-500/20 focus:outline-none transition"
            />
            @error('email')
                <p class="mt-1.5 text-sm text-red-400">{{ $message }}</p>
            @enderror
        </div>

        {{-- Submit --}}
        <button type="submit"
            class="w-full px-5 py-3.5 rounded-xl bg-krait-500 hover:bg-krait-400 text-black font-bold text-sm tracking-wide glow-green-strong transition cursor-pointer">
            Send reset link
        </button>

        <div class="text-center">
            <a href="{{ route('login') }}" class="text-sm text-krait-400 hover:text-krait-300 transition">
                Back to login
            </a>
        </div>
    </form>
</x-layouts.guest>
