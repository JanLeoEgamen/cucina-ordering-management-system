<x-app-layout>
    <x-slot name="header">
        <x-page-header title="{{ __('System Configuration') }}" />
    </x-slot>

    @php
        $label = 'block text-sm font-medium text-gray-700 dark:text-gray-300';
        $input = 'block w-full rounded-xl border border-gray-200 bg-gray-50 px-3.5 py-2.5 text-sm leading-5 text-gray-900 placeholder-gray-400 transition focus:border-gray-900 focus:bg-white focus:outline-none focus:ring-4 focus:ring-gray-200 disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-600 dark:bg-gray-700/60 dark:text-white dark:placeholder-gray-400 dark:focus:border-gray-300 dark:focus:bg-gray-700 dark:focus:ring-gray-600 dark:[color-scheme:dark]';
        $submitButton = 'rounded-full bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-gray-300 disabled:opacity-60 dark:focus:ring-gray-600';
        $canEdit = auth()->user()->can('edit settings');
    @endphp

    <div class="container mx-auto max-w-3xl px-4 py-6 sm:px-6 lg:px-8">
        {{--
            One form for the whole page; each setting group is a card (section), so more
            can be added below this one. Saving is confirmed (the page's shared
            confirmation modal) and sent by resources/js/pages/settings.js.
        --}}
        <form id="settings-form" method="POST" action="{{ route('settings.update') }}"
              data-confirm-title="{{ __('Confirm Save') }}"
              data-confirm-message="{{ __('Are you sure you want to save the system configuration? New order sessions will start from these times, and the bulk order limits apply to every new bulk order.') }}"
              data-confirm-label="{{ __('Save Configuration') }}"
              data-label-edited="{{ __('Edited') }}"
              data-label-was="{{ __('Was') }}"
              data-label-undo="{{ __('Undo') }}"
              data-label-on="{{ __('On') }}"
              data-label-off="{{ __('Off') }}"
              data-label-change="{{ __('1 unsaved change') }}"
              data-label-changes="{{ __(':n unsaved changes') }}"
              class="space-y-6">
            @csrf
            @method('PUT')

            <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card dark:border-gray-700 dark:bg-gray-800">
                <div class="border-b border-gray-100 px-6 py-4 dark:border-gray-700">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Order sessions') }}</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {{ __('The start and end time a new order session is created with. They are only a starting point: each session can still be given its own times.') }}
                    </p>
                </div>

                <div class="p-6">
                    <div id="settings-errors" class="mb-4 hidden rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300"></div>

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div>
                            <label for="session-start-time" class="{{ $label }}">{{ __('Default Start Time') }}</label>
                            <div class="mt-1">
                                <input type="time" name="session_start_time" id="session-start-time" required
                                       value="{{ $sessionWindow['start_time'] }}" @disabled(! $canEdit)
                                       class="{{ $input }}">
                            </div>
                        </div>

                        <div>
                            <label for="session-end-time" class="{{ $label }}">{{ __('Default End Time') }}</label>
                            <div class="mt-1">
                                <input type="time" name="session_end_time" id="session-end-time" required
                                       value="{{ $sessionWindow['end_time'] }}" @disabled(! $canEdit)
                                       class="{{ $input }}">
                            </div>
                        </div>
                    </div>
                </div>

            </section>

            {{-- The size of a bulk order, for every bulk session. Saved with the rest of the form. --}}
            <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card dark:border-gray-700 dark:bg-gray-800">
                <div class="border-b border-gray-100 px-6 py-4 dark:border-gray-700">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Bulk orders') }}</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {{ __('How many plates one bulk order may hold, whatever the mix of dishes. A bulk session can ask for more than the minimum, never for less.') }}
                    </p>
                </div>

                <div class="p-6">
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div>
                            <label for="bulk-min-quantity" class="{{ $label }}">{{ __('Minimum plates per order') }}</label>
                            <div class="mt-1">
                                <input type="number" name="bulk_min_quantity" id="bulk-min-quantity" min="1" max="100000" step="1" required
                                       value="{{ $bulkLimits['min'] }}" @disabled(! $canEdit)
                                       class="{{ $input }}">
                            </div>
                        </div>

                        <div>
                            <label for="bulk-max-quantity" class="{{ $label }}">{{ __('Maximum plates per order') }}</label>
                            <div class="mt-1">
                                <input type="number" name="bulk_max_quantity" id="bulk-max-quantity" min="1" max="100000" step="1"
                                       value="{{ $bulkLimits['max'] }}" placeholder="{{ __('No limit') }}" @disabled(! $canEdit)
                                       class="{{ $input }}">
                            </div>
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Leave empty for no maximum.') }}</p>
                        </div>
                    </div>
                </div>
            </section>

            {{--
                Who receives a copy of EVERY notification. Customers always get their own
                (order placed, payment verified / rejected, order completed or cancelled);
                the users picked here also get everyone's, plus staff-only ones (a customer
                sent a new proof, a dish sold out). Saved with the rest of the form.
            --}}
            <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card dark:border-gray-700 dark:bg-gray-800">
                <div class="border-b border-gray-100 px-6 py-4 dark:border-gray-700">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Notifications') }}</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {{ __('Customers are always told about their own orders. Pick the users who should also receive every notification of every customer.') }}
                    </p>
                </div>

                <div class="p-6">
                    {{-- On / off for the email copy. A 0 is always sent first, so un-ticking is saved. --}}
                    <div class="mb-6 flex items-start justify-between gap-4">
                        <div>
                            <label for="email-notifications" class="{{ $label }}">{{ __('Send notifications by email') }}</label>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {{ __('Everyone who gets a notification also gets it by email, at the address of their account. Needs the mail settings of the server and a running queue worker.') }}
                            </p>
                            @if ($emailNotifications && in_array($mailer, ['log', 'array'], true))
                                <p class="mt-2 text-xs font-medium text-red-700 dark:text-red-300">
                                    {{ __('The server mailer is ":mailer": emails are only written to the log, not delivered.', ['mailer' => $mailer]) }}
                                </p>
                            @endif
                        </div>
                        <input type="hidden" name="email_notifications" value="0">
                        <label class="relative mt-1 inline-flex shrink-0 cursor-pointer items-center">
                            <input type="checkbox" role="switch" name="email_notifications" id="email-notifications" value="1"
                                   class="peer sr-only" @checked($emailNotifications) @disabled(! $canEdit)>
                            <span class="h-6 w-11 rounded-full bg-gray-300 transition peer-checked:bg-indigo-600 peer-focus-visible:ring-4 peer-focus-visible:ring-gray-300 peer-disabled:opacity-60 dark:bg-gray-600 dark:peer-focus-visible:ring-gray-600"></span>
                            <span class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-5 motion-reduce:transition-none"></span>
                        </label>
                    </div>

                    <label for="notification-watchers" class="{{ $label }}">{{ __('Also notify') }}</label>
                    <div class="mt-1">
                        {{-- An empty entry is always sent, so un-picking everybody is saved as "nobody". --}}
                        <input type="hidden" name="notification_watchers[]" value="">
                        <select multiple name="notification_watchers[]" id="notification-watchers" @disabled(! $canEdit)
                                data-placeholder="{{ __('Nobody') }}" data-count-label="{{ __('{n} users') }}"
                                class="{{ $input }}">
                            @foreach ($users as $user)
                                <option value="{{ $user->id }}" @selected(in_array((int) $user->id, $watchers, true))>
                                    {{ $user->name }} · {{ $user->email }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('They see them under Notifications in the menu. Whoever causes an event is not told about their own action.') }}
                    </p>
                </div>

                @if ($canEdit)
                    <div class="flex items-center justify-end gap-3 border-t border-gray-100 bg-gray-50/70 px-6 py-4 dark:border-gray-700 dark:bg-gray-900/40">
                        {{-- How many fields differ from what is saved (filled in by settings.js). --}}
                        <p id="settings-changes" class="mr-auto hidden text-sm font-medium text-gray-700 dark:text-gray-300" aria-live="polite"></p>
                        <button type="submit" id="settings-submit" data-loading-label="{{ __('Saving...') }}" class="{{ $submitButton }}">
                            {{ __('Save Configuration') }}
                        </button>
                    </div>
                @endif
            </section>
        </form>
    </div>

    @if ($canEdit)
        <x-edit-confirmation-modal
            title="{{ __('Confirm Save') }}"
            message="{{ __('Are you sure you want to save the system configuration?') }}"
            confirm-label="{{ __('Save Configuration') }}"
        />
    @endif

    @push('scripts')
        {{-- Page behaviour lives in resources/js/pages/settings.js; see vite.config.js. --}}
        @vite('resources/js/pages/settings.js')
    @endpush
</x-app-layout>
