{{-- Analytics widget `rice`: plates ordered with and without rice. --}}
<x-dashboard.card :csv="$csv ?? null" :title="__('Rice')">
    @if ($with + $without === 0)
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ __('No plate of a dish that comes with rice was ordered in this range.') }}</p>
    @else
        <dl class="mt-3 grid grid-cols-2 gap-3">
            <div>
                <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('With rice') }}</dt>
                <dd class="text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($with) }}</dd>
            </div>
            <div>
                <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('Without rice') }}</dt>
                <dd class="text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($without) }}</dd>
                <dd class="text-xs text-gray-500 dark:text-gray-400">{{ $rate }}%</dd>
            </div>
        </dl>

        @if ($perSession !== null)
            <p class="mt-3 text-sm text-gray-700 dark:text-gray-200">{{ __('About :count rice servings per session, on average over :sessions.', ['count' => $perSession, 'sessions' => $sessions]) }}</p>
        @endif

        @if ($dishes)
            <table class="analytics-table mt-3">
                <thead><tr><th scope="col">{{ __('Often without rice') }}</th><th scope="col">{{ __('Without') }}</th><th scope="col">%</th></tr></thead>
                <tbody>
                    @foreach ($dishes as $dish)
                        <tr><td>{{ $dish['dish_name'] }}</td><td>{{ $dish['without'] }}</td><td>{{ $dish['rate'] }}%</td></tr>
                    @endforeach
                </tbody>
            </table>
        @endif
        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('Counts the dishes marked as coming with rice today, or ordered without it in this range.') }}</p>
    @endif
</x-dashboard.card>
