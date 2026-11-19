{{--
    The page every HTTP error is shown with (resources/views/errors/*.blade.php are one-liners that
    use it). It wears the app's theme like the signed-out pages do: the paper / ink colours, the
    dark / light switch (the choice is restored before paint) and the Cucina brand.

        <x-error-page :code="404" />
        <x-error-page :code="403" :detail="$exception->getMessage()" />

    `code` picks the wording below; a status that is not listed falls back to a generic 4xx / 5xx
    text. `detail` is an optional sentence written by the app itself (e.g. "Complete your profile
    first."): it is shown only for a 403, and never when it is the framework's own technical text.

    It must not fail itself: it runs while the app is in trouble (a broken database, no session, a
    site in maintenance), so the account lookup is rescued and the Vite assets are loaded only when
    they exist.
--}}
@props(['code' => 500, 'detail' => null])

@php
    $code = (int) $code;

    // Outline icons (24x24 paths): a padlock, a magnifier, a clock, a refresh arrow, a warning
    // triangle, a "no entry" circle, a document with an arrow (too large).
    $icons = [
        'lock' => 'M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z',
        'search' => 'M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z',
        'clock' => 'M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z',
        'refresh' => 'M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99',
        'warning' => 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.008v.008H12v-.008z',
        'blocked' => 'M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636',
        'upload' => 'M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5',
    ];

    // code => [icon, title, message, retry]. `retry` adds a "Try again" button for the errors that
    // can go away on their own (a reload fixes an expired page, a busy server, a timeout).
    $known = [
        401 => ['lock', __('Sign in to continue'), __('You need to be signed in to see this page.'), false],
        403 => ['lock', __('You do not have access'), __('Your account is not allowed to open this page. If you think it should be, ask an administrator to check your role.'), false],
        404 => ['search', __('Page not found'), __('The page you are looking for does not exist, or it has moved.'), false],
        405 => ['blocked', __('That is not allowed here'), __('This page cannot be used that way. Go back and use the buttons on the page instead.'), false],
        408 => ['clock', __('The request took too long'), __('The connection timed out before your request arrived. Please try again.'), true],
        413 => ['upload', __('That file is too large'), __('What you sent is bigger than the server accepts. Choose a smaller file (a proof of payment photo can be shrunk) and try again.'), false],
        419 => ['clock', __('This page has expired'), __('You were away for a while, so the page timed out for your safety. Reload it and try again; nothing was saved or charged.'), true],
        429 => ['clock', __('Too many requests'), __('You are going a little too fast. Wait a moment, then try again.'), true],
        500 => ['warning', __('Something went wrong on our side'), __('It is not you. The error has been recorded; please try again in a moment.'), true],
        502 => ['refresh', __('The server could not be reached'), __('We got a bad answer from the server. Please try again in a moment.'), true],
        503 => ['refresh', __('We will be right back'), __('The site is being updated or is very busy. Please try again in a few minutes.'), true],
        504 => ['clock', __('The server took too long'), __('The server did not answer in time. Please try again in a moment.'), true],
    ];

    $kind = $known[$code] ?? ($code >= 500
        ? ['warning', __('Something went wrong on our side'), __('It is not you. Please try again in a moment.'), true]
        : ['blocked', __('We could not open that'), __('There was a problem with that request. Go back and try again.'), false]);

    [$icon, $title, $message, $retry] = $kind;

    // Only a sentence the app wrote itself, and only for a 403 (the framework's and the permission
    // package's own wording is technical and says nothing the page above does not).
    $detail = trim((string) $detail);
    $technical = ['This action is unauthorized.', 'Forbidden'];

    if ($code !== 403 || $detail === '' || in_array($detail, $technical, true) || str_starts_with($detail, 'User does not have')) {
        $detail = null;
    }

    // The account lookup touches the session / database; on a 500 they may be the thing that broke.
    $signedIn = rescue(fn () => auth()->check(), false, false);
    $homeUrl = $signedIn ? route('dashboard') : url('/');
    $homeLabel = $signedIn ? __('Go to dashboard') : __('Back to home');

    // After a `npm run build` (or with the dev server) the theme comes from the Vite assets; a
    // checkout that has neither would otherwise turn every error page into a second error.
    $hasAssets = is_file(public_path('hot')) || is_file(public_path('build/manifest.json'));

    $primary = 'inline-flex w-full items-center justify-center rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-medium text-white transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-300 sm:w-auto dark:focus:ring-indigo-800';
    $secondary = 'inline-flex w-full items-center justify-center rounded-lg border border-gray-300 bg-white px-5 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 sm:w-auto dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">

        <title>{{ $code }} · {{ $title }} · {{ config('app.name', 'Cucina') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        {{-- The saved dark / light choice (or the OS setting), applied before first paint. --}}
        @include('layouts.theme-script')

        @if ($hasAssets)
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
    </head>
    <body class="bg-gray-50 font-sans text-gray-900 antialiased dark:bg-gray-900 dark:text-gray-100">
        @if ($hasAssets)
            <div class="fixed right-4 top-4 z-10">
                <x-ui.theme-toggle />
            </div>
        @endif

        <main class="mx-auto flex min-h-screen max-w-xl flex-col items-center justify-center px-5 py-12 text-center">
            <a href="{{ $homeUrl }}" class="mb-10 inline-flex items-center gap-2.5">
                <x-application-logo class="h-9 w-9 fill-current text-gray-900 dark:text-mist" />
                <span class="text-lg font-semibold tracking-tight text-gray-900 dark:text-white">Cucina</span>
            </a>

            <div class="w-full animate-fade-up rounded-2xl bg-white p-8 shadow-card sm:p-10 dark:bg-gray-800">
                <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200">
                    <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icons[$icon] }}" />
                    </svg>
                </span>

                <p class="mt-5 text-6xl font-bold tabular-nums tracking-tight text-gray-300 dark:text-gray-600" aria-hidden="true">{{ $code }}</p>

                <h1 class="mt-2 text-2xl font-bold tracking-tight text-gray-900 dark:text-white">{{ $title }}</h1>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ $message }}</p>

                @if ($detail)
                    <p class="mt-3 rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-700 dark:bg-gray-700/60 dark:text-gray-200">{{ $detail }}</p>
                @endif

                <div class="mt-8 flex flex-col items-stretch justify-center gap-3 sm:flex-row sm:items-center">
                    @if ($retry)
                        <button type="button" onclick="window.location.reload()" class="{{ $primary }}">{{ __('Try again') }}</button>
                        <a href="{{ $homeUrl }}" class="{{ $secondary }}">{{ $homeLabel }}</a>
                    @else
                        <a href="{{ $homeUrl }}" class="{{ $primary }}">{{ $homeLabel }}</a>
                        <button type="button" onclick="history.length > 1 ? history.back() : (window.location = '{{ $homeUrl }}')" class="{{ $secondary }}">{{ __('Go back') }}</button>
                    @endif
                </div>
            </div>

            <p class="mt-6 text-xs text-gray-400 dark:text-gray-500">&copy; {{ date('Y') }} Cucina</p>
        </main>
    </body>
</html>
