<x-guest-layout>
    <p class="mb-4 text-sm ui-text-muted">
        {{ __('Forgot your password? No problem. Just let us know your email address and we will email you a password reset link that will allow you to choose a new one.') }}
    </p>

    @if (session('status'))
        <x-hub-ui::alert type="success" class="mb-4">{{ session('status') }}</x-hub-ui::alert>
    @endif

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <x-hub-ui::input name="email" :label="__('Email')" type="email" :value="old('email')" required autofocus />

        <div class="flex items-center justify-end mt-6">
            <x-hub-ui::button type="submit">
                {{ __('Email Password Reset Link') }}
            </x-hub-ui::button>
        </div>
    </form>
</x-guest-layout>
