{{--
    Application navigation.

    Desktop (lg+): a fixed sidebar that collapses between 16rem and a 5rem icon
    rail. The collapsed look is driven by `html.cucina-sidebar-collapsed` (set
    from localStorage before paint, see layouts/app.blade.php), NOT by Alpine
    bindings, so a stored collapsed state never flashes a wide sidebar. The
    label hiding / centring rules live in resources/css/app.css.

    Mobile: an off-canvas drawer toggled by the top bar hamburger.

    The collapse toggle is a handle on the sidebar's right edge, halfway down, half of
    it outside, so the <aside> itself must NOT clip (`overflow-hidden` on it would cut
    the handle). Everything else sits inside one clipping wrapper instead, so labels
    cannot spill during the width animation.

    This partial uses the Alpine root declared in layouts/app.blade.php (it
    defines `open` and `collapsed`). Alpine's initial walk only enters elements
    matching `[x-data]`/`[x-init]`, so `x-on:*` handlers declared without an
    `x-data` ancestor would never be registered.
--}}
<header class="fixed inset-x-0 top-0 z-30 flex h-16 items-center gap-3 border-b border-gray-200 bg-white/90 px-4 backdrop-blur dark:border-gray-700 dark:bg-gray-900/90 lg:hidden">
    <button type="button"
            @click="open = ! open"
            :aria-expanded="open ? 'true' : 'false'"
            aria-controls="app-sidebar"
            class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-gray-200 text-gray-500 transition hover:bg-gray-100 dark:border-gray-600 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white">
        <span class="sr-only">{{ __('Toggle navigation') }}</span>
        <svg x-show="! open" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
        </svg>
        <svg x-show="open" x-cloak class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
    </button>

    <a href="{{ route('dashboard') }}" class="flex items-center gap-2">
        <x-application-logo class="block h-8 w-auto fill-current text-gray-800 dark:text-white" />
        <span class="font-semibold tracking-tight text-gray-900 dark:text-white">Cucina</span>
    </a>
    {{-- Theme switch, notification bell and account menu: one component, also used by the
         desktop bar in layouts/app.blade.php (only one of the two is visible). --}}
    <div class="ml-auto">
        <x-ui.account-bar id="phone" />
    </div>
</header>

{{-- Mobile backdrop --}}
<div x-show="open"
     x-cloak
     x-transition.opacity.duration.200ms
     @click="open = false"
     aria-hidden="true"
     class="fixed inset-0 z-40 bg-gray-900/60 lg:hidden"></div>

{{--
    Below lg the sidebar is an off-canvas drawer: the resting state is
    `-translate-x-full` and `lg:translate-x-0` pins it open on desktop, so it is
    already correct before Alpine boots (no flash). `!translate-x-0` beats the
    resting class while the mobile drawer is open.
--}}
<aside id="app-sidebar"
       aria-label="{{ __('Main navigation') }}"
       class="fixed inset-y-0 left-0 z-50 h-screen w-64 -translate-x-full bg-gray-50 p-3 transition-[width,transform] duration-300 ease-in-out dark:bg-gray-900 lg:translate-x-0"
       :class="{ '!translate-x-0': open }">

    {{--
        Collapse / expand handle: on the sidebar's right edge, halfway down, half of
        it outside, expanded or in the icon rail. Centred with
        a negative margin, not a transform: the press effect in app.css sets
        `transform` on pressed buttons and would otherwise make it jump. Desktop only
        (a phone has the top bar button and the close button).
    --}}
    <button type="button"
            id="sidebar-toggle"
            @click="toggleSidebar()"
            :aria-expanded="(! collapsed) ? 'true' : 'false'"
            :title="collapsed ? '{{ __('Expand sidebar') }}' : '{{ __('Collapse sidebar') }}'"
            class="absolute -right-[calc(1.125rem+0.5px)] top-1/2 z-20 -mt-[1.125rem] hidden h-9 w-9 items-center justify-center rounded-full border border-gray-200 bg-white text-gray-500 shadow-card transition-colors hover:border-gray-600 hover:bg-gray-700 hover:text-white focus:outline-none ring-4 ring-gray-200 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:border-mist dark:hover:bg-mist dark:hover:text-ink dark:ring-gray-700 lg:flex">
        <span class="sr-only">{{ __('Toggle sidebar') }}</span>
        <svg class="sidebar-toggle-icon h-5 w-5 transition-transform duration-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
        </svg>
    </button>

    {{-- Clipping wrapper: keeps labels from spilling during the width animation. --}}
    <div class="flex h-full min-h-0 flex-col overflow-hidden">
        {{-- Brand --}}
        <div class="sidebar-brand flex h-14 flex-shrink-0 items-center gap-2 px-3">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-2">
                <x-application-logo class="block h-8 w-auto flex-shrink-0 fill-current text-gray-800 dark:text-white" />
                <span class="sidebar-label font-semibold tracking-tight text-gray-900 dark:text-white">Cucina</span>
            </a>
            <button type="button"
                    @click="open = false"
                    class="ml-auto rounded-full border border-gray-200 p-2.5 text-gray-500 transition hover:bg-gray-100 dark:border-gray-600 hover:text-gray-900 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white lg:hidden">
                <span class="sr-only">{{ __('Close navigation') }}</span>
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        {{-- Who is signed in is NOT shown here: the account menu in the top right corner
             (components/ui/account-bar) carries the name, email and role, once. --}}

        {{-- Links, in one white rounded panel --}}
        <div class="mt-1 flex min-h-0 flex-1 flex-col rounded-2xl bg-white shadow-card dark:bg-gray-800">
        <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4" aria-label="{{ __('Main navigation') }}">
            <a href="{{ route('dashboard') }}"
               @click="open = false"
               title="{{ __('Dashboard') }}"
               @if (request()->routeIs('dashboard')) aria-current="page" @endif
               class="sidebar-link flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('dashboard') ? 'bg-gray-900 text-white shadow-sm dark:bg-mist dark:text-ink' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white' }}">
                <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 011 1m-6 0h6" /></svg>
                <span class="sidebar-label">{{ __('Dashboard') }}</span>
            </a>

            {{-- Ordering --}}
            @canany(['view menu', 'view my orders', 'view bulk-menu', 'view my bulk-orders'])
                <div class="space-y-1 pt-3" data-nav-group="ordering" @if (request()->routeIs('menu.*', 'my-orders.*', 'bulk-menu.*', 'bulk-my-orders.*')) data-has-active @endif>
                    {{-- The title is a button: it folds the group (state: html[data-nav-closed], kept in
                         localStorage, see layouts/app.blade.php). The icon rail has no room for it, so a
                         thin rule marks the group there and its links always show. --}}
                    <button type="button" @click="toggleGroup('ordering')" aria-expanded="true" aria-controls="nav-group-ordering"
                            class="sidebar-label group flex w-full items-center justify-between rounded-lg px-3 pb-1 pt-1 text-left text-[11px] font-semibold uppercase tracking-wider text-gray-400 transition hover:text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-300 dark:text-gray-500 dark:hover:text-gray-200">
                        <span>{{ __('Ordering') }}</span>
                        <svg class="nav-chevron h-3.5 w-3.5 transition-transform duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m19 9-7 7-7-7" /></svg>
                    </button>
                    <hr class="sidebar-rule mx-3 hidden border-gray-100 dark:border-gray-700">
                    <div id="nav-group-ordering" class="nav-group-items space-y-1">
                @can('view menu')
                    <a href="{{ route('menu.index') }}"
                       @click="open = false"
                       title="{{ __('Menu') }}"
                       @if (request()->routeIs('menu.*')) aria-current="page" @endif
                       class="sidebar-link flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('menu.*') ? 'bg-gray-900 text-white shadow-sm dark:bg-mist dark:text-ink' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white' }}">
                        <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" /></svg>
                        <span class="sidebar-label">{{ __('Menu') }}</span>
                    </a>
                @endcan

                @can('view my orders')
                    <a href="{{ route('my-orders.index') }}"
                       @click="open = false"
                       title="{{ __('My Orders') }}"
                       @if (request()->routeIs('my-orders.*')) aria-current="page" @endif
                       class="sidebar-link flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('my-orders.*') ? 'bg-gray-900 text-white shadow-sm dark:bg-mist dark:text-ink' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white' }}">
                        <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" /></svg>
                        <span class="sidebar-label">{{ __('My Orders') }}</span>
                    </a>
                @endcan

                @can('view bulk-menu')
                    <a href="{{ route('bulk-menu.index') }}"
                       @click="open = false"
                       title="{{ __('Bulk Orders') }}"
                       @if (request()->routeIs('bulk-menu.*')) aria-current="page" @endif
                       class="sidebar-link flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('bulk-menu.*') ? 'bg-gray-900 text-white shadow-sm dark:bg-mist dark:text-ink' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white' }}">
                        <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" /></svg>
                        <span class="sidebar-label">{{ __('Bulk Orders') }}</span>
                    </a>
                @endcan

                @can('view my bulk-orders')
                    <a href="{{ route('bulk-my-orders.index') }}"
                       @click="open = false"
                       title="{{ __('My Bulk Orders') }}"
                       @if (request()->routeIs('bulk-my-orders.*')) aria-current="page" @endif
                       class="sidebar-link flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('bulk-my-orders.*') ? 'bg-gray-900 text-white shadow-sm dark:bg-mist dark:text-ink' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white' }}">
                        <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                        <span class="sidebar-label">{{ __('My Bulk Orders') }}</span>
                    </a>
                @endcan
                    </div>
                </div>
            @endcanany

            {{-- Service --}}
            @canany(['view order-sessions', 'view bulk-sessions', 'view orders', 'view payments'])
                <div class="space-y-1 pt-3" data-nav-group="service" @if (request()->routeIs('order-sessions.*', 'bulk-sessions.*', 'orders.index', 'payments.*')) data-has-active @endif>
                    {{-- The title is a button: it folds the group (state: html[data-nav-closed], kept in
                         localStorage, see layouts/app.blade.php). The icon rail has no room for it, so a
                         thin rule marks the group there and its links always show. --}}
                    <button type="button" @click="toggleGroup('service')" aria-expanded="true" aria-controls="nav-group-service"
                            class="sidebar-label group flex w-full items-center justify-between rounded-lg px-3 pb-1 pt-1 text-left text-[11px] font-semibold uppercase tracking-wider text-gray-400 transition hover:text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-300 dark:text-gray-500 dark:hover:text-gray-200">
                        <span>{{ __('Service') }}</span>
                        <svg class="nav-chevron h-3.5 w-3.5 transition-transform duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m19 9-7 7-7-7" /></svg>
                    </button>
                    <hr class="sidebar-rule mx-3 hidden border-gray-100 dark:border-gray-700">
                    <div id="nav-group-service" class="nav-group-items space-y-1">
                @can('view order-sessions')
                    <a href="{{ route('order-sessions.index') }}"
                       @click="open = false"
                       title="{{ __('Order Sessions') }}"
                       @if (request()->routeIs('order-sessions.*')) aria-current="page" @endif
                       class="sidebar-link flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('order-sessions.*') ? 'bg-gray-900 text-white shadow-sm dark:bg-mist dark:text-ink' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white' }}">
                        <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                        <span class="sidebar-label">{{ __('Order Sessions') }}</span>
                    </a>
                @endcan

                @can('view bulk-sessions')
                    <a href="{{ route('bulk-sessions.index') }}"
                       @click="open = false"
                       title="{{ __('Bulk Sessions') }}"
                       @if (request()->routeIs('bulk-sessions.*')) aria-current="page" @endif
                       class="sidebar-link flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('bulk-sessions.*') ? 'bg-gray-900 text-white shadow-sm dark:bg-mist dark:text-ink' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white' }}">
                        <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" /></svg>
                        <span class="sidebar-label">{{ __('Bulk Sessions') }}</span>
                    </a>
                @endcan

                @can('view orders')
                    <a href="{{ route('orders.index') }}"
                       @click="open = false"
                       title="{{ __('Orders') }}"
                       @if (request()->routeIs('orders.index')) aria-current="page" @endif
                       class="sidebar-link flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('orders.index') ? 'bg-gray-900 text-white shadow-sm dark:bg-mist dark:text-ink' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white' }}">
                        <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" /></svg>
                        <span class="sidebar-label">{{ __('Orders') }}</span>
                    </a>
                @endcan

                @can('view payments')
                    <a href="{{ route('payments.index') }}"
                       @click="open = false"
                       title="{{ __('Payments') }}"
                       @if (request()->routeIs('payments.*')) aria-current="page" @endif
                       class="sidebar-link flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('payments.*') ? 'bg-gray-900 text-white shadow-sm dark:bg-mist dark:text-ink' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white' }}">
                        <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
                        <span class="sidebar-label">{{ __('Payments') }}</span>
                    </a>
                @endcan
                    </div>
                </div>
            @endcanany

            {{-- Catalog --}}
            @canany(['view dishes', 'view payment-methods'])
                <div class="space-y-1 pt-3" data-nav-group="catalog" @if (request()->routeIs('dishes.*', 'payment-methods.*')) data-has-active @endif>
                    {{-- The title is a button: it folds the group (state: html[data-nav-closed], kept in
                         localStorage, see layouts/app.blade.php). The icon rail has no room for it, so a
                         thin rule marks the group there and its links always show. --}}
                    <button type="button" @click="toggleGroup('catalog')" aria-expanded="true" aria-controls="nav-group-catalog"
                            class="sidebar-label group flex w-full items-center justify-between rounded-lg px-3 pb-1 pt-1 text-left text-[11px] font-semibold uppercase tracking-wider text-gray-400 transition hover:text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-300 dark:text-gray-500 dark:hover:text-gray-200">
                        <span>{{ __('Catalog') }}</span>
                        <svg class="nav-chevron h-3.5 w-3.5 transition-transform duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m19 9-7 7-7-7" /></svg>
                    </button>
                    <hr class="sidebar-rule mx-3 hidden border-gray-100 dark:border-gray-700">
                    <div id="nav-group-catalog" class="nav-group-items space-y-1">
                @can('view dishes')
                    <a href="{{ route('dishes.index') }}"
                       @click="open = false"
                       title="{{ __('Dish Masterlist') }}"
                       @if (request()->routeIs('dishes.*')) aria-current="page" @endif
                       class="sidebar-link flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('dishes.*') ? 'bg-gray-900 text-white shadow-sm dark:bg-mist dark:text-ink' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white' }}">
                        <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" /></svg>
                        <span class="sidebar-label">{{ __('Dish Masterlist') }}</span>
                    </a>
                @endcan

                @can('view payment-methods')
                    <a href="{{ route('payment-methods.index') }}"
                       @click="open = false"
                       title="{{ __('Payment Methods') }}"
                       @if (request()->routeIs('payment-methods.*')) aria-current="page" @endif
                       class="sidebar-link flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('payment-methods.*') ? 'bg-gray-900 text-white shadow-sm dark:bg-mist dark:text-ink' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white' }}">
                        <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" /></svg>
                        <span class="sidebar-label">{{ __('Payment Methods') }}</span>
                    </a>
                @endcan
                    </div>
                </div>
            @endcanany

            {{-- Access --}}
            @canany(['view roles', 'view users', 'view settings'])
                <div class="space-y-1 pt-3" data-nav-group="access" @if (request()->routeIs('roles.*', 'users.*', 'settings.*')) data-has-active @endif>
                    {{-- The title is a button: it folds the group (state: html[data-nav-closed], kept in
                         localStorage, see layouts/app.blade.php). The icon rail has no room for it, so a
                         thin rule marks the group there and its links always show. --}}
                    <button type="button" @click="toggleGroup('access')" aria-expanded="true" aria-controls="nav-group-access"
                            class="sidebar-label group flex w-full items-center justify-between rounded-lg px-3 pb-1 pt-1 text-left text-[11px] font-semibold uppercase tracking-wider text-gray-400 transition hover:text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-300 dark:text-gray-500 dark:hover:text-gray-200">
                        <span>{{ __('Access') }}</span>
                        <svg class="nav-chevron h-3.5 w-3.5 transition-transform duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m19 9-7 7-7-7" /></svg>
                    </button>
                    <hr class="sidebar-rule mx-3 hidden border-gray-100 dark:border-gray-700">
                    <div id="nav-group-access" class="nav-group-items space-y-1">
                @can('view roles')
                    <a href="{{ route('roles.index') }}"
                       @click="open = false"
                       title="{{ __('Roles') }}"
                       @if (request()->routeIs('roles.*')) aria-current="page" @endif
                       class="sidebar-link flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('roles.*') ? 'bg-gray-900 text-white shadow-sm dark:bg-mist dark:text-ink' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white' }}">
                        <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" /></svg>
                        <span class="sidebar-label">{{ __('Roles') }}</span>
                    </a>
                @endcan

                @can('view users')
                    <a href="{{ route('users.index') }}"
                       @click="open = false"
                       title="{{ __('Users') }}"
                       @if (request()->routeIs('users.*')) aria-current="page" @endif
                       class="sidebar-link flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('users.*') ? 'bg-gray-900 text-white shadow-sm dark:bg-mist dark:text-ink' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white' }}">
                        <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" /></svg>
                        <span class="sidebar-label">{{ __('Users') }}</span>
                    </a>
                @endcan

                @can('view settings')
                    <a href="{{ route('settings.index') }}"
                       @click="open = false"
                       title="{{ __('System Configuration') }}"
                       @if (request()->routeIs('settings.*')) aria-current="page" @endif
                       class="sidebar-link flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('settings.*') ? 'bg-gray-900 text-white shadow-sm dark:bg-mist dark:text-ink' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white' }}">
                        <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                        <span class="sidebar-label">{{ __('System Configuration') }}</span>
                    </a>
                @endcan
                    </div>
                </div>
            @endcanany
        </nav>

        {{-- Runs right here, before the first paint: the group that holds the page you are on is
             always opened on arrival (a folded group would hide the highlighted link), without
             touching what is stored; then every header says whether its group is open. --}}
        <script>
            (function () {
                var root = document.documentElement;
                var closed = (root.getAttribute('data-nav-closed') || '').split(' ').filter(Boolean);

                document.querySelectorAll('[data-nav-group]').forEach(function (group) {
                    var key = group.getAttribute('data-nav-group');

                    if (group.hasAttribute('data-has-active')) {
                        closed = closed.filter(function (item) { return item !== key; });
                    }
                });

                if (closed.length) {
                    root.setAttribute('data-nav-closed', closed.join(' '));
                } else {
                    root.removeAttribute('data-nav-closed');
                }

                document.querySelectorAll('[data-nav-group]').forEach(function (group) {
                    var header = group.querySelector('button[aria-controls]');

                    if (header) {
                        header.setAttribute('aria-expanded', closed.indexOf(group.getAttribute('data-nav-group')) === -1 ? 'true' : 'false');
                    }
                });
            })();
        </script>

        </div>
    </div>
</aside>
