@php
    use App\Support\Analytics\Period;

    // The tab links keep the range, the range links keep the tab.
    $rangeQuery = $period->query();

    $pill = 'inline-flex items-center whitespace-nowrap rounded-full px-3.5 py-1.5 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500';
    $on = 'bg-accent text-accent-on shadow-sm';
    $off = 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600';
    $field = 'block rounded-lg border-gray-300 bg-white py-1.5 text-sm text-gray-900 focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white';
@endphp

<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white">{{ __('Analytics') }}</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $period->label() }}</p>
        </div>
    </x-slot>

    {{-- Must be pushed: soft-nav.js reads the marker OUTSIDE the page area it swaps in, so one left
         inside it is not found on a soft visit (the tabs, the sidebar) and no chart is drawn. --}}
    @push('scripts')
        <x-page-module name="analytics" />
    @endpush

    <div class="mx-auto max-w-7xl space-y-5 px-4 py-6 sm:px-6 lg:px-8">
        {{-- One filter row for everything below it --}}
        <section class="flex flex-wrap items-end gap-x-6 gap-y-3 rounded-2xl bg-white p-4 shadow-card dark:bg-gray-800 dark:ring-1 dark:ring-white/5">
            <nav data-soft-nav class="flex flex-wrap gap-2" aria-label="{{ __('Date range') }}">
                @foreach (Period::PRESETS as $key => $label)
                    <a href="{{ route('analytics.index', ['tab' => $tab, 'range' => $key]) }}"
                       @if ($period->key === $key) aria-current="true" @endif
                       class="{{ $pill }} {{ $period->key === $key ? $on : $off }}">{{ __($label) }}</a>
                @endforeach
            </nav>

            <form method="GET" action="{{ route('analytics.index') }}" class="flex flex-wrap items-end gap-2">
                <input type="hidden" name="tab" value="{{ $tab }}">
                <input type="hidden" name="range" value="{{ Period::CUSTOM }}">
                <label class="text-xs font-semibold text-gray-500 dark:text-gray-400">
                    {{ __('From') }}
                    <input type="date" name="from" required value="{{ $period->fromDate() }}" max="{{ now()->toDateString() }}" class="{{ $field }}">
                </label>
                <label class="text-xs font-semibold text-gray-500 dark:text-gray-400">
                    {{ __('To') }}
                    <input type="date" name="to" required value="{{ $period->toDate() }}" max="{{ now()->toDateString() }}" class="{{ $field }}">
                </label>
                <button type="submit" class="{{ $pill }} {{ $period->key === Period::CUSTOM ? $on : $off }}">{{ __('Apply') }}</button>
            </form>
        </section>

        @if ($period->refused)
            <p role="alert" class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-900/30 dark:text-amber-100">
                {{ __('That date range cannot be used (check the order of the dates; the longest is :days days). The last 30 days are shown.', ['days' => Period::MAX_DAYS]) }}
            </p>
        @endif

        @if (count($tabs) > 1)
            <nav data-soft-nav class="flex gap-2 overflow-x-auto pb-1" aria-label="{{ __('Analytics sections') }}">
                @foreach ($tabs as $key => $label)
                    <a href="{{ route('analytics.index', ['tab' => $key] + $rangeQuery) }}"
                       @if ($tab === $key) aria-current="page" @endif
                       class="{{ $pill }} {{ $tab === $key ? $on : $off }}">{{ __($label) }}</a>
                @endforeach
            </nav>
        @endif

        {{-- Widgets (config/analytics.php): a @canany around the tab and a @can on every widget.
             $widgets holds only the open tab's widgets this person may see, in the registry's order,
             each with its data. --}}
        @canany(\App\Support\Analytics::permissions())
            <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-3">
                @foreach ($widgets as $widget)
                    @can($widget['permission'])
                        @include($widget['view'], $widget['data'])
                    @endcan
                @endforeach
            </div>
        @endcanany
    </div>
</x-app-layout>
