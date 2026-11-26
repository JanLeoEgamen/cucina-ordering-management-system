{{-- Analytics widget `never-ordered`: registered, never ordered. A count, no names. --}}
<x-dashboard.card :csv="$csv ?? null" :title="__('Never ordered')">
    @if ($accounts === 0)
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ __('No account can place orders yet.') }}</p>
    @else
        <p class="mt-3 text-3xl font-bold text-gray-900 dark:text-white">{{ number_format($never) }}</p>
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('of :accounts accounts that can order (:share%)', ['accounts' => number_format($accounts), 'share' => $share]) }}</p>
        <p class="mt-2 text-sm text-gray-700 dark:text-gray-200">{{ trans_choice(':count of them registered more than :days days ago.|:count of them registered more than :days days ago.', $older, ['days' => $graceDays]) }}</p>
    @endif
    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('Staff accounts that may order count too. As of now.') }}</p>
</x-dashboard.card>
