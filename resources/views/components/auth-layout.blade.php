@props(['title' => 'Kraite — Sign in'])
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>{{ $title }}</title>
    <link rel="icon" href="{{ asset('svg/snake-green.svg') }}"/>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-black text-ink-9 font-sans">
<div class="min-h-screen flex flex-col items-center justify-center px-4 py-12">
    <a href="/" class="inline-flex items-center gap-3 mb-8 no-underline">
        <img src="{{ asset('svg/snake-green.svg') }}" alt="Kraite" class="w-9 h-9"/>
        <span class="font-sans font-bold text-[20px] tracking-[-0.01em] text-ink-9">Kraite</span>
    </a>

    <div class="w-full max-w-[420px] bg-ink-1 border border-ink-3 rounded-surface p-7 shadow-2">
        {{ $slot }}
    </div>

    @if(! empty($footer))
        <div class="mt-6 text-center font-mono text-[11px] text-ink-6 tracking-[0.04em]">{{ $footer }}</div>
    @endif
</div>
</body>
</html>
