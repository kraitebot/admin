<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ config('hub-ui.theme.default_mode', 'dark') === 'light' ? 'light' : '' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>Complete registration — {{ config('app.name', 'Kraite Admin') }}</title>

    @include('hub-ui::partials.scripts')
    @include('hub-ui::partials.styles')

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

    <style>[x-cloak] { display: none !important; }</style>
</head>
<body class="min-h-screen bg-[#020805] font-sans text-white antialiased">
    <div class="fixed inset-0 -z-10 bg-[radial-gradient(circle_at_center,rgba(16,185,129,0.16),transparent_34%),linear-gradient(135deg,#020805_0%,#03150d_45%,#010409_100%)]"></div>
    <div class="fixed inset-0 -z-10 bg-[url('/logos/snake-white.svg')] bg-[length:34rem_34rem] bg-center bg-no-repeat opacity-10 blur-sm scale-105"></div>
    <div class="fixed inset-0 -z-10 bg-[#020805]/65"></div>

    <main class="min-h-screen px-4 py-8 sm:py-12 flex items-center justify-center">
        <livewire:register-form :uuid="$user->uuid" />
    </main>

    @livewireScriptConfig
</body>
</html>
