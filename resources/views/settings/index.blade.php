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
        <form id="settings-form" method="POST" action="{{ route('settings.update') }}" enctype="multipart/form-data"
              data-confirm-title="{{ __('Confirm Save') }}"
              data-confirm-message="{{ __('Are you sure you want to save the system configuration? The app name and logo change on every page, new order sessions will start from these times, the pre-order limits apply to every new pre-order, and the public privacy policy and terms of service pages change.') }}"
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

            {{-- Any refused save: filled in by settings.js, at the top where it is seen whichever card caused it. --}}
            <div id="settings-errors" class="hidden rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300" role="alert"></div>

            {{-- The name and logo every page, error page and email shows (Setting::brand()). --}}
            <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card dark:border-gray-700 dark:bg-gray-800">
                <div class="border-b border-gray-100 px-6 py-4 dark:border-gray-700">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Branding') }}</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {{ __('The name and logo shown in the menu, on the sign in pages, in the browser tab and in emails.') }}
                    </p>
                </div>

                <div class="space-y-5 p-6">
                    <div>
                        <label for="app-name" class="{{ $label }}">{{ __('App name') }}</label>
                        <div class="mt-1">
                            <input type="text" name="app_name" id="app-name" required maxlength="60"
                                   value="{{ $brand['name'] }}" placeholder="{{ \App\Models\Setting::DEFAULT_APP_NAME }}" @disabled(! $canEdit)
                                   class="{{ $input }}">
                        </div>
                    </div>

                    <div>
                        <span class="{{ $label }}">{{ __('Logo') }}</span>
                        <div class="mt-2 flex flex-wrap items-center gap-4">
                            {{-- What the logo looks like now; settings.js swaps in the picked file. A picture
                                 on the sign in panel and the menu sits on light AND dark backgrounds. --}}
                            <div id="app-logo-preview"
                                 class="flex h-16 w-16 flex-shrink-0 items-center justify-center rounded-xl border border-gray-200 bg-gray-50 p-2 text-gray-900 dark:border-gray-600 dark:bg-gray-700/60 dark:text-white">
                                <x-application-logo class="h-full w-full fill-current" />
                            </div>

                            <div class="min-w-0 flex-1">
                                <input type="file" name="app_logo" id="app-logo" accept="image/png,image/jpeg,image/webp" @disabled(! $canEdit)
                                       class="block w-full text-sm text-gray-600 file:mr-3 file:cursor-pointer file:rounded-full file:border-0 file:bg-gray-900 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-gray-700 disabled:opacity-60 dark:text-gray-300 dark:file:bg-mist dark:file:text-ink">
                                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                    {{ __('JPG, PNG or WebP, up to 2 MB. A square picture with a transparent background works best: it is shown small, in the menu and on light and dark panels.') }}
                                </p>

                                @if ($brand['logo'])
                                    <label for="remove-app-logo" class="mt-3 inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                                        <input type="checkbox" name="remove_app_logo" id="remove-app-logo" value="1" @disabled(! $canEdit)
                                               class="rounded border-gray-300 text-gray-900 focus:ring-gray-400 dark:border-gray-600 dark:bg-gray-700">
                                        {{ __('Remove the logo and use the built-in one') }}
                                    </label>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card dark:border-gray-700 dark:bg-gray-800">
                <div class="border-b border-gray-100 px-6 py-4 dark:border-gray-700">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Order sessions') }}</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {{ __('The start and end time a new order session is created with. They are only a starting point: each session can still be given its own times.') }}
                    </p>
                </div>

                <div class="p-6">
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

            {{-- What a customer may do with their own order. Saved with the rest of the form. --}}
            <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card dark:border-gray-700 dark:bg-gray-800">
                <div class="border-b border-gray-100 px-6 py-4 dark:border-gray-700">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Orders') }}</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {{ __('What customers may do with an order they placed.') }}
                    </p>
                </div>

                <div class="p-6">
                    {{-- A 0 is always sent first, so un-ticking is saved. --}}
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <label for="customer-cancel" class="{{ $label }}">{{ __('Customers can cancel their own order') }}</label>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {{ __('A Cancel button appears on a customer\'s pending order in My Orders. Cancelling gives the plates back to the menu and rejects a payment that was still waiting. Once staff verified the payment only staff can cancel. Staff can always cancel.') }}
                            </p>
                        </div>
                        <input type="hidden" name="customer_cancel" value="0">
                        <label class="relative mt-1 inline-flex shrink-0 cursor-pointer items-center">
                            <input type="checkbox" role="switch" name="customer_cancel" id="customer-cancel" value="1"
                                   class="peer sr-only" @checked($customerCancel) @disabled(! $canEdit)>
                            <span class="h-6 w-11 rounded-full bg-gray-300 transition peer-checked:bg-indigo-600 peer-focus-visible:ring-4 peer-focus-visible:ring-gray-300 peer-disabled:opacity-60 dark:bg-gray-600 dark:peer-focus-visible:ring-gray-600"></span>
                            <span class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-5 motion-reduce:transition-none"></span>
                        </label>
                    </div>
                </div>
            </section>

            {{-- The size of a pre-order, for every pre-order session. Saved with the rest of the form. --}}
            <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card dark:border-gray-700 dark:bg-gray-800">
                <div class="border-b border-gray-100 px-6 py-4 dark:border-gray-700">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Pre-orders') }}</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {{ __('How many plates one pre-order may hold, whatever the mix of dishes. A pre-order session can ask for more than the minimum, never for less.') }}
                    </p>
                </div>

                <div class="p-6">
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div>
                            <label for="pre-order-min-quantity" class="{{ $label }}">{{ __('Minimum plates per order') }}</label>
                            <div class="mt-1">
                                <input type="number" name="pre_order_min_quantity" id="pre-order-min-quantity" min="1" max="100000" step="1" required
                                       value="{{ $preOrderLimits['min'] }}" @disabled(! $canEdit)
                                       class="{{ $input }}">
                            </div>
                        </div>

                        <div>
                            <label for="pre-order-max-quantity" class="{{ $label }}">{{ __('Maximum plates per order') }}</label>
                            <div class="mt-1">
                                <input type="number" name="pre_order_max_quantity" id="pre-order-max-quantity" min="1" max="100000" step="1"
                                       value="{{ $preOrderLimits['max'] }}" placeholder="{{ __('No limit') }}" @disabled(! $canEdit)
                                       class="{{ $input }}">
                            </div>
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Leave empty for no maximum.') }}</p>
                        </div>
                    </div>
                </div>
            </section>

            {{-- The two public legal pages (privacy policy, terms of service). Markdown; empty = the built-in text. --}}
            @include('settings.partials.legal-card', [
                'page' => 'privacy',
                'heading' => __('Privacy policy'),
                'description' => __('The public page customers read before they sign up: what is collected and why. Linked from the home page, the sign in and sign up pages and the account menu.'),
                'legal' => $legal['privacy'],
            ])

            @include('settings.partials.legal-card', [
                'page' => 'terms',
                'heading' => __('Terms of service'),
                'description' => __('The public page with the rules of ordering: payment, pick up, cancelling. Linked from the same places as the privacy policy.'),
                'legal' => $legal['terms'],
            ])

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

                    {{-- On / off for the Google Chat copy (a direct message from the Chat app). --}}
                    <div class="mb-6 flex items-start justify-between gap-4">
                        <div>
                            <label for="google-chat-notifications" class="{{ $label }}">{{ __('Send notifications to Google Chat') }}</label>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {{ __('Everyone who gets a notification also gets it as a Google Chat direct message. Only people who signed in with Google and added the Chat app can be reached; the others still get the bell. Needs a running queue worker.') }}
                            </p>
                            @if ($googleChatNotifications && ! $googleChatReady)
                                <p class="mt-2 text-xs font-medium text-red-700 dark:text-red-300">
                                    {{ __('The Chat app key is not set up on the server (GOOGLE_CHAT_CREDENTIALS), so nothing is sent.') }}
                                </p>
                            @endif
                        </div>
                        <input type="hidden" name="google_chat_notifications" value="0">
                        <label class="relative mt-1 inline-flex shrink-0 cursor-pointer items-center">
                            <input type="checkbox" role="switch" name="google_chat_notifications" id="google-chat-notifications" value="1"
                                   class="peer sr-only" @checked($googleChatNotifications) @disabled(! $canEdit)>
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
