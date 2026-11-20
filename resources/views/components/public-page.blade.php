@props(['title'])

{{--
    The shell of a public text page (no login): the privacy policy, the terms of service and the support
    page. A header with the brand, the theme switch and a way back in, one white card holding the slot,
    and the footer with the other public pages.

        <x-public-page :title="$title"> ...the card's content... </x-public-page>
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title }} &middot; {{ \App\Models\Setting::appName() }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        @include('layouts.theme-script')

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-gray-50 font-sans text-gray-900 antialiased dark:bg-gray-900 dark:text-gray-100">
        <header class="mx-auto flex max-w-3xl items-center justify-between gap-3 px-4 py-5 sm:px-6">
            <a href="{{ route('welcome') }}" class="inline-flex items-center gap-2.5">
                <x-application-logo class="h-9 w-9 fill-current text-gray-900 dark:text-mist" />
                <span class="text-lg font-semibold tracking-tight">{{ \App\Models\Setting::appName() }}</span>
            </a>

            <nav class="flex items-center gap-2 sm:gap-3" aria-label="{{ __('Account') }}">
                <x-ui.theme-toggle />

                @auth
                    <a href="{{ route('dashboard') }}" class="inline-flex items-center justify-center rounded-full bg-gray-900 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-gray-700 focus:outline-none focus:ring-4 focus:ring-gray-300 dark:bg-mist dark:text-ink dark:hover:bg-white dark:focus:ring-gray-600">{{ __('Dashboard') }}</a>
                @else
                    <a href="{{ route('login') }}" class="inline-flex items-center justify-center rounded-full border border-gray-300 bg-white px-5 py-2.5 text-sm font-semibold text-gray-800 transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700 dark:focus:ring-gray-700">{{ __('Log in') }}</a>
                @endauth
            </nav>
        </header>

        <main class="mx-auto max-w-3xl px-4 pb-16 sm:px-6">
            <article class="rounded-2xl bg-white p-6 shadow-card sm:p-10 dark:bg-gray-800">
                {{ $slot }}
            </article>
        </main>

        <footer class="border-t border-gray-200 py-6 text-center text-xs text-gray-400 dark:border-gray-800 dark:text-gray-500">
            &copy; {{ date('Y') }} {{ \App\Models\Setting::appName() }}
            @foreach (['support' => 'Help and Support', 'privacy-policy' => 'Privacy Policy', 'terms-of-service' => 'Terms of Service'] as $routeName => $label)
                <span aria-hidden="true">&middot;</span>
                <a href="{{ route($routeName) }}" class="underline-offset-4 hover:text-gray-600 hover:underline dark:hover:text-gray-300">{{ __($label) }}</a>
            @endforeach
        </footer>
    </body>
</html>
