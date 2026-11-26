{{-- Dashboard widget `system-health`: the queue that delivers notifications. Counts only. --}}
<x-dashboard.card :title="__('System health')">
    @if ($mode === 'sync')
        <p class="mt-3 text-sm font-semibold text-gray-900 dark:text-white">{{ __('The queue runs inside each request.') }}</p>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __('People wait while notifications are sent. Use the database queue with a worker on a live site.') }}</p>
    @elseif ($mode === 'untracked')
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ __('This queue type is not checked here.') }}</p>
    @elseif ($mode === 'unknown')
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ __('The queue could not be read. See the application log.') }}</p>
    @else
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Checked within the last minute.') }}</p>
        @if ($failed === 0 && $stuck === 0)
            <p class="mt-3 text-sm font-semibold text-gray-900 dark:text-white">{{ __('No failed or stuck jobs.') }}</p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('An empty queue does not prove that the worker is running.') }}</p>
        @endif
        @if ($stuck > 0)
            <p class="mt-3"><x-dashboard.pill status="rejected">{{ trans_choice(':count job is waiting too long|:count jobs are waiting too long', $stuck) }}</x-dashboard.pill></p>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __('The oldest has waited :minutes minutes. Check that the queue worker is running, or notifications are not delivered.', ['minutes' => $oldestMinutes]) }}</p>
        @endif
        @if ($failed > 0)
            <p class="mt-3"><x-dashboard.pill status="pending">{{ trans_choice(':count failed job|:count failed jobs', $failed) }}</x-dashboard.pill></p>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __('Ask whoever runs the server to run: php artisan queue:failed') }}</p>
        @endif
    @endif
</x-dashboard.card>
