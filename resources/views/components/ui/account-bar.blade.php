@props(['id' => 'account'])

{{--
    The signed-in person's corner: the dark / light switch, the notification bell and the
    account menu (Profile, Log Out). It sits in the TOP RIGHT of every page: a bar of its own
    from lg up (layouts/app.blade.php), and the right end of the phone's top bar below that
    (layouts/navigation.blade.php). The sidebar no longer carries these links.

    The bell is the point of the corner: its badge is always in sight, and opening it shows
    the latest few notifications right there. resources/js/ui/notifications.js fills the badge
    (`data-notif-badge`, a small poll), the list (`data-notif-list`, loaded when the panel
    opens and again when something new arrives) and rings the bell for a new one.

    The component is rendered twice per page (phone bar + desktop bar, only one of them
    visible), so every id carries the `id` prop; the script paints all badges and lists.

    On a phone the panels span the screen's width (`inset-x-4`, anchored to the fixed top bar);
    from sm up they hang under their button.
--}}
@php
    $user = Auth::user();
    $initials = mb_strtoupper(mb_substr($user->first_name, 0, 1)) . mb_strtoupper(mb_substr($user->last_name, 0, 1));
    $role = $user->getRoleNames()->first();
    $roundButton = 'relative inline-flex h-10 w-10 items-center justify-center rounded-full border border-gray-200 bg-white text-gray-500 shadow-sm transition hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white dark:focus:ring-gray-700';
    $panel = 'absolute z-50 mt-2 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card-lift dark:border-gray-700 dark:bg-gray-800';
    $menuItem = 'flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left text-sm font-medium text-gray-700 transition hover:bg-gray-100 hover:text-gray-900 focus:bg-gray-100 focus:outline-none dark:text-gray-200 dark:hover:bg-gray-700 dark:hover:text-white dark:focus:bg-gray-700';
@endphp

<div class="flex items-center gap-2">
    <x-ui.theme-toggle class="bg-white shadow-sm dark:bg-gray-800" />

    {{-- The bell + its latest notifications. `sm:relative`: on a phone the panel anchors to the bar. --}}
    <div class="sm:relative" x-data="{ open: false }" @keydown.escape.window="open = false" @click.outside="open = false">
        <button type="button"
                data-notif-bell
                @click="open = ! open; if (open) $dispatch('notif-open')"
                :aria-expanded="open ? 'true' : 'false'"
                aria-haspopup="true"
                aria-controls="{{ $id }}-notifications"
                title="{{ __('Notifications') }}"
                class="{{ $roundButton }}">
            <span class="sr-only">{{ __('Notifications') }}</span>
            <svg data-notif-icon class="h-5 w-5 origin-top" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" /></svg>
            <span data-notif-badge data-feed-url="{{ route('notifications.feed') }}" aria-live="polite"
                  class="absolute -right-1 -top-1 hidden min-w-[1.25rem] rounded-full bg-indigo-600 px-1 text-center text-[11px] font-semibold leading-5 text-white ring-2 ring-gray-50 dark:bg-mist dark:text-ink dark:ring-gray-900">0</span>
        </button>

        <div id="{{ $id }}-notifications" data-notif-panel
             x-show="open" x-cloak
             x-transition:enter="transition duration-150 ease-out"
             x-transition:enter-start="opacity-0 -translate-y-1 scale-95"
             x-transition:enter-end="opacity-100 translate-y-0 scale-100"
             x-transition:leave="transition duration-100 ease-in"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             class="{{ $panel }} inset-x-4 top-full origin-top-right sm:inset-x-auto sm:right-0 sm:top-auto sm:w-[24rem]">
            <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-3 dark:border-gray-700">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Notifications') }}</h3>
                <button type="button" data-notif-read-all data-read-all-url="{{ route('notifications.read-all') }}"
                        class="hidden rounded-full px-2.5 py-1 text-xs font-medium text-gray-600 transition hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-gray-300 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white">
                    {{ __('Mark all as read') }}
                </button>
            </div>

            <ul data-notif-list role="list" class="max-h-[min(24rem,60vh)] divide-y divide-gray-100 overflow-y-auto dark:divide-gray-700/70"></ul>

            <p data-notif-empty class="hidden px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('You are all caught up.') }}</p>
            <p data-notif-loading data-failed-text="{{ __('Could not load. Close and open again to retry.') }}" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('Loading...') }}</p>

            <a href="{{ route('notifications.index') }}"
               class="block border-t border-gray-100 bg-gray-50/70 px-4 py-3 text-center text-sm font-medium text-gray-700 transition hover:bg-gray-100 hover:text-gray-900 dark:border-gray-700 dark:bg-gray-900/40 dark:text-gray-200 dark:hover:bg-gray-700">
                {{ __('View all notifications') }}
            </a>
        </div>
    </div>

    {{-- The account menu. --}}
    <div class="relative" x-data="{ open: false }" @keydown.escape.window="open = false" @click.outside="open = false">
        <button type="button"
                @click="open = ! open"
                :aria-expanded="open ? 'true' : 'false'"
                aria-haspopup="true"
                aria-controls="{{ $id }}-account"
                title="{{ $user->name }}"
                class="flex h-10 items-center gap-2 rounded-full border border-gray-200 bg-white py-1 pl-1 pr-3 text-sm shadow-sm transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-800 dark:hover:bg-gray-700 dark:focus:ring-gray-700 max-sm:pr-1">
            <span class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-ink text-xs font-bold text-white dark:bg-mist dark:text-ink" aria-hidden="true">{{ $initials }}</span>
            <span class="hidden max-w-[9rem] truncate font-medium text-gray-800 dark:text-gray-100 xl:block">{{ $user->first_name }}</span>
            <svg class="hidden h-4 w-4 flex-shrink-0 text-gray-400 transition-transform duration-200 sm:block" :class="open ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m19 9-7 7-7-7" /></svg>
            <span class="sr-only">{{ __('Account menu') }}</span>
        </button>

        <div id="{{ $id }}-account"
             x-show="open" x-cloak
             x-transition:enter="transition duration-150 ease-out"
             x-transition:enter-start="opacity-0 -translate-y-1 scale-95"
             x-transition:enter-end="opacity-100 translate-y-0 scale-100"
             x-transition:leave="transition duration-100 ease-in"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             class="{{ $panel }} right-0 w-72 origin-top-right">
            {{-- Who is signed in: the same ink card as the sidebar's, so the two read as one. --}}
            <div class="flex items-center gap-3 bg-ink p-4 text-white dark:bg-gray-900">
                <span class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-full bg-mist text-sm font-bold text-ink" aria-hidden="true">{{ $initials }}</span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold">{{ $user->name }}</p>
                    <p class="truncate text-xs text-white/60">{{ $user->email }}</p>
                    @if ($role)
                        <span class="mt-1 inline-flex rounded-full border border-white/20 bg-white/10 px-2 py-0.5 text-[11px] font-medium text-mist">{{ $role }}</span>
                    @endif
                </div>
            </div>

            <div class="space-y-0.5 p-2">
                <a href="{{ route('profile.edit') }}" class="{{ $menuItem }}">
                    <svg class="h-5 w-5 flex-shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
                    {{ __('Profile') }}
                </a>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="{{ $menuItem }}">
                        <svg class="h-5 w-5 flex-shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" /></svg>
                        {{ __('Log Out') }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
