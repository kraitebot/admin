<x-app-layout>
    <x-hub-ui::page-header title="Profile" description="Manage your account settings." />

    <div class="max-w-2xl space-y-6">
        <x-hub-ui::card title="Profile Information">
            @include('profile.partials.update-profile-information-form')
        </x-hub-ui::card>

        <x-hub-ui::card title="Update Password">
            @include('profile.partials.update-password-form')
        </x-hub-ui::card>

        <x-hub-ui::card title="Delete Account">
            @include('profile.partials.delete-user-form')
        </x-hub-ui::card>
    </div>
</x-app-layout>
