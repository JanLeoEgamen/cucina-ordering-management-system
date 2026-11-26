{{-- Analytics widget `duplicate-references`: a payment reference number on more than one order. --}}
<x-dashboard.card :csv="$csv ?? null" :title="__('Repeated reference numbers')" class="lg:col-span-3">
    <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ __('A reason to look at the proofs, not proof of fraud: a typing mistake repeats a number too.') }}</p>

    @forelse ($groups as $group)
        <div class="mt-3 rounded-xl border border-gray-100 p-3 dark:border-gray-700">
            <p class="flex flex-wrap items-center gap-2 text-sm font-semibold tabular-nums text-gray-900 dark:text-white">
                <span class="break-all">{{ $group['reference'] }}</span>
                @if ($group['customers'] > 1)
                    <x-dashboard.pill status="rejected">{{ __(':count different customers', ['count' => $group['customers']]) }}</x-dashboard.pill>
                @else
                    <x-dashboard.pill status="pending">{{ __('Same customer') }}</x-dashboard.pill>
                @endif
            </p>
            <div class="mt-1 overflow-x-auto">
                <table class="analytics-table">
                    <thead><tr><th scope="col">{{ __('Order') }}</th><th scope="col" class="is-text">{{ __('Customer') }}</th><th scope="col" class="is-text">{{ __('Placed') }}</th><th scope="col" class="is-text">{{ __('Method') }}</th><th scope="col">{{ __('Amount') }}</th><th scope="col">{{ __('Payment') }}</th></tr></thead>
                    <tbody>
                        @foreach ($group['orders'] as $order)
                            <tr>
                                <td class="whitespace-nowrap">{{ $order['number'] }}{{ $order['cancelled'] ? ' (' . __('cancelled') . ')' : '' }}</td>
                                <td class="is-text">{{ $order['customer'] }}</td>
                                <td class="is-text whitespace-nowrap">{{ $order['date'] }}</td>
                                <td class="is-text">{{ $order['method'] }}</td>
                                <td>₱{{ number_format($order['amount'], 2) }}</td>
                                <td>{{ __(ucfirst($order['payment'])) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @empty
        <p class="mt-3 text-sm font-semibold text-gray-900 dark:text-white">{{ __('No repeated reference number found for orders in this range.') }}</p>
    @endforelse

    @if ($groups)
        <a href="{{ $ordersUrl }}" class="mt-3 inline-block text-sm font-semibold text-gray-900 hover:underline dark:text-white">{{ __('Search for the order number to see its proof') }}</a>
    @endif
    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
        {{ $more ? __('Only the newest repeats are listed; pick a shorter range to see the rest.') : '' }}
        {{ __('The other order can be of any age. Spaces and capitals are ignored. A number that was replaced when a customer sent a new proof is no longer known.') }}
    </p>
</x-dashboard.card>
