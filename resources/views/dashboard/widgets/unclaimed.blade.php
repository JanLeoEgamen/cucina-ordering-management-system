{{-- Dashboard widget `unclaimed`: announced as ready and not handed over, longest waiting first. --}}
<x-dashboard.card :title="__('Ready, not picked up')">
    <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
        @foreach ($orders as $order)
            <li class="py-2">
                <a href="{{ $order['url'] }}" class="block text-sm hover:underline">
                    <span class="flex items-baseline justify-between gap-3">
                        <span class="min-w-0 truncate font-semibold text-gray-900 dark:text-white">{{ $order['customer'] }}</span>
                        <span class="flex-shrink-0 text-xs text-gray-500 dark:text-gray-400">{{ __('announced :ago', ['ago' => $order['waiting']]) }}</span>
                    </span>
                    <span class="block text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ $order['number'] }}</span>
                </a>
            </li>
        @endforeach
    </ul>

    @if ($total > count($orders))
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('And :count more.', ['count' => $total - count($orders)]) }}</p>
    @endif
</x-dashboard.card>
