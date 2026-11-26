@php
    $user = auth()->user();
    $first = $user->first_name ?: $user->name;

    // The places this user may open, with a line on what each is for. Each is
    // behind the same permission its controller checks, so no tile 403s.
    $tiles = collect([
        ['permission' => 'view menu', 'route' => 'menu.index', 'title' => __('Order Menu'), 'text' => __('Browse the live menu and place an order.')],
        ['permission' => 'view my orders', 'route' => 'my-orders.index', 'title' => __('My Orders'), 'text' => __('Follow what you ordered and how you paid.')],
        ['permission' => 'view pre-order-menu', 'route' => 'pre-order-menu.index', 'title' => __('Pre-Orders'), 'text' => __('Order big quantities for a party or an event.')],
        ['permission' => 'view my pre-orders', 'route' => 'my-pre-orders.index', 'title' => __('My Pre-Orders'), 'text' => __('Follow your pre-orders and how you paid.')],
        ['permission' => 'view order-sessions', 'route' => 'order-sessions.index', 'title' => __('Order Sessions'), 'text' => __('Open a selling window, set its stock, see its orders.')],
        ['permission' => 'view pre-order-sessions', 'route' => 'pre-order-sessions.index', 'title' => __('Pre-Order Sessions'), 'text' => __('Open a window for big orders, with a minimum number of plates.')],
        ['permission' => 'view orders', 'route' => 'orders.index', 'title' => __('Orders'), 'text' => __('Every order of every session: details, complete or cancel.')],
        ['permission' => 'view payments', 'route' => 'payments.index', 'title' => __('Payments'), 'text' => __('Check proofs, verify or reject payments.')],
        ['permission' => 'view dishes', 'route' => 'dishes.index', 'title' => __('Dish Masterlist'), 'text' => __('Dishes with their photos and prices, and the types they are sorted by.')],
        ['permission' => 'view payment-methods', 'route' => 'payment-methods.index', 'title' => __('Payment Methods'), 'text' => __('How customers can pay, with account details and QR.')],
        ['permission' => 'view users', 'route' => 'users.index', 'title' => __('Users'), 'text' => __('Accounts and the roles they hold.')],
        ['permission' => 'view settings', 'route' => 'settings.index', 'title' => __('System Configuration'), 'text' => __('Defaults the app starts from, like the order session times.')],
        ['permission' => 'view roles', 'route' => 'roles.index', 'title' => __('Roles'), 'text' => __('Who may do what.')],
    ])->filter(fn (array $tile) => $user->can($tile['permission']))->values();

    // Analytics has no single permission: any widget the person may see opens the page.
    if (\App\Support\Analytics::tabsFor($user) !== []) {
        $tiles->push(['route' => 'analytics.index', 'title' => __('Analytics'), 'text' => __('Sales, money and kitchen trends over any date range.')]);
    }

    // Is any widget of this section drawn? (See config/dashboard.php.)
    $inSection = fn (string $section) => collect($widgets)->contains('section', $section);

    // The sections with something to draw. Two or more become tabs; a single one keeps its heading.
    $sections = collect(['counter' => __('At the counter'), 'mine' => __('For you'), 'checks' => __('Checks')])
        ->filter(fn (string $label, string $key) => $inSection($key));
    $tabbed = $sections->count() > 1;
    $firstTab = $sections->keys()->first();
    $alerts = \App\Support\Dashboard::sectionsNeedingALook($widgets);

    // What makes a section a tab's panel: nothing when there are no tabs. All but the first start
    // cloaked, so they do not show before Alpine runs.
    $panel = fn (string $key) => new \Illuminate\View\ComponentAttributeBag($tabbed ? [
        'id' => 'dashboard-panel-' . $key,
        'role' => 'tabpanel',
        'aria-labelledby' => 'dashboard-tab-' . $key,
        'x-show' => "tab === '" . $key . "'",
    ] + ($key === $firstTab ? [] : ['x-cloak' => true]) : []);

    // Full class names on purpose: a gradient colour theme only paints a literal `bg-accent`, so
    // the tabs switch these classes instead of using an `aria-selected:` variant.
    $tabOn = 'bg-accent text-accent-on shadow-sm';
    $tabOff = 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600';
@endphp

<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white">{{ __('Hello, :name', ['name' => $first]) }}</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ now()->translatedFormat('l, F j') }}</p>
        </div>
    </x-slot>

    {{-- From xl the "Jump to" links are a narrow column on the right; below that they follow the widgets. --}}
    <div class="mx-auto flex max-w-7xl flex-col gap-6 px-4 py-6 sm:px-6 lg:px-8 xl:flex-row xl:items-start">
        <div class="min-w-0 space-y-6 xl:flex-1">
            {{-- What is happening right now --}}
            <section class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div class="animate-fade-up rounded-2xl bg-hero p-6 text-white shadow-card dark:bg-gray-800 dark:ring-1 dark:ring-white/10 {{ isset($widgets['attention']) ? 'lg:col-span-2' : 'lg:col-span-3' }}">
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

                {{-- Widgets (config/dashboard.php): one @can each. $widgets holds only what this person may
                     see AND what has something to show, with its data; hence the isset beside each @can. --}}
                @can('view dashboard-attention')
                    @isset($widgets['attention'])
                        @include($widgets['attention']['view'], $widgets['attention']['data'])
                    @endisset
                @endcan
            </section>

            @can('view dashboard-insights')
                @isset($widgets['insights'])
                    <section>
                        @include($widgets['insights']['view'], $widgets['insights']['data'])
                    </section>
                @endisset
            @endcan

            {{-- The rest of the widgets, one tab per section. What is urgent (above) stays in sight on
                 every tab. Every panel is already on the page, so switching asks the server nothing; the
                 tab last opened is remembered in this browser. --}}
            @if ($sections->isNotEmpty())
                <div class="space-y-4"
                    @if ($tabbed)
                        x-data="{
                            tabs: {{ \Illuminate\Support\Js::from($sections->keys()->all()) }},
                            tab: {{ \Illuminate\Support\Js::from($firstTab) }},
                            init() {
                                try {
                                    const saved = localStorage.getItem('cucina.dashboard.tab');
                                    if (this.tabs.includes(saved)) this.tab = saved;
                                } catch (error) {}
                            },
                            show(key) {
                                this.tab = key;
                                try { localStorage.setItem('cucina.dashboard.tab', key); } catch (error) {}
                            },
                            go(index) {
                                this.show(this.tabs[(index + this.tabs.length) % this.tabs.length]);
                                this.$nextTick(() => document.getElementById('dashboard-tab-' + this.tab)?.focus());
                            },
                        }"
                    @endif>
                    @if ($tabbed)
                        <div role="tablist" aria-label="{{ __('Dashboard sections') }}" class="-m-1 flex gap-2 overflow-x-auto p-1"
                             x-on:keydown.arrow-right.prevent="go(tabs.indexOf(tab) + 1)"
                             x-on:keydown.arrow-left.prevent="go(tabs.indexOf(tab) - 1)"
                             x-on:keydown.home.prevent="go(0)"
                             x-on:keydown.end.prevent="go(tabs.length - 1)">
                            @foreach ($sections as $key => $label)
                                <button type="button" role="tab" id="dashboard-tab-{{ $key }}" aria-controls="dashboard-panel-{{ $key }}"
                                        aria-selected="{{ $loop->first ? 'true' : 'false' }}" tabindex="{{ $loop->first ? '0' : '-1' }}"
                                        x-bind:aria-selected="(tab === '{{ $key }}').toString()"
                                        x-bind:tabindex="tab === '{{ $key }}' ? 0 : -1"
                                        x-on:click="show('{{ $key }}')"
                                        class="inline-flex flex-shrink-0 items-center gap-2 whitespace-nowrap rounded-full px-4 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 {{ $loop->first ? $tabOn : $tabOff }}"
                                        x-bind:class="{ '{{ $tabOn }}': tab === '{{ $key }}', '{{ $tabOff }}': tab !== '{{ $key }}' }">
                                    {{ $label }}
                                    @if (in_array($key, $alerts, true))
                                        <span data-tab-alert class="h-2 w-2 rounded-full bg-red-500 ring-2 ring-white dark:ring-gray-900" aria-hidden="true"></span>
                                        <span class="sr-only">{{ __('(needs a look)') }}</span>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    @endif

                    {{-- Today at the counter. The @canany hides the whole section from someone with none of its
                         widgets; $inSection also hides the heading when none of theirs has anything to show. --}}
                    @canany(['view dashboard-today', 'view dashboard-live-session', 'view dashboard-cash-expected', 'view dashboard-unclaimed'])
                        @if ($inSection('counter'))
                            <section {{ $panel('counter') }}>
                                @unless ($tabbed)
                                    <h3 class="mb-3 text-sm font-semibold text-gray-500 dark:text-gray-400">{{ __('At the counter') }}</h3>
                                @endunless

                                <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-3">
                                    @can('view dashboard-today')
                                        @isset($widgets['today'])
                                            @include($widgets['today']['view'], $widgets['today']['data'])
                                        @endisset
                                    @endcan
                                    @can('view dashboard-live-session')
                                        @isset($widgets['live-session'])
                                            @include($widgets['live-session']['view'], $widgets['live-session']['data'])
                                        @endisset
                                    @endcan
                                    {{-- The two short cards: one column beside the tall stock list, or a row of
                                         their own when no session is running. --}}
                                    @canany(['view dashboard-cash-expected', 'view dashboard-unclaimed'])
                                        <div class="{{ isset($widgets['live-session']) ? 'min-w-0 space-y-4' : 'grid grid-cols-1 items-start gap-4 lg:col-span-3 lg:grid-cols-3' }}">
                                            @can('view dashboard-cash-expected')
                                                @isset($widgets['cash-expected'])
                                                    @include($widgets['cash-expected']['view'], $widgets['cash-expected']['data'])
                                                @endisset
                                            @endcan
                                            @can('view dashboard-unclaimed')
                                                @isset($widgets['unclaimed'])
                                                    @include($widgets['unclaimed']['view'], $widgets['unclaimed']['data'])
                                                @endisset
                                            @endcan
                                        </div>
                                    @endcanany
                                </div>
                            </section>
                        @endif
                    @endcanany

                    {{-- The signed-in person's own --}}
                    @canany(['view dashboard-my-orders', 'view dashboard-my-pickup', 'view dashboard-my-usual', 'view dashboard-my-favorites', 'view dashboard-my-spending', 'view dashboard-popular-dishes', 'view dashboard-my-sellout-alert', 'view dashboard-my-new-dishes', 'view dashboard-my-discounts'])
                        @if ($inSection('mine'))
                            <section {{ $panel('mine') }}>
                                @unless ($tabbed)
                                    <h3 class="mb-3 text-sm font-semibold text-gray-500 dark:text-gray-400">{{ __('For you') }}</h3>
                                @endunless

                                <div class="grid grid-cols-1 items-start gap-4 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-2 2xl:grid-cols-3">
                                    @can('view dashboard-my-orders')
                                        @isset($widgets['my-orders'])
                                            @include($widgets['my-orders']['view'], $widgets['my-orders']['data'])
                                        @endisset
                                    @endcan
                                    @can('view dashboard-my-pickup')
                                        @isset($widgets['my-pickup'])
                                            @include($widgets['my-pickup']['view'], $widgets['my-pickup']['data'])
                                        @endisset
                                    @endcan
                                    @can('view dashboard-my-usual')
                                        @isset($widgets['my-usual'])
                                            @include($widgets['my-usual']['view'], $widgets['my-usual']['data'])
                                        @endisset
                                    @endcan
                                    @can('view dashboard-my-favorites')
                                        @isset($widgets['my-favorites'])
                                            @include($widgets['my-favorites']['view'], $widgets['my-favorites']['data'])
                                        @endisset
                                    @endcan
                                    @can('view dashboard-my-spending')
                                        @isset($widgets['my-spending'])
                                            @include($widgets['my-spending']['view'], $widgets['my-spending']['data'])
                                        @endisset
                                    @endcan
                                    @can('view dashboard-popular-dishes')
                                        @isset($widgets['popular-dishes'])
                                            @include($widgets['popular-dishes']['view'], $widgets['popular-dishes']['data'])
                                        @endisset
                                    @endcan
                                    @can('view dashboard-my-sellout-alert')
                                        @isset($widgets['my-sellout-alert'])
                                            @include($widgets['my-sellout-alert']['view'], $widgets['my-sellout-alert']['data'])
                                        @endisset
                                    @endcan
                                    @can('view dashboard-my-new-dishes')
                                        @isset($widgets['my-new-dishes'])
                                            @include($widgets['my-new-dishes']['view'], $widgets['my-new-dishes']['data'])
                                        @endisset
                                    @endcan
                                    @can('view dashboard-my-discounts')
                                        @isset($widgets['my-discounts'])
                                            @include($widgets['my-discounts']['view'], $widgets['my-discounts']['data'])
                                        @endisset
                                    @endcan
                                </div>
                            </section>
                        @endif
                    @endcanany

                    {{-- Trouble caught early --}}
                    @canany(['view dashboard-system-health', 'view dashboard-security', 'view dashboard-setup-checklist'])
                        @if ($inSection('checks'))
                            <section {{ $panel('checks') }}>
                                @unless ($tabbed)
                                    <h3 class="mb-3 text-sm font-semibold text-gray-500 dark:text-gray-400">{{ __('Checks') }}</h3>
                                @endunless

                                <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-3">
                                    @can('view dashboard-system-health')
                                        @isset($widgets['system-health'])
                                            @include($widgets['system-health']['view'], $widgets['system-health']['data'])
                                        @endisset
                                    @endcan
                                    @can('view dashboard-security')
                                        @isset($widgets['security'])
                                            @include($widgets['security']['view'], $widgets['security']['data'])
                                        @endisset
                                    @endcan
                                    @can('view dashboard-setup-checklist')
                                        @isset($widgets['setup-checklist'])
                                            @include($widgets['setup-checklist']['view'], $widgets['setup-checklist']['data'])
                                        @endisset
                                    @endcan
                                </div>
                            </section>
                        @endif
                    @endcanany
                </div>
            @endif

            @if ($tiles->isEmpty())
                <section class="rounded-2xl bg-white p-8 text-center shadow-card dark:bg-gray-800">
                    <p class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Nothing to open yet') }}</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Ask an administrator to give your account a role.') }}</p>
                </section>
            @endif
        </div>

        {{-- Everywhere this user may go --}}
        @if ($tiles->isNotEmpty())
            <aside class="animate-fade-up rounded-2xl bg-white p-3 shadow-card dark:bg-gray-800 dark:ring-1 dark:ring-white/5 xl:sticky xl:top-20 xl:max-h-[calc(100vh-6rem)] xl:w-52 xl:flex-shrink-0 xl:overflow-y-auto">
                <h3 class="px-2 py-1 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Jump to') }}</h3>

                <nav data-soft-nav aria-label="{{ __('Jump to') }}" class="mt-1 grid grid-cols-2 gap-x-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-1">
                    @foreach ($tiles as $tile)
                        <a href="{{ route($tile['route']) }}" title="{{ $tile['text'] }}"
                           class="group flex items-center justify-between gap-2 rounded-lg px-2 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-100 hover:text-gray-900 dark:text-gray-200 dark:hover:bg-gray-700 dark:hover:text-white">
                            <span class="min-w-0 truncate">{{ $tile['title'] }}</span>
                            <svg class="h-3.5 w-3.5 flex-shrink-0 text-gray-400 transition group-hover:text-gray-900 dark:group-hover:text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17 17 7M8 7h9v9" /></svg>
                        </a>
                    @endforeach
                </nav>
            </aside>
        @endif
    </div>
</x-app-layout>
