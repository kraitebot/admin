<section>
    <p class="text-sm ui-text-subtle mb-4">
        {{ __('Ensure your account is using a long, random password to stay secure.') }}
    </p>

    <form method="post" action="{{ route('password.update') }}" class="space-y-6">
        @csrf
        @method('put')

        <x-hub-ui::input id="update_password_current_password" name="current_password" :label="__('Current Password')" type="password" autocomplete="current-password" :error="$errors->updatePassword->first('current_password')" />

        <x-hub-ui::input id="update_password_password" name="password" :label="__('New Password')" type="password" autocomplete="new-password" :error="$errors->updatePassword->first('password')" />

        <x-hub-ui::input id="update_password_password_confirmation" name="password_confirmation" :label="__('Confirm Password')" type="password" autocomplete="new-password" :error="$errors->updatePassword->first('password_confirmation')" />

        <div class="flex items-center gap-4">
            <x-hub-ui::button type="submit">{{ __('Save') }}</x-hub-ui::button>

            @if (session('status') === 'password-updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2000)"
                    class="text-sm ui-text-success"
                >{{ __('Saved.') }}</p>
            @endif
        </div>
    </form>
</section>
