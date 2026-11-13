{{--
    Layout of the signed-out pages (log in, register, password reset ...).

    From `lg` it is a split screen: an ink panel on the left that says what the app is for,
    the form on the right. On a phone only the form shows, under a small brand header.

        <x-guest-layout heading="Welcome back" subheading="Sign in to order from today's menu.">
            ...the form...
            <x-slot name="footer"> New here? <a ...>Create an account</a> </x-slot>
        </x-guest-layout>

    `heading` / `subheading` / the `footer` slot are all optional.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Cucina') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        @include('layouts.theme-script')

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-gray-50 font-sans text-gray-900 antialiased dark:bg-gray-900">
        <div class="fixed right-4 top-4 z-10">
            <x-ui.theme-toggle />
        </div>

        <div class="min-h-screen lg:grid lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
            {{-- Brand panel (desktop). With dishes to show it is a collage of them scrolling up;
                 without any, the three plain points stand in. --}}
            <aside class="relative hidden flex-col justify-between overflow-hidden bg-ink p-12 text-white lg:flex dark:bg-gray-800 dark:ring-1 dark:ring-white/10">
                @if ($collage)
                    {{-- The collage: three columns of dish tiles, tilted a little and scrolling up at
                         their own speeds. Each column holds its tiles twice, so the loop has no seam
                         (the `marquee-up` keyframes in tailwind.config.js). Decoration only. --}}
                    <div class="pointer-events-none absolute inset-0" aria-hidden="true">
                        <x-ui.dish-collage :columns="$collage" />

                        {{-- Fades that keep the brand and the text readable over the photos. --}}
                        <div class="absolute inset-x-0 top-0 h-44 bg-gradient-to-b from-ink to-transparent dark:from-gray-800"></div>
                        <div class="absolute inset-x-0 bottom-0 h-[68%] bg-gradient-to-t from-ink via-ink/90 to-transparent dark:from-gray-800 dark:via-gray-800/90"></div>
                        <div class="absolute inset-y-0 left-0 w-16 bg-gradient-to-r from-ink/60 to-transparent dark:from-gray-800/60"></div>
                    </div>
                @endif

                <a href="/" class="relative z-10 inline-flex items-center gap-3 self-start">
                    <x-application-logo class="h-10 w-10 fill-current text-mist" />
                    <span class="text-xl font-semibold tracking-tight">Cucina</span>
                </a>

                <div class="relative z-10 {{ $collage ? 'max-w-lg' : 'max-w-md' }}">
                    <h2 class="animate-fade-up text-4xl font-bold leading-tight tracking-tight">
                        {{ __('Good food, ordered in a few taps.') }}
                    </h2>
                    <p class="mt-4 animate-fade-up text-base text-white/70" style="animation-delay: 60ms">
                        {{ __('Pick from the live menu, pay the way you like, and collect your order when it is ready.') }}
                    </p>

                    @unless ($collage)
                        <ul class="mt-10 space-y-5">
                            @foreach ([
                                [__('A live menu'), __('Each ordering session offers a limited stock of dishes, so what you see is what is available.'), 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253'],
                                [__('Pay your way'), __('Cash on pickup, or an e-wallet or bank transfer with your proof of payment.'), 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z'],
                                [__('Follow every order'), __('See what you ordered and where your payment stands, any time.'), 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4'],
                            ] as $index => [$title, $text, $icon])
                                <li class="flex animate-fade-up gap-4" style="animation-delay: {{ 120 + $index * 60 }}ms">
                                    <span class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full border border-white/20 bg-white/10">
                                        <svg class="h-5 w-5 text-mist" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="{{ $icon }}" /></svg>
                                    </span>
                                    <span>
                                        <span class="block text-sm font-semibold">{{ $title }}</span>
                                        <span class="mt-0.5 block text-sm text-white/60">{{ $text }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        {{-- Over the photos, three short points as chips instead of the long list. --}}
                        <ul class="mt-6 flex animate-fade-up flex-wrap gap-2" style="animation-delay: 120ms">
                            @foreach ([__('Live menu'), __('Cash or online payment'), __('Track every order')] as $chip)
                                <li class="rounded-full border border-white/20 bg-white/10 px-3.5 py-1.5 text-sm font-medium backdrop-blur-sm">{{ $chip }}</li>
                            @endforeach
                        </ul>
                    @endunless
                </div>

                <p class="relative z-10 text-xs text-white/40">&copy; {{ date('Y') }} Cucina</p>

                @unless ($collage)
                    {{-- A soft ring behind the text, so the panel is not a flat block. --}}
                    <span class="pointer-events-none absolute -bottom-40 -right-40 h-96 w-96 rounded-full border border-white/10" aria-hidden="true"></span>
                    <span class="pointer-events-none absolute -bottom-24 -right-24 h-72 w-72 rounded-full border border-white/10" aria-hidden="true"></span>
                @endunless
            </aside>

            {{-- Form --}}
            <main class="flex min-h-screen flex-col items-center justify-center px-5 py-12 sm:px-8">
                <a href="/" class="mb-8 inline-flex items-center gap-2.5 lg:hidden">
                    <x-application-logo class="h-9 w-9 fill-current text-gray-900 dark:text-mist" />
                    <span class="text-lg font-semibold tracking-tight text-gray-900 dark:text-white">Cucina</span>
                </a>

                <div class="w-full max-w-md animate-fade-up">
                    @if ($attributes->get('heading'))
                        <div class="mb-6">
                            <h1 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white">{{ $attributes->get('heading') }}</h1>
                            @if ($attributes->get('subheading'))
                                <p class="mt-1.5 text-sm text-gray-500 dark:text-gray-400">{{ $attributes->get('subheading') }}</p>
                            @endif
                        </div>
                    @endif

                    <div class="rounded-2xl bg-white p-6 shadow-card sm:p-8 dark:bg-gray-800">
                        {{ $slot }}
                    </div>

                    @isset($footer)
                        <p class="mt-6 text-center text-sm text-gray-500 dark:text-gray-400">{{ $footer }}</p>
                    @endisset
                </div>
            </main>
        </div>
    </body>
</html>
