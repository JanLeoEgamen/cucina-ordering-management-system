@php
    $th = 'border-b border-gray-100 bg-transparent px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-gray-500 whitespace-nowrap dark:border-gray-700 dark:text-gray-400';
    $label = 'text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400';

    $date = \Illuminate\Support\Carbon::parse($session->order_date)->format('M j, Y');
    $window = substr($session->start_time, 0, 5) . ' – ' . substr($session->end_time, 0, 5);
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col items-start gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
            <div>
                <a href="{{ route('bulk-sessions.index') }}"
                   class="inline-flex items-center gap-1 text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 6-6 6 6 6" /></svg>
                    {{ __('Bulk Sessions') }}
                </a>

                <h2 class="mt-1 flex flex-wrap items-center gap-2 text-xl font-semibold text-gray-900 dark:text-white">
                    {{ $session->title ?: __('Bulk Session') }} · {{ $date }}
                    {!! $statusBadge !!}
                </h2>
            </div>

            {{-- Ending is the creator's call, so the button is only rendered for
                 them, and only while the session is still open. --}}
            @if ($canEnd)
                <button type="button" id="end-session"
                        data-end-url="{{ route('bulk-sessions.end', $session->bulk_session_id) }}"
                        data-subject="{{ $date }}"
                        class="inline-flex w-full items-center justify-center rounded-lg bg-amber-500 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-amber-600 focus:outline-none focus:ring-4 focus:ring-amber-300 sm:w-auto dark:focus:ring-amber-800">
                    {{ __('End Session') }}
                </button>
            @endif
        </div>
    </x-slot>

    <div class="container mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
        {{-- Facts --}}
        <section class="grid grid-cols-2 gap-4 rounded-xl border border-gray-200 bg-white p-4 sm:grid-cols-3 lg:grid-cols-5 dark:border-gray-700 dark:bg-gray-800">
            <div>
                <p class="{{ $label }}">{{ __('Session Date') }}</p>
                <p class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ $date }}</p>
            </div>
            <div>
                <p class="{{ $label }}">{{ __('Time') }}</p>
                <p class="mt-1 text-sm font-medium tabular-nums text-gray-900 dark:text-white">{{ $window }}</p>
            </div>
            <div>
                <p class="{{ $label }}">{{ __('Minimum plates per order') }}</p>
                <p class="mt-1 text-sm font-medium tabular-nums text-gray-900 dark:text-white">{{ (int) $session->min_quantity }}</p>
            </div>
            <div>
                <p class="{{ $label }}">{{ __('Created By') }}</p>
                <p class="mt-1 truncate text-sm font-medium text-gray-900 dark:text-white">{{ $session->created_by_name ?? '—' }}</p>
            </div>
            <div>
                <p class="{{ $label }}">{{ __('Orders') }}</p>
                <p class="mt-1 text-sm font-medium tabular-nums text-gray-900 dark:text-white">{{ $ordersCount }}</p>
            </div>
        </section>

        {{-- The dishes of the session. No stock column: a bulk session has no limit. --}}
        <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card dark:border-gray-700 dark:bg-gray-800">
            <h3 class="flex flex-wrap items-center gap-2 px-4 py-3 text-sm font-semibold text-gray-900 dark:text-white">
                {{ __('Dishes') }}
                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium tabular-nums text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ count($session->dishes) }}</span>
            </h3>

            <div class="overflow-x-auto border-t border-gray-200 dark:border-gray-700">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead>
                        <tr>
                            <th scope="col" class="{{ $th }}">{{ __('Dish') }}</th>
                            <th scope="col" class="{{ $th }} text-right">{{ __('Price') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-700 dark:bg-gray-800">
                        @forelse ($session->dishes as $dish)
                            <tr>
                                <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-white">{{ $dish['dish_name'] }}</td>
                                <td class="px-4 py-3 text-right text-sm tabular-nums text-gray-600 dark:text-gray-300">₱{{ number_format($dish['price'], 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="2" class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">{{ __('This bulk session offers no dishes.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    @if ($canEnd)
        {{-- The confirmation for End Session; resources/js/pages/bulk-session-show.js sends it. --}}
        <x-edit-confirmation-modal
            title="{{ __('Confirm End') }}"
            message="{{ __('Are you sure you want to end this bulk session? Orders already placed are kept, and customers can no longer order from it.') }}"
            confirm-label="{{ __('End Session') }}"
        />
    @endif

    @push('scripts')
        @if ($canEnd)
            @vite('resources/js/pages/bulk-session-show.js')
        @endif
    @endpush
</x-app-layout>
