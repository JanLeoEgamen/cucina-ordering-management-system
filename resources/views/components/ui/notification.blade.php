@props([
    'timeout' => 5000,
])

@php
    $success = session('success');
    $error   = session('error');
    $warning = session('warning');
    $info    = session('info');

    $show    = $success || $error || $warning || $info;
    $message = $success ?? $error ?? $warning ?? $info;
    $type    = $success ? 'success' : ($error ? 'error' : ($warning ? 'warning' : 'info'));

    $title = match ($type) {
        'success' => __('Success'),
        'error'   => __('Error'),
        'warning' => __('Warning'),
        default   => __('Information'),
    };

    $color = match ($type) {
        'success' => 'border-green-500 bg-green-50 dark:bg-green-900/20',
        'error'   => 'border-red-500 bg-red-50 dark:bg-red-900/20',
        'warning' => 'border-amber-500 bg-amber-50 dark:bg-amber-900/20',
        default   => 'border-blue-500 bg-blue-50 dark:bg-blue-900/20',
    };

    $icon = match ($type) {
        'success' => '<svg class="h-5 w-5 text-green-600" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>',
        'error'   => '<svg class="h-5 w-5 text-red-600" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" /></svg>',
        'warning' => '<svg class="h-5 w-5 text-amber-600" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 100 2h2a1 1 0 100-2H9z" clip-rule="evenodd" /></svg>',
        default   => '<svg class="h-5 w-5 text-blue-600" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" /></svg>',
    };
@endphp

{{--
    Non-blocking toast. It used to render as a full-screen modal with a
    backdrop, which locked the whole UI for {{ $timeout }}ms after every save.
--}}
@if ($show)
    <div x-data="{ show: true }"
         x-init="setTimeout(() => show = false, {{ $timeout }})"
         x-show="show"
         x-cloak
         role="status"
         aria-live="polite"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 translate-y-2"
         class="fixed bottom-4 right-4 z-[100] w-[calc(100%-2rem)] max-w-sm">

        <div class="{{ $color }} flex items-start gap-3 rounded-lg border-l-4 p-4 shadow-lg">
            <div class="flex-shrink-0">{!! $icon !!}</div>

            <div class="min-w-0 flex-1">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $title }}</h3>
                <div class="mt-1 text-sm text-gray-700 dark:text-gray-200">{{ $message }}</div>
            </div>

            <button type="button"
                    @click="show = false"
                    class="-m-1 flex-shrink-0 rounded-lg p-1 text-gray-500 transition hover:bg-black/5 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-gray-400 dark:text-gray-300 dark:hover:bg-white/10 dark:hover:text-white">
                <span class="sr-only">{{ __('Dismiss') }}</span>
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
    </div>
@endif
