<x-guest-layout>
    <div class="mb-5">
        <h1 class="text-lg font-semibold tracking-tight ui-text">Reset your password</h1>
        <p class="text-sm ui-text-subtle mt-1">
            Enter your account email and we'll send you a link to set a new password.
        </p>
    </div>

    @if (session('status'))
        <x-hub-ui::alert type="success" class="mb-4">{{ session('status') }}</x-hub-ui::alert>
    @endif

    @if ($errors->any())
        <x-hub-ui::alert type="error" class="mb-4">{{ $errors->first() }}</x-hub-ui::alert>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
        @csrf

        <x-hub-ui::input
            name="email"
            :label="__('Email')"
            type="email"
            :value="old('email')"
            required
            autofocus
            autocomplete="username"
            placeholder="you@kraite.com"
        />

        <div class="flex items-center justify-between pt-1">
            <a href="{{ route('login') }}" class="text-xs ui-text-subtle hover:ui-text-primary transition-colors">
                {{ __('Back to sign in') }}
            </a>

            <x-hub-ui::button type="submit" variant="primary" size="md">
                {{ __('Send reset link') }}
            </x-hub-ui::button>
        </div>
    </form>
</x-guest-layout>
