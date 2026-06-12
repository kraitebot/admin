@props([
    'active' => 'dashboard',
    'showBanner' => false,
    'downAccount' => null,
])
@php
    // Surface follows the route group, not the host: any `system.*` route is
    // the sysadmin console and swaps the whole UI to staff-mode violet via
    // data-surface; every other route is the trader surface. EnsureAdmin gates
    // the `system.*` group on is_admin, so data-surface=console ⇔ a sysadmin
    // is looking at it.
    $surface = request()->routeIs('system.*') ? 'console' : 'trader';
@endphp
<!DOCTYPE html>
<html lang="en" data-theme="dark" data-surface="{{ $surface }}">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>{{ $title ?? 'Kraite — Admin' }}</title>
    <link rel="icon" href="{{ asset('svg/snake-green.svg') }}"/>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<div class="grid grid-cols-[112px_1fr] h-screen w-screen bg-black text-ink-9 font-sans max-[640px]:grid-cols-[1fr]"
     data-density="compact">

    {{-- Raw x-persist divs, NOT the @persist directive — the directive
         compiles to forceAssetInjection(), which overrides
         livewire.inject_assets=false and loads a SECOND Livewire+Alpine
         alongside the Vite bundle (two instances then fight over the
         rail's data-current state). The runtime handles the x-persist
         attribute itself; the directive only adds the force-inject. --}}
    <div x-persist="rail" class="contents">
        <x-rail :active="$active"/>
    </div>

    <div class="flex flex-col min-w-0 h-screen bg-[#07090b] max-[640px]:pb-[62px] max-[420px]:pb-[56px]"
         x-data="{ contentDark: localStorage.getItem('kraite-content-theme') !== 'light' }"
         x-init="$watch('contentDark', v => localStorage.setItem('kraite-content-theme', v ? 'dark' : 'light'))">

        <div x-persist="top-bar" class="contents">
            <x-top-bar/>
        </div>

        @if($showBanner && $downAccount)
            <x-disconnect-banner :account="$downAccount"/>
        @endif

        <div class="content flex-1 min-h-0 overflow-y-auto bg-canvas text-fg rounded-t-2xl relative z-[2] max-[640px]:rounded-t-xl"
             :data-theme="contentDark ? 'dark' : 'light'">
            <div class="max-w-wide mx-auto pt-6 px-8 pb-12 max-[640px]:pt-4 max-[640px]:px-3 max-[640px]:pb-6">
                {{ $slot }}
            </div>
        </div>

        <div x-persist="footer" class="contents">
            <x-footer/>
        </div>
    </div>
</div>
</body>
</html>
