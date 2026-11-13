{{--
    Numbered pagination in the app's own look (used as ->links('pagination.numbered')):
    "Showing 21–40 of 87", Previous / Next, and the page numbers (a short "Page 2 of 5"
    on phones). The current page is the ink pill, like every primary button.
--}}
@php
    $button = 'inline-flex h-9 min-w-[2.25rem] items-center justify-center rounded-lg border px-3 text-sm font-medium transition focus:outline-none focus:ring-4 focus:ring-gray-200 dark:focus:ring-gray-700';
    $idle = 'border-gray-300 bg-white text-gray-700 hover:bg-gray-100 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600';
    $off = 'cursor-not-allowed border-gray-200 bg-gray-50 text-gray-400 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-500';
@endphp

@if ($paginator->hasPages())
    <nav class="mt-4 flex flex-col items-center gap-3 sm:flex-row sm:justify-between" aria-label="{{ __('Page navigation') }}">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ __('Showing :from–:to of :total', ['from' => $paginator->firstItem(), 'to' => $paginator->lastItem(), 'total' => $paginator->total()]) }}
        </p>

        <div class="flex items-center gap-1">
            @if ($paginator->onFirstPage())
                <span class="{{ $button }} {{ $off }}" aria-disabled="true">{{ __('Previous') }}</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="{{ $button }} {{ $idle }}">{{ __('Previous') }}</a>
            @endif

            {{-- Phones: no row of numbers, just where you are. --}}
            <span class="px-2 text-sm text-gray-500 sm:hidden dark:text-gray-400">{{ __('Page :page of :last', ['page' => $paginator->currentPage(), 'last' => $paginator->lastPage()]) }}</span>

            <div class="hidden items-center gap-1 sm:flex">
                @foreach ($elements as $element)
                    @if (is_string($element))
                        <span class="px-1 text-sm text-gray-400 dark:text-gray-500" aria-hidden="true">{{ $element }}</span>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            @if ($page === $paginator->currentPage())
                                <span aria-current="page" class="{{ $button }} border-transparent bg-indigo-600 text-white dark:bg-mist dark:text-ink">{{ $page }}</span>
                            @else
                                <a href="{{ $url }}" class="{{ $button }} {{ $idle }}" aria-label="{{ __('Go to page :page', ['page' => $page]) }}">{{ $page }}</a>
                            @endif
                        @endforeach
                    @endif
                @endforeach
            </div>

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="{{ $button }} {{ $idle }}">{{ __('Next') }}</a>
            @else
                <span class="{{ $button }} {{ $off }}" aria-disabled="true">{{ __('Next') }}</span>
            @endif
        </div>
    </nav>
@endif
