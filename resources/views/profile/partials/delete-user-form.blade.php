<section class="space-y-6">
    <p class="text-sm ui-text-subtle">
        {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Before deleting your account, please download any data or information that you wish to retain.') }}
    </p>

    <x-hub-ui::button
        variant="danger"
        x-data=""
        x-on:click.prevent="$dispatch('open-modal', 'confirm-user-deletion')"
    >{{ __('Delete Account') }}</x-hub-ui::button>

    <x-hub-ui::modal name="confirm-user-deletion" :show="$errors->userDeletion->isNotEmpty()" focusable>
        <form method="post" action="{{ route('profile.destroy') }}" class="p-6">
            @csrf
            @method('delete')

            <h2 class="text-lg font-medium ui-text">
                {{ __('Are you sure you want to delete your account?') }}
            </h2>

            <p class="mt-1 text-sm ui-text-subtle">
                {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.') }}
            </p>

            <div class="mt-6">
                <x-hub-ui::input
                    id="delete_user_password"
                    name="password"
                    type="password"
                    :placeholder="__('Password')"
                    :error="$errors->userDeletion->first('password')"
                />
            </div>

            <div class="mt-6 flex justify-end">
                <x-hub-ui::button variant="secondary" x-on:click="$dispatch('close')">
                    {{ __('Cancel') }}
                </x-hub-ui::button>

                <x-hub-ui::button type="submit" variant="danger" class="ms-3">
                    {{ __('Delete Account') }}
                </x-hub-ui::button>
            </div>
        </form>
    </x-hub-ui::modal>
</section>
