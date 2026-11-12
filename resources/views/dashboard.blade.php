@php
    $user = auth()->user();
    $first = $user->first_name ?: $user->name;

    // The places this user may open, with a line on what each is for. Each is
    // behind the same permission its controller checks, so no tile 403s.
    $tiles = collect([
        ['permission' => 'view menu', 'route' => 'menu.index', 'title' => __('Order Menu'), 'text' => __('Browse the live menu and place an order.')],
        ['permission' => 'view my orders', 'route' => 'my-orders.index', 'title' => __('My Orders'), 'text' => __('Follow what you ordered and how you paid.')],
        ['permission' => 'view order-sessions', 'route' => 'order-sessions.index', 'title' => __('Order Sessions'), 'text' => __('Open a selling window, set its stock, see its orders.')],
        ['permission' => 'view orders', 'route' => 'orders.index', 'title' => __('Orders'), 'text' => __('Every order of every session: details, complete or cancel.')],
        ['permission' => 'view payments', 'route' => 'payments.index', 'title' => __('Payments'), 'text' => __('Check proofs, verify or reject payments.')],
        ['permission' => 'view dishes', 'route' => 'dishes.index', 'title' => __('Dish Masterlist'), 'text' => __('Dishes with their photos and prices, and the types they are sorted by.')],
        ['permission' => 'view payment-methods', 'route' => 'payment-methods.index', 'title' => __('Payment Methods'), 'text' => __('How customers can pay, with account details and QR.')],
        ['permission' => 'view users', 'route' => 'users.index', 'title' => __('Users'), 'text' => __('Accounts and the roles they hold.')],
        ['permission' => 'view settings', 'route' => 'settings.index', 'title' => __('System Configuration'), 'text' => __('Defaults the app starts from, like the order session times.')],
        ['permission' => 'view roles', 'route' => 'roles.index', 'title' => __('Roles'), 'text' => __('Who may do what.')],
    ])->filter(fn (array $tile) => $user->can($tile['permission']))->values();
@endphp

<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white">{{ __('Hello, :name', ['name' => $first]) }}</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ now()->translatedFormat('l, F j') }}</p>
        </div>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
        {{-- What is happening right now --}}
        <section class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div class="animate-fade-up rounded-2xl bg-ink p-6 text-white shadow-card dark:bg-gray-800 dark:ring-1 dark:ring-white/10 {{ $pendingPayments !== null ? 'lg:col-span-2' : 'lg:col-span-3' }}">
                <p class="text-xs font-semibold uppercase tracking-wider text-white/60">{{ __('Order session') }}</p>

                @if ($live)
                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <span data-status="live" class="inline-flex items-center rounded-full bg-white/10 px-2.5 py-0.5 text-xs font-semibold text-white">{{ __('Live') }}</span>
                        <p class="text-2xl font-bold tabular-nums">{{ substr($live->start_time, 0, 5) }} – {{ substr($live->end_time, 0, 5) }}</p>
                    </div>
                    <p class="mt-2 text-sm text-white/70">
                        {{ \Illuminate\Support\Carbon::parse($live->order_date)->format('M j, Y') }}
                        · {{ trans_choice(':count dish|:count dishes', (int) $live->dishes_count) }}
                        · {{ trans_choice(':count order|:count orders', (int) $live->orders_count) }}
                    </p>

                    <div class="mt-5 flex flex-wrap gap-2">
                        @can('view menu')
                            <a href="{{ route('menu.index') }}" class="inline-flex items-center rounded-full bg-mist px-5 py-2.5 text-sm font-semibold text-ink transition hover:bg-white">{{ __('Open the menu') }}</a>
                        @endcan
                        @can('view order-sessions')
                            <a href="{{ route('order-sessions.show', $live->session_id) }}" class="inline-flex items-center rounded-full border border-white/25 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-white/10">{{ __('View session') }}</a>
                        @endcan
                    </div>
                @else
                    <p class="mt-3 text-2xl font-bold">{{ __('No session is running') }}</p>
                    <p class="mt-2 text-sm text-white/70">{{ __('The menu opens when an order session starts.') }}</p>

                    @can('view order-sessions')
                        <a href="{{ route('order-sessions.index') }}" class="mt-5 inline-flex items-center rounded-full bg-mist px-5 py-2.5 text-sm font-semibold text-ink transition hover:bg-white">{{ __('Plan a session') }}</a>
                    @endcan
                @endif
            </div>

            @if ($pendingPayments !== null)
                <a href="{{ route('payments.index') }}"
                   class="group animate-fade-up rounded-2xl bg-white p-6 shadow-card transition duration-200 hover:-translate-y-0.5 hover:shadow-card-lift dark:bg-gray-800 dark:ring-1 dark:ring-white/5"
                   style="animation-delay: 60ms">
                    <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Waiting for a decision') }}</p>
                    <p class="mt-3 text-5xl font-bold tabular-nums text-gray-900 dark:text-white">{{ $pendingPayments }}</p>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ trans_choice('pending payment|pending payments', $pendingPayments) }}</p>
                    <span class="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-gray-900 dark:text-white">
                        {{ __('Review payments') }}
                        <svg class="h-4 w-4 transition-transform duration-200 group-hover:translate-x-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6" /></svg>
                    </span>
                </a>
            @endif
        </section>

        {{-- Everywhere this user may go --}}
        @if ($tiles->isNotEmpty())
            <section>
                <h3 class="mb-3 text-sm font-semibold text-gray-500 dark:text-gray-400">{{ __('Jump to') }}</h3>

                <div class="grid grid-cols-1 gap-4 min-[520px]:grid-cols-2 lg:grid-cols-3">
                    @foreach ($tiles as $tile)
                        <a href="{{ route($tile['route']) }}"
                           class="group animate-fade-up flex items-start justify-between gap-3 rounded-2xl bg-white p-5 shadow-card transition duration-200 hover:-translate-y-0.5 hover:shadow-card-lift dark:bg-gray-800 dark:ring-1 dark:ring-white/5"
                           style="animation-delay: {{ 100 + min($loop->index, 8) * 40 }}ms">
                            <span>
                                <span class="block text-base font-bold text-gray-900 dark:text-white">{{ $tile['title'] }}</span>
                                <span class="mt-1 block text-sm leading-snug text-gray-500 dark:text-gray-400">{{ $tile['text'] }}</span>
                            </span>
                            <span class="mt-0.5 flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full bg-gray-100 text-gray-700 transition duration-200 group-hover:bg-gray-700 group-hover:text-white dark:bg-gray-700 dark:text-gray-200 dark:group-hover:bg-mist dark:group-hover:text-ink">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17 17 7M8 7h9v9" /></svg>
                            </span>
                        </a>
                    @endforeach
                </div>
            </section>
        @else
            <section class="rounded-2xl bg-white p-8 text-center shadow-card dark:bg-gray-800">
                <p class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Nothing to open yet') }}</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Ask an administrator to give your account a role.') }}</p>
            </section>
        @endif
    </div>
</x-app-layout>
