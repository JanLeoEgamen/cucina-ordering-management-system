{{-- Dashboard widget `security`: sign in trouble of the last 24 hours, counts only. --}}
<x-dashboard.card :title="__('Sign-in security')">
    @if (! $enabled)
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ __('The audit trail is switched off, so nothing is recorded.') }}</p>
    @else
        @if ($failed === 0 && $lockouts === 0)
            <p class="mt-3 text-sm font-semibold text-gray-900 dark:text-white">{{ __('No failed sign-ins in the last 24 hours.') }}</p>
        @else
            <p class="mt-3 text-3xl font-bold text-gray-900 dark:text-white">{{ $failed }}</p>
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ trans_choice('failed sign-in in the last 24 hours|failed sign-ins in the last 24 hours', $failed) }}</p>
            @if ($lockouts > 0)
                <p class="mt-2"><x-dashboard.pill status="rejected">{{ trans_choice(':count lockout|:count lockouts', $lockouts) }}</x-dashboard.pill></p>
            @endif
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ trans_choice('From :count network address.|From :count network addresses.', $addresses) }}</p>
        @endif
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Checked within the last minute.') }}</p>
        <a href="{{ $url }}" class="mt-2 inline-block text-sm font-semibold text-gray-900 hover:underline dark:text-white">{{ __('Open the audit trail') }}</a>
    @endif
</x-dashboard.card>
