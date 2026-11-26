{{-- Dashboard widget `my-orders`: the signed-in person's open orders, the ones needing them first. --}}
<x-dashboard.card :title="__('Your active orders')">
    @forelse ($orders as $order)
        <a href="{{ $order['url'] }}" class="mt-3 block rounded-xl border border-gray-100 p-3 transition hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-700/40">
            <span class="flex flex-wrap items-center justify-between gap-2">
                <x-dashboard.pill :status="$order['status']">{{ $order['label'] }}</x-dashboard.pill>
                <span class="text-sm font-semibold tabular-nums text-gray-900 dark:text-white">₱{{ number_format($order['total'], 2) }}</span>
            </span>
            <span class="mt-1.5 block text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ $order['number'] }}</span>
            @if ($order['hint'] !== '')
                <span class="mt-0.5 block text-sm text-gray-600 dark:text-gray-300">{{ $order['hint'] }}</span>
            @endif
        </a>
    @empty
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ __('You have no active orders.') }}</p>
        @can('view menu')
            <a href="{{ route('menu.index') }}" class="mt-3 inline-flex items-center rounded-full bg-accent px-4 py-2 text-sm font-semibold text-accent-on transition hover:bg-accent-hover">{{ __('Order now') }}</a>
        @endcan
    @endforelse

    @if ($more > 0)
        <a href="{{ route('my-orders.index') }}" class="mt-3 inline-block text-sm font-semibold text-gray-900 hover:underline dark:text-white">{{ __('And :count more.', ['count' => $more]) }}</a>
    @endif
</x-dashboard.card>
