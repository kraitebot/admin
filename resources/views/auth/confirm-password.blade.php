<x-guest-layout>
    <p class="mb-4 text-sm ui-text-muted">
        {{ __('This is a secure area of the application. Please confirm your password before continuing.') }}
    </p>

    <form method="POST" action="{{ route('password.confirm') }}">
        @csrf

        <x-hub-ui::input name="password" :label="__('Password')" type="password" required autocomplete="current-password" />

        <div class="flex justify-end mt-6">
            <x-hub-ui::button type="submit">
                {{ __('Confirm') }}
            </x-hub-ui::button>
        </div>
    </form>
</x-guest-layout>
