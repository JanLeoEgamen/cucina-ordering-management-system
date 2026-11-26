{{-- The card every dashboard and analytics widget sits in: a small heading and the widget's
     content. `csv` (Analytics page, for who may export) adds the download link. --}}
@props(['title', 'csv' => null])

<div {{ $attributes->class('animate-fade-up min-w-0 rounded-2xl bg-white p-5 shadow-card dark:bg-gray-800 dark:ring-1 dark:ring-white/5') }}>
    <div class="flex items-start justify-between gap-3">
        <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ $title }}</h4>

        @if ($csv)
            <a href="{{ $csv }}" download data-soft-nav="off"
               class="flex-shrink-0 text-xs font-semibold text-gray-500 underline-offset-2 hover:text-gray-900 hover:underline dark:text-gray-400 dark:hover:text-white"
               aria-label="{{ __('Download :title as CSV', ['title' => $title]) }}">{{ __('CSV') }}</a>
        @endif
    </div>

    {{ $slot }}
</div>
