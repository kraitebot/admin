<x-guest-layout>
    <form method="POST" action="{{ route('register') }}">
        @csrf

        <x-hub-ui::input name="name" :label="__('Name')" type="text" :value="old('name')" required autofocus autocomplete="name" />

        <x-hub-ui::input name="email" :label="__('Email')" type="email" :value="old('email')" required autocomplete="username" class="mt-4" />

        <x-hub-ui::input name="password" :label="__('Password')" type="password" required autocomplete="new-password" class="mt-4" />

        <x-hub-ui::input name="password_confirmation" :label="__('Confirm Password')" type="password" required autocomplete="new-password" class="mt-4" />

        <div class="flex items-center justify-end mt-6">
            <a class="text-sm ui-text-muted hover:ui-text-primary transition" href="{{ route('login') }}">
                {{ __('Already registered?') }}
            </a>

            <x-hub-ui::button type="submit" class="ms-4">
                {{ __('Register') }}
            </x-hub-ui::button>
        </div>
    </form>
</x-guest-layout>
