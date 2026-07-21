<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'GPHA EMS') }}</title>

        <link rel="icon" type="image/png" href="{{ asset('images/gpha-logo.png') }}">
        <link rel="apple-touch-icon" href="{{ asset('images/gpha-logo.png') }}">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=nunito-sans:400,500,600,700,800,900&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased">
        <div class="flex min-h-screen flex-col bg-gpha-shell">
            <livewire:layout.navigation />

            <!-- Page Heading -->
            @if (isset($header))
                <header class="bg-white shadow">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endif

            <!-- Page Content -->
            <main class="flex-1">
                {{ $slot }}
            </main>
            <footer class="mt-8 bg-gpha-primary text-white"><div class="mx-auto flex max-w-[1600px] flex-col gap-3 px-4 py-6 sm:px-6 md:flex-row md:items-center md:justify-between"><div class="flex items-center gap-3"><span class="flex h-11 w-11 rounded-full bg-white p-1"><x-application-logo class="h-full w-full object-contain" /></span><div><p class="font-extrabold">Ghana <span class="text-gpha-secondary">Ports</span> and Harbours Authority</p><p class="text-sm text-white/70">Emergency Medical Services Department</p></div></div><p class="text-sm text-white/70">© {{ now()->year }} GPHA EMS</p></div></footer>
        </div>
        <x-ems.confirmation-dialog />
    </body>
</html>
