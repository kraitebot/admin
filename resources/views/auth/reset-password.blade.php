<x-guest-layout>
    <div class="mb-5">
        <h1 class="text-lg font-semibold tracking-tight ui-text">Set a new password</h1>
        <p class="text-sm ui-text-subtle mt-1">Choose a strong password for your Kraite account.</p>
    </div>

    @if ($errors->any())
        <x-hub-ui::alert type="error" class="mb-4">{{ $errors->first() }}</x-hub-ui::alert>
    @endif

    <form
        method="POST"
        action="{{ route('password.store') }}"
        x-data="{
            pwd: '',
            get hasMin()    { return this.pwd.length >= 8 },
            get hasUpper()  { return /[A-Z]/.test(this.pwd) },
            get hasLower()  { return /[a-z]/.test(this.pwd) },
            get hasNumber() { return /\d/.test(this.pwd) },
            get score()     { return [this.hasMin, this.hasUpper, this.hasLower, this.hasNumber].filter(Boolean).length },
            get label()     {
                return ['Too short', 'Weak', 'Fair', 'Good', 'Strong'][this.score];
            },
            get barColor()  {
                return ['var(--ui-danger)', 'var(--ui-danger)', 'var(--ui-warning)', 'var(--ui-warning)', 'var(--ui-success)'][this.score];
            },
        }"
        class="space-y-4"
    >
        @csrf

        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <x-hub-ui::input
            name="email"
            :label="__('Email')"
            type="email"
            :value="old('email', $request->email)"
            required
            readonly
            autocomplete="username"
        />

        @if($needsName)
            <x-hub-ui::input
                name="name"
                :label="__('Full name')"
                type="text"
                :value="old('name')"
                required
                autocomplete="name"
                placeholder="e.g. Alex Morgan"
                hint="We don't have your name on file yet — please add it now."
            />
        @endif

        <div>
            <x-hub-ui::input
                name="password"
                :label="__('New password')"
                type="password"
                required
                autocomplete="new-password"
                placeholder="••••••••"
                x-model="pwd"
            />

            <div class="mt-2 space-y-2">
                <div class="flex items-center gap-1.5">
                    <template x-for="i in 4" :key="i">
                        <div
                            class="h-1 flex-1 rounded-full transition-all duration-200"
                            :style="`background-color: ${i <= score ? `rgb(${barColor})` : 'rgb(var(--ui-border))'}`"
                        ></div>
                    </template>
                </div>

                <div class="flex items-center justify-between text-[11px]">
                    <span class="ui-text-subtle">Password strength</span>
                    <span x-text="label" :style="`color: rgb(${barColor})`" class="font-medium"></span>
                </div>

                <ul class="text-[11px] space-y-1 mt-2">
                    <li class="flex items-center gap-1.5"
                        :class="hasMin ? 'ui-text-success' : 'ui-text-subtle'">
                        <span x-text="hasMin ? '✓' : '○'" class="font-mono w-3 inline-block text-center"></span>
                        At least 8 characters
                    </li>
                    <li class="flex items-center gap-1.5"
                        :class="hasUpper ? 'ui-text-success' : 'ui-text-subtle'">
                        <span x-text="hasUpper ? '✓' : '○'" class="font-mono w-3 inline-block text-center"></span>
                        One uppercase letter
                    </li>
                    <li class="flex items-center gap-1.5"
                        :class="hasLower ? 'ui-text-success' : 'ui-text-subtle'">
                        <span x-text="hasLower ? '✓' : '○'" class="font-mono w-3 inline-block text-center"></span>
                        One lowercase letter
                    </li>
                    <li class="flex items-center gap-1.5"
                        :class="hasNumber ? 'ui-text-success' : 'ui-text-subtle'">
                        <span x-text="hasNumber ? '✓' : '○'" class="font-mono w-3 inline-block text-center"></span>
                        One number
                    </li>
                </ul>
            </div>
        </div>

        <x-hub-ui::input
            name="password_confirmation"
            :label="__('Confirm new password')"
            type="password"
            required
            autocomplete="new-password"
            placeholder="••••••••"
        />

        <div class="flex items-center justify-end pt-2">
            <x-hub-ui::button type="submit" variant="primary" size="md">
                {{ __('Reset password') }}
            </x-hub-ui::button>
        </div>
    </form>
</x-guest-layout>
