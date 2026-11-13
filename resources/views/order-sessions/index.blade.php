<x-app-layout>
    <x-slot name="header">
        <x-page-header title="{{ __('Order Sessions') }}" action-label="{{ __('Add Session') }}" action-id="create-order-session" />
    </x-slot>

    @php
        $label = 'block text-sm font-medium text-gray-700 dark:text-gray-300';
        $input = 'block w-full rounded-xl border border-gray-200 bg-gray-50 px-3.5 py-2.5 text-sm leading-5 text-gray-900 placeholder-gray-400 transition focus:border-gray-900 focus:bg-white focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700/60 dark:text-white dark:placeholder-gray-400 dark:focus:border-gray-300 dark:focus:bg-gray-700 dark:focus:ring-gray-600';
        $th = 'border-b border-gray-100 bg-transparent px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-gray-500 whitespace-nowrap dark:border-gray-700 dark:text-gray-400';
        $cancelButton = 'rounded-full border border-gray-200 bg-white px-5 py-2.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600 dark:focus:ring-gray-700';
        $submitButton = 'rounded-full bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-gray-300 dark:focus:ring-gray-600';
        // pr-10 keeps the @tailwindcss/forms chevron from sitting on the value.
        $filter = 'block w-full rounded-full border border-gray-200 bg-white py-2.5 pl-4 pr-10 text-sm leading-5 text-gray-900 focus:border-gray-900 focus:outline-none focus:ring-4 focus:ring-gray-200 sm:w-auto dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:focus:border-gray-300 dark:focus:ring-gray-600';
    @endphp

    <div class="container mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card dark:border-gray-700 dark:bg-gray-800">
            {{-- Search, sort, filter and the view tabs (resources/js/ui/table-toolbar.js). A session is
                 scheduled before its window opens, live inside it, and ended once its end time went past
                 or its creator closed it by hand. --}}
            <x-ui.table-toolbar :export-title="__('Order Sessions')" :views="['table', 'card', 'calendar']" :sorts="[
                [__('Date: newest first'), 1, 'desc'],
                [__('Date: oldest first'), 1, 'asc'],
                [__('Most orders'), 4, 'desc'],
                [__('Most dishes'), 3, 'desc'],
                [__('Created by: A to Z'), 5, 'asc'],
            ]">
                <x-ui.filter-field :label="__('Status')">
                    <select id="session-status-filter" aria-label="{{ __('Status') }}" class="{{ $filter }} sm:!w-full">
                        <option value="">{{ __('All statuses') }}</option>
                        <option value="scheduled">{{ __('Scheduled') }}</option>
                        <option value="live">{{ __('Live') }}</option>
                        <option value="ended">{{ __('Ended') }}</option>
                    </select>
                </x-ui.filter-field>
            </x-ui.table-toolbar>

            {{-- Rows come from this route's AJAX response (client side table);
                 `data-endpoint` is read by resources/js/pages/order-sessions.js. --}}
            <table id="order-sessions-table"
                   data-endpoint="{{ route('order-sessions.index') }}"
                   class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50/70 dark:bg-gray-900/40">
                    <tr>
                        <th scope="col" data-no-export class="{{ $th }} w-12">#</th>
                        <th scope="col" class="{{ $th }}">{{ __('Date') }}</th>
                        <th scope="col" class="{{ $th }}">{{ __('Time') }}</th>
                        <th scope="col" class="{{ $th }} hidden sm:table-cell">{{ __('Dishes') }}</th>
                        <th scope="col" class="{{ $th }} hidden sm:table-cell">{{ __('Orders') }}</th>
                        <th scope="col" class="{{ $th }} hidden md:table-cell">{{ __('Created By') }}</th>
                        <th scope="col" class="{{ $th }} hidden sm:table-cell">{{ __('Status') }}</th>
                        <th scope="col" data-no-export class="{{ $th }} text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-700 dark:bg-gray-800"></tbody>
            </table>

            {{-- Card grid for the card view; populated by resources/js/pages/order-sessions.js --}}
            <div id="order-sessions-cards"
                 class="hidden grid grid-cols-1 gap-4 p-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
                 data-empty="{{ __('No data available yet') }}"></div>

            {{-- Calendar for the calendar view: a month grid, filled by
                 resources/js/pages/order-sessions.js from the same rows the table
                 holds (so the search box and the status filter apply to it too).
                 A session is a chip on its day and links to its page. --}}
            <div id="order-sessions-calendar" class="hidden p-4">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <button type="button" data-cal-nav="prev" aria-label="{{ __('Previous month') }}"
                                class="rounded-lg border border-gray-300 bg-white p-2 text-gray-600 transition hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-indigo-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 6-6 6 6 6" /></svg>
                        </button>
                        <button type="button" data-cal-nav="today"
                                class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-indigo-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">{{ __('Today') }}</button>
                        <button type="button" data-cal-nav="next" aria-label="{{ __('Next month') }}"
                                class="rounded-lg border border-gray-300 bg-white p-2 text-gray-600 transition hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-indigo-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6" /></svg>
                        </button>
                    </div>

                    <h3 id="calendar-title" class="text-base font-semibold text-gray-900 dark:text-white" aria-live="polite"></h3>

                    <ul class="flex items-center gap-3 text-xs text-gray-500 dark:text-gray-400" aria-label="{{ __('Status') }}">
                        <li class="inline-flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-blue-400"></span>{{ __('Scheduled') }}</li>
                        <li class="inline-flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-green-500"></span>{{ __('Live') }}</li>
                        <li class="inline-flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-gray-400"></span>{{ __('Ended') }}</li>
                    </ul>
                </div>

                <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
                    <div id="calendar-weekdays" class="grid grid-cols-7 bg-gray-50 text-center text-[11px] font-semibold uppercase tracking-wide text-gray-500 sm:text-xs dark:bg-gray-700/50 dark:text-gray-300"></div>
                    <div id="calendar-grid" class="grid grid-cols-7 gap-px bg-gray-200 dark:bg-gray-700"></div>
                </div>
            </div>
        </div>
    </div>

    {{-- Create drawer --}}
    <x-ui.drawer name="create-order-session-drawer" placement="right" size="lg">
        <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4 dark:border-gray-700">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Create Order Session') }}</h3>
            <button type="button"
                    class="rounded-full border border-gray-200 p-2.5 text-gray-500 transition hover:bg-gray-100 dark:border-gray-600 hover:text-gray-900 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white"
                    x-on:click="show = false">
                <span class="sr-only">{{ __('Close') }}</span>
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 6L6 18M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div class="flex-1 overflow-y-auto p-6">
            <div id="create-order-session-errors" class="mb-4 hidden rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300"></div>

            {{--
                The `data-*` attributes carry the page wiring to
                resources/js/pages/order-sessions.js: which drawer the form lives
                in, the field used as the confirmation subject and the copy.
                Keeping the copy here means it stays translated by __().
            --}}
            <form id="create-order-session-form" method="POST" action="{{ route('order-sessions.store') }}"
                  data-drawer="create-order-session-drawer"
                  data-subject-field="order_date"
                  data-default-start="{{ $sessionWindow['start_time'] }}"
                  data-default-end="{{ $sessionWindow['end_time'] }}"
                  data-confirm-title="{{ __('Confirm Create') }}"
                  data-confirm-message="{{ __('Are you sure you want to create this order session?') }}"
                  data-confirm-label="{{ __('Create Session') }}"
                  class="space-y-5">
                @csrf

                <div>
                    <label for="create-order-date" class="{{ $label }}">{{ __('Session Date') }}</label>
                    <div class="mt-1">
                        <input type="date" name="order_date" id="create-order-date" required class="{{ $input }}">
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="create-start-time" class="{{ $label }}">{{ __('Start Time') }}</label>
                        <div class="mt-1">
                            <input type="time" name="start_time" id="create-start-time" required class="{{ $input }}">
                        </div>
                    </div>

                    <div>
                        <label for="create-end-time" class="{{ $label }}">{{ __('End Time') }}</label>
                        <div class="mt-1">
                            <input type="time" name="end_time" id="create-end-time" required class="{{ $input }}">
                        </div>
                    </div>
                </div>

                <div>
                    <span class="{{ $label }}">{{ __('Dishes and Stock') }}</span>
                    <p class="mb-2 mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('Tick the dishes this session offers, and set how many of each it has.') }}
                    </p>
                    <x-ui.dish-stock-list prefix="create" :dishes="$dishes" />
                </div>

                <div class="flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <button type="button" class="{{ $cancelButton }}" x-on:click="show = false">{{ __('Cancel') }}</button>
                    <button type="submit" id="create-order-session-submit" class="{{ $submitButton }}">{{ __('Create Session') }}</button>
                </div>
            </form>
        </div>
    </x-ui.drawer>

    {{-- Edit drawer --}}
    <x-ui.drawer name="edit-order-session-drawer" placement="right" size="lg">
        <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4 dark:border-gray-700">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Edit Order Session') }}</h3>
            <button type="button"
                    class="rounded-full border border-gray-200 p-2.5 text-gray-500 transition hover:bg-gray-100 dark:border-gray-600 hover:text-gray-900 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white"
                    x-on:click="show = false">
                <span class="sr-only">{{ __('Close') }}</span>
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 6L6 18M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div class="flex-1 overflow-y-auto p-6">
            <div id="edit-order-session-errors" class="mb-4 hidden rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300"></div>

            <form id="edit-order-session-form" method="POST" action=""
                  data-drawer="edit-order-session-drawer"
                  data-load-error="{{ __('Unable to load this order session.') }}"
                  data-subject-field="order_date"
                  data-confirm-title="{{ __('Confirm Update') }}"
                  data-confirm-message="{{ __('Are you sure you want to save the changes to this order session?') }}"
                  data-confirm-label="{{ __('Update Session') }}"
                  class="space-y-5">
                @csrf
                @method('PUT')

                <div>
                    <label for="edit-order-date" class="{{ $label }}">{{ __('Session Date') }}</label>
                    <div class="mt-1">
                        <input type="date" name="order_date" id="edit-order-date" required class="{{ $input }}">
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="edit-start-time" class="{{ $label }}">{{ __('Start Time') }}</label>
                        <div class="mt-1">
                            <input type="time" name="start_time" id="edit-start-time" required class="{{ $input }}">
                        </div>
                    </div>

                    <div>
                        <label for="edit-end-time" class="{{ $label }}">{{ __('End Time') }}</label>
                        <div class="mt-1">
                            <input type="time" name="end_time" id="edit-end-time" required class="{{ $input }}">
                        </div>
                    </div>
                </div>

                <div>
                    <span class="{{ $label }}">{{ __('Dishes and Stock') }}</span>
                    <p class="mb-2 mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('A dish you untick leaves the session. The stock you set here is what the menu offers from now on.') }}
                    </p>
                    <x-ui.dish-stock-list prefix="edit" :dishes="$dishes" />
                </div>

                <div class="flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <button type="button" class="{{ $cancelButton }}" x-on:click="show = false">{{ __('Cancel') }}</button>
                    <button type="submit" id="edit-order-session-submit" class="{{ $submitButton }}">{{ __('Update Session') }}</button>
                </div>
            </form>
        </div>
    </x-ui.drawer>

    <x-delete-confirmation-modal
        title="{{ __('Confirm Delete') }}"
        message="{{ __('Are you sure you want to delete this order session? This will soft-delete it and take its dishes off the menu.') }}"
        confirm-label="{{ __('Delete') }}"
    />

    {{-- One confirmation modal serves create, update and end: the page script
         sets its copy and the form to send before opening it. --}}
    <x-edit-confirmation-modal
        title="{{ __('Confirm Create') }}"
        message="{{ __('Are you sure you want to create this order session?') }}"
        confirm-label="{{ __('Create Session') }}"
    />

    {{-- The end action has no form of its own: the page script points this one at
         the session before opening the confirmation modal. --}}
    <form id="end-session-form" method="POST" action=""
          data-confirm-title="{{ __('Confirm End') }}"
          data-confirm-message="{{ __('Are you sure you want to end this order session? Orders already placed are kept, and its dishes come off the menu.') }}"
          data-confirm-label="{{ __('End Session') }}"
          class="hidden">
        @csrf
    </form>

    @push('scripts')
        {{-- Page behaviour lives in resources/js/pages/order-sessions.js; see vite.config.js. --}}
        @vite('resources/js/pages/order-sessions.js')
    @endpush
</x-app-layout>
