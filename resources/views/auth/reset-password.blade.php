<x-guest-layout>
    <form method="POST" action="{{ route('password.store') }}">
        @csrf

        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <x-hub-ui::input name="email" :label="__('Email')" type="email" :value="old('email', $request->email)" required autofocus autocomplete="username" />

        <x-hub-ui::input name="password" :label="__('Password')" type="password" required autocomplete="new-password" class="mt-4" />

        <x-hub-ui::input name="password_confirmation" :label="__('Confirm Password')" type="password" required autocomplete="new-password" class="mt-4" />

        <div class="flex items-center justify-end mt-6">
            <x-hub-ui::button type="submit">
                {{ __('Reset Password') }}
            </x-hub-ui::button>
        </div>
    </form>
</x-guest-layout>
