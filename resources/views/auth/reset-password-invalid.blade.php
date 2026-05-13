<x-guest-layout>
    <div class="text-center py-2">
        <div class="mx-auto mb-4 w-12 h-12 rounded-full flex items-center justify-center"
             style="background-color: rgb(var(--ui-warning) / 0.12);">
            <svg class="w-6 h-6" style="color: rgb(var(--ui-warning))"
                 fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4M12 16h.01" />
            </svg>
        </div>

        <h1 class="text-lg font-semibold tracking-tight ui-text">Reset link no longer valid</h1>
        <p class="text-sm ui-text-subtle mt-2 max-w-sm mx-auto">
            This password reset link has expired or has already been used. Reset links are valid for 15 minutes and can only be used once.
        </p>

        <div class="mt-6 flex items-center justify-center gap-3">
            <a href="{{ route('password.request') }}">
                <x-hub-ui::button type="button" variant="primary" size="md">
                    {{ __('Request a new link') }}
                </x-hub-ui::button>
            </a>
            <a href="{{ route('login') }}" class="text-xs ui-text-subtle hover:ui-text-primary transition-colors">
                {{ __('Back to sign in') }}
            </a>
        </div>
    </div>
</x-guest-layout>
