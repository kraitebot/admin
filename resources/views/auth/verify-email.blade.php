<x-guest-layout>
    <p class="mb-4 text-sm ui-text-muted">
        {{ __('Thanks for signing up! Before getting started, could you verify your email address by clicking on the link we just emailed to you? If you didn\'t receive the email, we will gladly send you another.') }}
    </p>

    @if (session('status') == 'verification-link-sent')
        <x-hub-ui::alert type="success" class="mb-4">
            {{ __('A new verification link has been sent to the email address you provided during registration.') }}
        </x-hub-ui::alert>
    @endif

    <div class="mt-4 flex items-center justify-between">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <x-hub-ui::button type="submit">
                {{ __('Resend Verification Email') }}
            </x-hub-ui::button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <x-hub-ui::button type="submit" variant="ghost">
                {{ __('Log Out') }}
            </x-hub-ui::button>
        </form>
    </div>
</x-guest-layout>
