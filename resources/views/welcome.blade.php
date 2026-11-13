{{--
    The public landing page (WelcomeController). A visitor learns what Cucina is for, whether
    ordering is open right now, and how to start; someone signed in gets their way back in.
    Nothing private is on it: dish names and photos only, no prices or stock.
--}}
@php
    $signedIn = auth()->check();
    $primary = 'inline-flex items-center justify-center gap-2 rounded-full bg-gray-900 px-6 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-gray-700 focus:outline-none focus:ring-4 focus:ring-gray-300 dark:bg-mist dark:text-ink dark:hover:bg-white dark:focus:ring-gray-600';
    $outline = 'inline-flex items-center justify-center gap-2 rounded-full border border-gray-300 bg-white px-6 py-3 text-sm font-semibold text-gray-800 transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700 dark:focus:ring-gray-700';
    // The same two buttons on the ink hero: mist fill, and a white outline.
    $heroPrimary = 'inline-flex items-center justify-center gap-2 rounded-full bg-mist px-6 py-3 text-sm font-semibold text-ink shadow-sm transition hover:bg-white focus:outline-none focus:ring-4 focus:ring-white/30';
    $heroOutline = 'inline-flex items-center justify-center gap-2 rounded-full border border-white/30 px-6 py-3 text-sm font-semibold text-white transition hover:bg-white/10 focus:outline-none focus:ring-4 focus:ring-white/20';

    $window = fn ($session) => substr($session->start_time, 0, 5) . ' – ' . substr($session->end_time, 0, 5);
    $day = fn ($session) => \Illuminate\Support\Carbon::parse($session->order_date)->translatedFormat('D, M j');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Cucina') }} &middot; {{ __('Order fresh, pick up fast') }}</title>
        <meta name="description" content="{{ __('Order from the live menu, pay cash on pickup or online, and follow your order.') }}">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        @include('layouts.theme-script')

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-gray-50 font-sans text-gray-900 antialiased dark:bg-gray-900 dark:text-gray-100">
        {{-- Top bar --}}
        <header class="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-5 sm:px-6 lg:px-8">
            <a href="{{ route('welcome') }}" class="inline-flex items-center gap-2.5">
                <x-application-logo class="h-9 w-9 fill-current text-gray-900 dark:text-mist" />
                <span class="text-lg font-semibold tracking-tight">Cucina</span>
            </a>

            <nav class="flex items-center gap-2 sm:gap-3" aria-label="{{ __('Account') }}">
                <x-ui.theme-toggle />

                @if ($signedIn)
                    <a href="{{ route('dashboard') }}" class="{{ $primary }} !px-5 !py-2.5">{{ __('Dashboard') }}</a>
                @else
                    <a href="{{ route('login') }}" class="{{ $outline }} !px-5 !py-2.5">{{ __('Log in') }}</a>
                    @if (Route::has('register'))
                        <a href="{{ route('register') }}" class="{{ $primary }} hidden !px-5 !py-2.5 sm:inline-flex">{{ __('Sign up') }}</a>
                    @endif
                @endif
            </nav>
        </header>

        <main class="mx-auto max-w-6xl space-y-6 px-4 pb-16 sm:px-6 lg:px-8">
            {{-- Hero --}}
            <section class="relative overflow-hidden rounded-3xl bg-ink p-7 text-white shadow-card sm:p-12 lg:p-14 dark:bg-gray-800 dark:ring-1 dark:ring-white/10">
                @if ($collage)
                    {{-- The dishes, scrolling up behind the right side of the hero. The left edge fades into
                         the ink so the headline stays readable, and the order ticket floats over them.
                         Decoration only (the same collage as the sign-in panel). --}}
                    <div class="pointer-events-none absolute inset-y-0 right-0 hidden w-[62%] overflow-hidden lg:block" aria-hidden="true">
                        <x-ui.dish-collage :columns="$collage" />

                        <div class="absolute inset-y-0 left-0 w-1/2 bg-gradient-to-r from-ink via-ink/75 to-transparent dark:from-gray-800 dark:via-gray-800/75"></div>
                        <div class="absolute inset-x-0 top-0 h-24 bg-gradient-to-b from-ink/80 to-transparent dark:from-gray-800/80"></div>
                        <div class="absolute inset-x-0 bottom-0 h-24 bg-gradient-to-t from-ink/80 to-transparent dark:from-gray-800/80"></div>
                    </div>
                @endif

                <div class="relative z-10 grid items-center gap-10 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,0.85fr)]">
                    <div>
                        {{-- Is ordering open? The live session, else the next one. --}}
                        @if ($live)
                            <p class="inline-flex animate-fade-up items-center gap-2.5 rounded-full border border-white/20 bg-white/10 px-4 py-1.5 text-sm font-medium">
                                <span class="relative flex h-2.5 w-2.5"><span class="absolute inline-flex h-full w-full animate-live-dot rounded-full bg-mist"></span><span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-mist"></span></span>
                                {{ __('Ordering is open') }} <span class="text-white/60">&middot;</span> <span class="tabular-nums">{{ $window($live) }}</span>
                            </p>
                        @elseif ($next)
                            <p class="inline-flex animate-fade-up items-center gap-2.5 rounded-full border border-white/20 bg-white/10 px-4 py-1.5 text-sm font-medium">
                                <span class="h-2.5 w-2.5 rounded-full bg-white/40"></span>
                                {{ __('Next session') }} <span class="text-white/60">&middot;</span> <span class="tabular-nums">{{ $day($next) }}, {{ $window($next) }}</span>
                            </p>
                        @else
                            <p class="inline-flex animate-fade-up items-center gap-2.5 rounded-full border border-white/20 bg-white/10 px-4 py-1.5 text-sm font-medium">
                                <span class="h-2.5 w-2.5 rounded-full bg-white/40"></span>
                                {{ __('No session is open right now') }}
                            </p>
                        @endif

                        <h1 class="mt-6 animate-fade-up text-4xl font-bold leading-[1.1] tracking-tight sm:text-5xl lg:text-6xl" style="animation-delay: 60ms">
                            {{ __('Order fresh,') }}<br>{{ __('pick up fast.') }}
                        </h1>
                        <p class="mt-5 max-w-xl animate-fade-up text-base text-white/70 sm:text-lg" style="animation-delay: 120ms">
                            {{ __('Cucina serves in ordering sessions. Pick from the live menu while a session is open, pay cash on pickup or online, and follow your order until it is ready.') }}
                        </p>

                        <div class="mt-8 flex animate-fade-up flex-wrap gap-3" style="animation-delay: 180ms">
                            @if ($signedIn)
                                @can('view menu')
                                    <a href="{{ route('menu.index') }}" class="{{ $heroPrimary }}">
                                        {{ __('Order from the menu') }}
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6" /></svg>
                                    </a>
                                @endcan
                                <a href="{{ route('dashboard') }}" class="{{ $heroOutline }}">{{ __('Open dashboard') }}</a>
                            @else
                                @if (Route::has('register'))
                                    <a href="{{ route('register') }}" class="{{ $heroPrimary }}">
                                        {{ __('Create an account') }}
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6" /></svg>
                                    </a>
                                @endif
                                <a href="{{ route('login') }}" class="{{ $heroOutline }}">{{ __('Log in') }}</a>
                            @endif
                        </div>
                    </div>

                    {{-- An order ticket: the journey of one order, in the app's own status labels. --}}
                    <div class="hidden animate-fade-up lg:block" style="animation-delay: 140ms" aria-hidden="true">
                        <div class="mx-auto max-w-sm rounded-2xl bg-white p-6 text-gray-900 shadow-card-lift dark:bg-gray-900 dark:text-gray-100">
                            <div class="flex items-center justify-between">
                                <p class="text-sm font-semibold">{{ __('Your order') }}</p>
                                <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-300">{{ __('Pending') }}</span>
                            </div>

                            <div class="mt-5 space-y-3">
                                @foreach ([72, 54, 63] as $bar)
                                    <div class="flex items-center justify-between gap-4">
                                        <span class="h-3 rounded-full bg-gray-200 dark:bg-gray-700" style="width: {{ $bar }}%"></span>
                                        <span class="h-3 w-10 rounded-full bg-gray-100 dark:bg-gray-800"></span>
                                    </div>
                                @endforeach
                            </div>

                            <ol class="mt-6 space-y-3.5 border-t border-gray-100 pt-5 dark:border-gray-800">
                                @foreach ([[__('Order placed'), true], [__('Payment verified'), true], [__('Ready for pickup'), false]] as [$step, $done])
                                    <li class="flex items-center gap-3 text-sm {{ $done ? 'font-medium' : 'text-gray-400 dark:text-gray-500' }}">
                                        <span class="flex h-5 w-5 items-center justify-center rounded-full {{ $done ? 'bg-gray-900 text-white dark:bg-mist dark:text-ink' : 'border border-gray-300 dark:border-gray-600' }}">
                                            @if ($done)
                                                <svg class="h-3 w-3" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m4.5 10.5 3.5 3.5 7.5-8" /></svg>
                                            @endif
                                        </span>
                                        {{ $step }}
                                    </li>
                                @endforeach
                            </ol>
                        </div>
                    </div>
                </div>

                @unless ($collage)
                    {{-- Soft rings behind the content, so the hero is not a flat block (the collage does that when there are dishes). --}}
                    <span class="pointer-events-none absolute -right-32 -top-32 h-96 w-96 rounded-full border border-white/10" aria-hidden="true"></span>
                    <span class="pointer-events-none absolute -right-16 -top-16 h-64 w-64 rounded-full border border-white/10" aria-hidden="true"></span>
                @endunless
            </section>

            {{-- How ordering works --}}
            <section aria-labelledby="how-title">
                <h2 id="how-title" class="mb-4 text-sm font-semibold text-gray-500 dark:text-gray-400">{{ __('How ordering works') }}</h2>

                <ol class="grid gap-4 sm:grid-cols-3">
                    @foreach ([
                        [__('Pick from the live menu'), __('A session offers a limited stock of dishes for its window. Add what you want; what is gone is gone.'), 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253'],
                        [__('Choose how to pay'), __('Pay cash when you pick up, or pay by e-wallet or bank transfer and attach your proof.'), 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z'],
                        [__('Pick up and track'), __('Follow the order and its payment under My Orders until the kitchen hands it over.'), 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4'],
                    ] as $index => [$title, $text, $icon])
                        <li class="animate-fade-up rounded-2xl bg-white p-6 shadow-card transition duration-200 hover:-translate-y-0.5 hover:shadow-card-lift dark:bg-gray-800" style="animation-delay: {{ $index * 60 }}ms">
                            <div class="flex items-center justify-between">
                                <span class="flex h-11 w-11 items-center justify-center rounded-full bg-gray-900 text-white dark:bg-mist dark:text-ink">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="{{ $icon }}" /></svg>
                                </span>
                                <span class="text-3xl font-bold tabular-nums text-gray-200 dark:text-gray-700">0{{ $index + 1 }}</span>
                            </div>
                            <h3 class="mt-5 text-base font-bold">{{ $title }}</h3>
                            <p class="mt-1.5 text-sm leading-relaxed text-gray-500 dark:text-gray-400">{{ $text }}</p>
                        </li>
                    @endforeach
                </ol>
            </section>

            {{-- Ways to pay, from the methods the kitchen has switched on --}}
            @if ($paymentMethods->isNotEmpty())
                <section class="flex flex-col gap-4 rounded-2xl bg-white p-6 shadow-card sm:flex-row sm:items-center sm:justify-between dark:bg-gray-800">
                    <div>
                        <h2 class="text-base font-bold">{{ __('Ways to pay') }}</h2>
                        <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ __('Whatever suits you; every payment is checked before an order is handed over.') }}</p>
                    </div>

                    <ul class="flex flex-wrap gap-2">
                        @foreach ($paymentMethods as $method)
                            <li class="rounded-full border border-gray-200 bg-gray-50 px-4 py-1.5 text-sm font-medium text-gray-700 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200">{{ $method }}</li>
                        @endforeach
                    </ul>
                </section>
            @endif

            {{-- Closing call to action --}}
            @unless ($signedIn)
                <section class="rounded-3xl border border-gray-200 px-6 py-10 text-center dark:border-gray-700">
                    <h2 class="text-2xl font-bold tracking-tight">{{ __('Ready for your first order?') }}</h2>
                    <p class="mx-auto mt-2 max-w-md text-sm text-gray-500 dark:text-gray-400">{{ __('It takes a minute to sign up. An administrator will give your account access to the menu.') }}</p>
                    <div class="mt-6 flex flex-wrap justify-center gap-3">
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="{{ $primary }}">{{ __('Create an account') }}</a>
                        @endif
                        <a href="{{ route('login') }}" class="{{ $outline }}">{{ __('Log in') }}</a>
                    </div>
                </section>
            @endunless
        </main>

        <footer class="border-t border-gray-200 py-6 text-center text-xs text-gray-400 dark:border-gray-800 dark:text-gray-500">
            &copy; {{ date('Y') }} Cucina
        </footer>
    </body>
</html>
