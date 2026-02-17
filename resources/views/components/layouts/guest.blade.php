@props([
    'title' => config('app.name'),
])

<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title }}</title>

    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="text-neutral-100 antialiased" style="font-family: 'Inter', sans-serif; background-color: #0a0a0a;">

    {{-- Ambient background effects --}}
    <div class="fixed inset-0 hero-gradient pointer-events-none"></div>
    <div class="fixed inset-0 grid-bg pointer-events-none"></div>

    {{-- Animated strike lines --}}
    <div class="fixed inset-0 overflow-hidden pointer-events-none">
        <div class="strike-line" style="top: 20%; width: 40%; animation-duration: 4s; animation-delay: 0s;"></div>
        <div class="strike-line" style="top: 45%; width: 30%; animation-duration: 6s; animation-delay: 1.5s;"></div>
        <div class="strike-line" style="top: 65%; width: 35%; animation-duration: 5s; animation-delay: 3s;"></div>
        <div class="strike-line" style="top: 85%; width: 25%; animation-duration: 7s; animation-delay: 4.5s;"></div>
    </div>

    <div class="relative min-h-screen flex flex-col items-center justify-center px-4">
        {{-- Logotype --}}
        <div class="mb-10 animate-fade-up">
            <span class="text-3xl font-bold tracking-tight gradient-text" style="font-family: 'Space Grotesk', sans-serif;">
                kraite
            </span>
        </div>

        {{-- Content card --}}
        <div class="w-full max-w-md animate-fade-up-delay-1">
            <div class="rounded-2xl bg-zinc-900/50 border border-zinc-800/50 glow-green backdrop-blur-sm p-8">
                {{ $slot }}
            </div>
        </div>
    </div>
</body>
</html>
