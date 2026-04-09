<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Kraite Admin') }}</title>

        {{-- Hub UI theme --}}
        @include('hub-ui::partials.scripts')
        @include('hub-ui::partials.styles')

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased" style="background-color: rgb(var(--ui-bg-body)); color: rgb(var(--ui-text))">
        <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0">
            <div class="mb-2">
                <a href="/">
                    <img src="{{ asset('logos/wordmark-horizontal.svg') }}" alt="Kraite" class="h-14 w-auto" />
                </a>
            </div>

            <div class="w-full sm:max-w-md mt-4 px-8 py-6 ui-card overflow-hidden rounded-xl shadow-2xl">
                {{ $slot }}
            </div>

            <p class="mt-6 text-xs ui-text-subtle">&copy; {{ date('Y') }} Kraite</p>
        </div>
    </body>
</html>
