@props([
    'active' => 'dashboard',
    'showBanner' => false,
    'downAccount' => null,
])
<!DOCTYPE html>
<html lang="en" data-theme="dark">
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

    @persist('rail')
        <x-rail :active="$active"/>
    @endpersist

    <div class="flex flex-col min-w-0 h-screen bg-[#07090b] max-[640px]:pb-[62px] max-[420px]:pb-[56px]"
         x-data="{ contentDark: localStorage.getItem('kraite-content-theme') !== 'light' }"
         x-init="$watch('contentDark', v => localStorage.setItem('kraite-content-theme', v ? 'dark' : 'light'))">

        @persist('top-bar')
            <x-top-bar/>
        @endpersist

        @if($showBanner && $downAccount)
            <x-disconnect-banner :account="$downAccount"/>
        @endif

        <div class="content flex-1 min-h-0 overflow-y-auto bg-canvas text-fg rounded-t-2xl relative z-[2] max-[640px]:rounded-t-xl"
             :data-theme="contentDark ? 'dark' : 'light'">
            <div class="max-w-wide mx-auto pt-6 px-8 pb-12 max-[640px]:pt-4 max-[640px]:px-3 max-[640px]:pb-6">
                {{ $slot }}
            </div>
        </div>

        @persist('footer')
            <x-footer/>
        @endpersist
    </div>
</div>
</body>
</html>
