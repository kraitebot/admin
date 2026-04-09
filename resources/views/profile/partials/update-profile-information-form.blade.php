<section>
    <p class="text-sm ui-text-subtle mb-4">
        {{ __("Update your account's profile information and email address.") }}
    </p>

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    <form method="post" action="{{ route('profile.update') }}" class="space-y-6">
        @csrf
        @method('patch')

        <x-hub-ui::input name="name" :label="__('Name')" type="text" :value="old('name', $user->name)" required autofocus autocomplete="name" />

        <x-hub-ui::input name="email" :label="__('Email')" type="email" :value="old('email', $user->email)" required autocomplete="username" />

        @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
            <div>
                <p class="text-sm ui-text-muted">
                    {{ __('Your email address is unverified.') }}

                    <button form="send-verification" class="underline text-sm ui-text-subtle hover:ui-text-primary transition">
                        {{ __('Click here to re-send the verification email.') }}
                    </button>
                </p>

                @if (session('status') === 'verification-link-sent')
                    <p class="mt-2 font-medium text-sm ui-text-success">
                        {{ __('A new verification link has been sent to your email address.') }}
                    </p>
                @endif
            </div>
        @endif

        <div class="flex items-center gap-4">
            <x-hub-ui::button type="submit">{{ __('Save') }}</x-hub-ui::button>

            @if (session('status') === 'profile-updated')
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
