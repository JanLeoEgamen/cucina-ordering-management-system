{{--
    Application navigation.

    Desktop (lg+): a fixed sidebar that collapses between 16rem and a 5rem icon
    rail. The collapsed look is driven by `html.cucina-sidebar-collapsed` (set
    from localStorage before paint, see layouts/app.blade.php), NOT by Alpine
    bindings, so a stored collapsed state never flashes a wide sidebar. The
    label hiding / centring rules live in resources/css/app.css.

    Mobile: an off-canvas drawer toggled by the top bar hamburger.

    The collapse toggle deliberately overhangs the sidebar's right border, so the
    <aside> itself must NOT clip (`overflow-hidden` on it also clipped the
    button). Everything else sits inside one clipping wrapper instead.

    This partial uses the Alpine root declared in layouts/app.blade.php (it
    defines `open` and `collapsed`). Alpine's initial walk only enters elements
    matching `[x-data]`/`[x-init]`, so `x-on:*` handlers declared without an
    `x-data` ancestor would never be registered.
--}}
<header class="fixed inset-x-0 top-0 z-30 flex h-16 items-center gap-3 border-b border-gray-200 bg-white px-4 dark:border-gray-700 dark:bg-gray-800 lg:hidden">
    <button type="button"
            @click="open = ! open"
            :aria-expanded="open ? 'true' : 'false'"
            aria-controls="app-sidebar"
            class="inline-flex h-10 w-10 items-center justify-center rounded-lg text-gray-500 transition hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white">
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
       class="fixed inset-y-0 left-0 z-50 h-screen w-64 -translate-x-full border-r border-gray-200 bg-white transition-[width,transform] duration-300 ease-in-out dark:border-gray-700 dark:bg-gray-800 lg:translate-x-0"
       :class="{ '!translate-x-0': open }">

    {{-- Collapse toggle: straddles the sidebar border, desktop only. --}}
    <button type="button"
            id="sidebar-toggle"
            @click="toggleSidebar()"
            :aria-expanded="(! collapsed) ? 'true' : 'false'"
            :title="collapsed ? '{{ __('Expand sidebar') }}' : '{{ __('Collapse sidebar') }}'"
            class="absolute -right-3.5 top-20 z-20 hidden h-7 w-7 items-center justify-center rounded-full border border-gray-200 bg-white text-gray-500 shadow-sm transition hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600 dark:hover:text-white lg:flex">
        <span class="sr-only">{{ __('Toggle sidebar') }}</span>
        <svg class="sidebar-toggle-icon h-4 w-4 transition-transform duration-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
        </svg>
    </button>

    {{-- Clipping wrapper: keeps labels from spilling during the width animation. --}}
    <div class="flex h-full min-h-0 flex-col overflow-hidden">
        {{-- Brand --}}
        <div class="sidebar-brand flex h-16 flex-shrink-0 items-center gap-2 border-b border-gray-200 px-6 dark:border-gray-700">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-2">
                <x-application-logo class="block h-8 w-auto flex-shrink-0 fill-current text-gray-800 dark:text-white" />
                <span class="sidebar-label font-semibold tracking-tight text-gray-900 dark:text-white">Cucina</span>
            </a>
            <button type="button"
                    @click="open = false"
                    class="ml-auto rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white lg:hidden">
                <span class="sr-only">{{ __('Close navigation') }}</span>
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        {{-- Links --}}
        <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4" aria-label="{{ __('Main navigation') }}">
            <a href="{{ route('dashboard') }}"
               @click="open = false"
               title="{{ __('Dashboard') }}"
               @if (request()->routeIs('dashboard')) aria-current="page" @endif
               class="sidebar-link flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('dashboard') ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white' }}">
                <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 011 1m-6 0h6" /></svg>
                <span class="sidebar-label">{{ __('Dashboard') }}</span>
            </a>

            {{-- Each module's link is behind the same permission its controller
                 checks, so a role without it never sees a link that 403s. --}}
            @can('view dish-types')
                <a href="{{ route('dish-types.index') }}"
                   @click="open = false"
                   title="{{ __('Dish Types') }}"
                   @if (request()->routeIs('dish-types.*')) aria-current="page" @endif
                   class="sidebar-link flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('dish-types.*') ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white' }}">
                    <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2V6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" /></svg>
                    <span class="sidebar-label">{{ __('Dish Types') }}</span>
                </a>
            @endcan

            @can('view dishes')
                <a href="{{ route('dishes.index') }}"
                   @click="open = false"
                   title="{{ __('Dishes') }}"
                   @if (request()->routeIs('dishes.*')) aria-current="page" @endif
                   class="sidebar-link flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('dishes.*') ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white' }}">
                    <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" /></svg>
                    <span class="sidebar-label">{{ __('Dishes') }}</span>
                </a>
            @endcan

            @can('view roles')
                <a href="{{ route('roles.index') }}"
                   @click="open = false"
                   title="{{ __('Roles') }}"
                   @if (request()->routeIs('roles.*')) aria-current="page" @endif
                   class="sidebar-link flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('roles.*') ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white' }}">
                    <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" /></svg>
                    <span class="sidebar-label">{{ __('Roles') }}</span>
                </a>
            @endcan

            @can('view users')
                <a href="{{ route('users.index') }}"
                   @click="open = false"
                   title="{{ __('Users') }}"
                   @if (request()->routeIs('users.*')) aria-current="page" @endif
                   class="sidebar-link flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('users.*') ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white' }}">
                    <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" /></svg>
                    <span class="sidebar-label">{{ __('Users') }}</span>
                </a>
            @endcan
        </nav>

        {{-- User + session actions --}}
        <div class="flex-shrink-0 border-t border-gray-200 p-4 dark:border-gray-700">
            <div class="sidebar-user flex items-center gap-3">
                <img class="h-9 w-9 flex-shrink-0 rounded-full bg-gray-200 dark:bg-gray-700"
                     src="https://ui-avatars.com/api/?name={{ urlencode(Auth::user()->name) }}&background=random"
                     alt="">
                <div class="sidebar-label min-w-0 flex-1">
                    <p class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ Auth::user()->name }}</p>
                    <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ Auth::user()->email }}</p>
                </div>
            </div>

            <div class="mt-4 space-y-1">
                <a href="{{ route('profile.edit') }}"
                   @click="open = false"
                   title="{{ __('Profile') }}"
                   class="sidebar-link flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-gray-600 transition hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white">
                    <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
                    <span class="sidebar-label">{{ __('Profile') }}</span>
                </a>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit"
                            title="{{ __('Log Out') }}"
                            class="sidebar-link flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm font-medium text-gray-600 transition hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white">
                        <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" /></svg>
                        <span class="sidebar-label">{{ __('Log Out') }}</span>
                    </button>
                </form>
            </div>
        </div>
    </div>
</aside>
