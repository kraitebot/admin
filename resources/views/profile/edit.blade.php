@php
    $user = auth()->user();
    // Content-area form control (light/dark via the content theme tokens).
    $ctrl = 'h-[42px] w-full px-3 bg-input border border-line rounded-control text-[13px] text-fg-1 font-sans transition-colors duration-fast ease-out focus:border-accent focus:outline-none';
    $lbl = 'font-mono text-[10px] font-semibold tracking-[0.11em] uppercase text-fg-mute mb-[7px]';
    $err = 'font-mono text-[11px] text-pnldown mt-1.5';
    $cardHead = 'flex items-center justify-between gap-3 py-[15px] px-5 border-b border-line-soft';
    $cardTitle = 'font-sans font-semibold text-[14px] text-fg-1 flex items-center gap-[9px] whitespace-nowrap';
    $btnPrimary = 'appearance-none font-sans font-semibold rounded-control border border-transparent cursor-pointer inline-flex items-center gap-[7px] whitespace-nowrap transition-colors duration-fast ease-out active:translate-y-px h-[38px] px-4 text-[13px] bg-accent text-accent-on hover:bg-accent-hover';
@endphp

<x-app-layout active="profile" :title="'Kraite — Profile'">
    {{-- ===================== PAGE HEADER ===================== --}}
    <div class="flex items-end justify-between gap-5 pb-5 mb-6 border-b border-line max-[820px]:flex-col max-[820px]:items-start">
        <div>
            <div class="font-mono text-[11px] font-medium tracking-[0.12em] uppercase text-fg-3 mb-2 flex items-center gap-2">
                <x-feathericon-user class="w-[13px] h-[13px]" stroke-width="1.75"/>ACCOUNT
            </div>
            <h1 class="font-sans font-bold text-[28px] tracking-[-0.02em] text-fg-1 leading-[1.1] max-[640px]:text-[24px]">Profile</h1>
            <div class="text-[13px] text-fg-3 mt-1.5">Your trader identity and sign-in credentials.</div>
        </div>
        <div class="flex items-center gap-2.5 flex-shrink-0">
            <span class="w-[38px] h-[38px] rounded-full text-accent font-mono font-bold text-[14px] flex items-center justify-center" style="background: color-mix(in srgb, var(--accent) 18%, transparent)">{{ collect(explode(' ', trim($user->name)))->filter()->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->take(2)->implode('') ?: '?' }}</span>
            <div class="flex flex-col leading-[1.2]">
                <span class="text-[13px] font-semibold text-fg-1">{{ $user->name }}</span>
                <span class="font-mono text-[10px] text-fg-mute tracking-[0.04em]">{{ $user->is_admin ? 'SYSADMIN' : 'TRADER' }}</span>
            </div>
        </div>
    </div>

    <div class="flex flex-col gap-6 max-w-[680px]">

        {{-- ===================== PROFILE INFORMATION ===================== --}}
        <div class="card">
            <div class="{{ $cardHead }}">
                <div class="{{ $cardTitle }}"><x-feathericon-user class="w-4 h-4 text-fg-3" stroke-width="1.75"/>Profile information</div>
                @if(session('status') === 'profile-updated')
                    <span x-data="{ show: true }" x-init="setTimeout(() => show = false, 3000)" x-show="show" x-cloak
                          class="font-mono text-[10px] font-bold tracking-[0.08em] uppercase text-pnlup">Saved</span>
                @endif
            </div>
            <form method="POST" action="{{ route('profile.update') }}" class="p-5 flex flex-col gap-4">
                @csrf
                @method('patch')
                <div>
                    <div class="{{ $lbl }}">Name</div>
                    <input type="text" name="name" value="{{ old('name', $user->name) }}" required autocomplete="name" class="{{ $ctrl }}"/>
                    @error('name')<div class="{{ $err }}">{{ $message }}</div>@enderror
                </div>
                <div>
                    <div class="{{ $lbl }}">Email</div>
                    <input type="email" name="email" value="{{ old('email', $user->email) }}" required autocomplete="username" class="{{ $ctrl }}"/>
                    @error('email')<div class="{{ $err }}">{{ $message }}</div>@enderror
                    @if($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                        <div class="mt-2 flex items-center gap-2 text-[12px] text-warn">
                            <x-feathericon-alert-triangle class="w-[13px] h-[13px]" stroke-width="1.75"/>
                            Your email address is unverified.
                        </div>
                    @endif
                </div>
                <div class="flex items-center gap-3 pt-1">
                    <button type="submit" class="{{ $btnPrimary }}">Save changes</button>
                </div>
            </form>
        </div>

        {{-- ===================== UPDATE PASSWORD ===================== --}}
        <div class="card">
            <div class="{{ $cardHead }}">
                <div class="{{ $cardTitle }}"><x-feathericon-lock class="w-4 h-4 text-fg-3" stroke-width="1.75"/>Update password</div>
                @if(session('status') === 'password-updated')
                    <span x-data="{ show: true }" x-init="setTimeout(() => show = false, 3000)" x-show="show" x-cloak
                          class="font-mono text-[10px] font-bold tracking-[0.08em] uppercase text-pnlup">Saved</span>
                @endif
            </div>
            <form method="POST" action="{{ route('password.update') }}" class="p-5 flex flex-col gap-4">
                @csrf
                @method('put')
                <div>
                    <div class="{{ $lbl }}">Current password</div>
                    <input type="password" name="current_password" autocomplete="current-password" class="{{ $ctrl }}"/>
                    @error('current_password', 'updatePassword')<div class="{{ $err }}">{{ $message }}</div>@enderror
                </div>
                <div>
                    <div class="{{ $lbl }}">New password</div>
                    <input type="password" name="password" autocomplete="new-password" class="{{ $ctrl }}"/>
                    @error('password', 'updatePassword')<div class="{{ $err }}">{{ $message }}</div>@enderror
                </div>
                <div>
                    <div class="{{ $lbl }}">Confirm new password</div>
                    <input type="password" name="password_confirmation" autocomplete="new-password" class="{{ $ctrl }}"/>
                    @error('password_confirmation', 'updatePassword')<div class="{{ $err }}">{{ $message }}</div>@enderror
                </div>
                <div class="flex items-center gap-3 pt-1">
                    <button type="submit" class="{{ $btnPrimary }}">Update password</button>
                </div>
            </form>
        </div>

        {{-- ===================== DELETE ACCOUNT ===================== --}}
        <div class="card" style="border-color: color-mix(in srgb, var(--danger) 30%, var(--border));"
             x-data="{ confirming: {{ $errors->userDeletion->isNotEmpty() ? 'true' : 'false' }} }">
            <div class="{{ $cardHead }}" style="border-color: color-mix(in srgb, var(--danger) 22%, var(--border));">
                <div class="{{ $cardTitle }} !text-pnldown"><x-feathericon-trash-2 class="w-4 h-4 text-pnldown" stroke-width="1.75"/>Delete account</div>
            </div>
            <div class="p-5">
                <p class="text-[13px] text-fg-3 leading-[1.55] max-w-[520px] mb-4">
                    Permanently delete your account and all of its data. This cannot be undone — exchange connections,
                    configuration and history are removed. Open positions are not closed by this action; detach your
                    exchange accounts first if you want the bot to stop trading.
                </p>

                <button type="button" x-show="!confirming" @click="confirming = true"
                        class="appearance-none font-sans font-semibold rounded-control border cursor-pointer inline-flex items-center gap-[7px] whitespace-nowrap transition-colors duration-fast ease-out active:translate-y-px h-[38px] px-4 text-[13px] text-pnldown"
                        style="background: var(--pnl-down-bg); border-color: color-mix(in srgb, var(--danger) 40%, transparent);">
                    <x-feathericon-trash-2 class="w-[15px] h-[15px]" stroke-width="1.75"/>Delete account
                </button>

                <form method="POST" action="{{ route('profile.destroy') }}" x-show="confirming" x-cloak class="flex flex-col gap-3">
                    @csrf
                    @method('delete')
                    <div class="max-w-[360px]">
                        <div class="{{ $lbl }}">Confirm with your password</div>
                        <input type="password" name="password" autocomplete="current-password" placeholder="Password" class="{{ $ctrl }}"/>
                        @error('password', 'userDeletion')<div class="{{ $err }}">{{ $message }}</div>@enderror
                    </div>
                    <div class="flex items-center gap-3">
                        <button type="submit"
                                class="appearance-none font-sans font-semibold rounded-control border-0 cursor-pointer inline-flex items-center gap-[7px] whitespace-nowrap transition-colors duration-fast ease-out active:translate-y-px h-[38px] px-4 text-[13px] text-white"
                                style="background: var(--danger);">
                            Permanently delete
                        </button>
                        <button type="button" @click="confirming = false"
                                class="appearance-none font-sans font-semibold rounded-control border cursor-pointer inline-flex items-center gap-[7px] whitespace-nowrap transition-colors duration-fast ease-out h-[38px] px-4 text-[13px] bg-transparent text-fg-1 border-line-strong hover:bg-hover">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </div>

    <style>[x-cloak] { display: none !important; }</style>
</x-app-layout>
