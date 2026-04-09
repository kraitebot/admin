<x-guest-layout>
    @if (session('status'))
        <x-hub-ui::alert type="success" class="mb-4">{{ session('status') }}</x-hub-ui::alert>
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <x-hub-ui::input name="email" :label="__('Email')" type="email" :value="old('email')" required autofocus autocomplete="username" />

        <x-hub-ui::input name="password" :label="__('Password')" type="password" required autocomplete="current-password" class="mt-4" />

        <div class="mt-4">
            <x-hub-ui::checkbox name="remember" :label="__('Remember me')" />
        </div>

        <div class="flex items-center justify-end mt-6">
            @if (Route::has('password.request'))
                <a class="text-sm ui-text-muted hover:ui-text-primary transition" href="{{ route('password.request') }}">
                    {{ __('Forgot your password?') }}
                </a>
            @endif

            <x-hub-ui::button type="submit" class="ms-3">
                {{ __('Log in') }}
            </x-hub-ui::button>
        </div>
    </form>
</x-guest-layout>
