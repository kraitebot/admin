<x-layouts.guest title="Login - {{ config('app.name') }}">
    <script>localStorage.removeItem('sidebar_open');</script>

    <form method="POST" action="{{ route('login') }}" class="space-y-6">
        @csrf

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

        {{-- Password --}}
        <div>
            <label for="password" class="block text-sm font-medium text-zinc-300 mb-2">Password</label>
            <input
                id="password"
                name="password"
                type="password"
                placeholder="Your password"
                autocomplete="current-password"
                required
                class="w-full px-5 py-3.5 rounded-xl bg-zinc-800/50 border border-zinc-700/50 text-neutral-100 placeholder-zinc-500 focus:border-krait-500/50 focus:ring-2 focus:ring-krait-500/20 focus:outline-none transition"
            />
            @error('password')
                <p class="mt-1.5 text-sm text-red-400">{{ $message }}</p>
            @enderror
        </div>

        {{-- Remember / Forgot --}}
        <div class="flex items-center justify-between">
            <label class="flex items-center gap-2 text-sm text-zinc-400 cursor-pointer">
                <input type="checkbox" name="remember" {{ old('remember') ? 'checked' : '' }}
                    class="rounded border-zinc-700 bg-zinc-800 text-krait-500 focus:ring-krait-500 focus:ring-offset-zinc-900">
                Remember me
            </label>

            <a href="{{ route('password.request') }}" class="text-sm text-krait-400 hover:text-krait-300 transition">
                Forgot password?
            </a>
        </div>

        {{-- Submit --}}
        <button type="submit"
            class="w-full px-5 py-3.5 rounded-xl bg-krait-500 hover:bg-krait-400 text-black font-bold text-sm tracking-wide glow-green-strong transition cursor-pointer">
            Sign in
        </button>
    </form>
</x-layouts.guest>
