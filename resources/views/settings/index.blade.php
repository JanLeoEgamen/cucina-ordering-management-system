<x-app-layout>
    <x-slot name="header">
        <x-page-header title="{{ __('System Configuration') }}" />
    </x-slot>

    @php
        $input = 'block w-full rounded-xl border border-gray-200 bg-gray-50 px-3.5 py-2.5 text-sm leading-5 text-gray-900 placeholder-gray-400 transition focus:border-gray-900 focus:bg-white focus:outline-none focus:ring-4 focus:ring-gray-200 disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-600 dark:bg-gray-700/60 dark:text-white dark:placeholder-gray-400 dark:focus:border-gray-300 dark:focus:bg-gray-700 dark:focus:ring-gray-600 dark:[color-scheme:dark]';
        $submitButton = 'rounded-full bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-gray-300 disabled:opacity-60 dark:focus:ring-gray-600';
        $canEdit = auth()->user()->can('edit settings');

        // The tabs, in the order they are drawn. The first one is open until settings.js reads the URL hash.
        $tabs = [
            'branding' => [__('Branding'), __('Name, logo and colours')],
            'ordering' => [__('Ordering'), __('Sessions, orders, pre-orders')],
            'pricing' => [__('Pricing'), __('Discounts and ala carte')],
            'notifications' => [__('Notifications'), __('Email, Google Chat, who is told')],
            'public-pages' => [__('Public pages'), __('Support, privacy, terms')],
        ];
    @endphp

    <div class="container mx-auto max-w-5xl px-4 py-6 sm:px-6 lg:px-8">
        {{--
            One form for the whole page. The settings are grouped into tabs (resources/js/pages/settings.js shows
            one panel at a time and keeps the URL hash), but every panel stays in the form, so Save sends them all.
            Saving is confirmed (the page's shared confirmation modal) and sent by settings.js. A new setting goes
            into the panel it belongs to as an <x-settings.field>.
        --}}
        {{-- novalidate: the browser cannot point at a field inside a hidden tab, and then refuses the submit without a word.
             settings.js checks the fields itself on submit, opens the tab of the first wrong one, and the server validates anyway. --}}
        <form id="settings-form" method="POST" action="{{ route('settings.update') }}" enctype="multipart/form-data" novalidate
              data-confirm-title="{{ __('Confirm Save') }}"
              data-confirm-message="{{ __('Are you sure you want to save the system configuration? The app name and logo change on every page, new order sessions will start from these times, the pre-order limits apply to every new pre-order, and the public privacy policy and terms of service pages change.') }}"
              data-confirm-label="{{ __('Save Configuration') }}"
              data-label-edited="{{ __('Edited') }}"
              data-label-was="{{ __('Was') }}"
              data-label-undo="{{ __('Undo') }}"
              data-label-on="{{ __('On') }}"
              data-label-off="{{ __('Off') }}"
              data-label-none="{{ __('No unsaved changes') }}"
              data-label-change="{{ __('1 unsaved change') }}"
              data-label-changes="{{ __(':n unsaved changes') }}">
            @csrf
            @method('PUT')

            <div class="lg:grid lg:grid-cols-[14rem_minmax(0,1fr)] lg:items-start lg:gap-8">
                {{-- The tabs: a scrolling strip on a phone, a column beside the settings from lg. --}}
                <nav id="settings-tabs" role="tablist" aria-label="{{ __('System Configuration') }}"
                     class="-mx-4 mb-5 flex gap-1.5 overflow-x-auto px-4 pb-1 sm:-mx-6 sm:px-6 lg:sticky lg:top-24 lg:mx-0 lg:mb-0 lg:flex-col lg:gap-1 lg:overflow-visible lg:px-0 lg:pb-0">
                    @foreach ($tabs as $id => [$title, $subtitle])
                        <button type="button" role="tab" id="settings-tab-{{ $id }}" data-settings-tab="{{ $id }}"
                                aria-controls="settings-panel-{{ $id }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}"
                                tabindex="{{ $loop->first ? '0' : '-1' }}"
                                class="group flex shrink-0 items-center gap-2 rounded-xl px-3.5 py-2 text-left text-sm font-medium text-gray-600 transition hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus-visible:ring-4 focus-visible:ring-gray-300 aria-selected:bg-accent aria-selected:text-accent-on aria-selected:hover:bg-accent lg:px-4 lg:py-2.5 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white dark:focus-visible:ring-gray-600 ">
                            <span class="min-w-0">
                                <span class="block whitespace-nowrap lg:whitespace-normal">{{ $title }}</span>
                                <span class="hidden text-xs font-normal text-gray-500 group-aria-selected:text-accent-on/70 lg:block dark:text-gray-400">{{ $subtitle }}</span>
                            </span>
                            {{-- How many fields of this tab differ from what is saved (filled in by settings.js). --}}
                            <span data-tab-count class="ml-auto hidden min-w-5 rounded-full bg-accent px-1.5 text-center text-[11px] font-semibold leading-5 text-accent-on group-aria-selected:bg-accent-on group-aria-selected:text-accent" aria-hidden="true"></span>
                        </button>
                    @endforeach
                </nav>

                <div class="min-w-0">
                    {{-- The name and logo every page, error page and email shows (Setting::brand()). --}}
                    <x-settings.panel id="branding" :title="__('Branding')" active
                        :description="__('The name and logo shown in the menu, on the sign in pages, in the browser tab and in emails, and the colour theme of the whole app.')">
                        <x-settings.section>
                            <x-settings.field :label="__('App name')" for="app-name">
                                <input type="text" name="app_name" id="app-name" required maxlength="60"
                                       value="{{ $brand['name'] }}" placeholder="{{ \App\Models\Setting::DEFAULT_APP_NAME }}" @disabled(! $canEdit)
                                       class="{{ $input }}">
                            </x-settings.field>

                            <x-settings.field :label="__('Logo')" for="app-logo" stacked
                                :hint="__('JPG, PNG or WebP, up to 2 MB.')"
                                :detail="__('A square picture with a transparent background works best: it is shown small, in the menu and on light and dark panels.')">
                                <div class="flex flex-wrap items-center gap-4">
                                    {{-- What the logo looks like now; settings.js swaps in the picked file. A picture
                                         on the sign in panel and the menu sits on light AND dark backgrounds. --}}
                                    <div id="app-logo-preview"
                                         class="flex h-16 w-16 flex-shrink-0 items-center justify-center rounded-xl border border-gray-200 bg-gray-50 p-2 text-gray-900 dark:border-gray-600 dark:bg-gray-700/60 dark:text-white">
                                        <x-application-logo class="h-full w-full fill-current" />
                                    </div>

                                    <div class="min-w-0 flex-1">
                                        <input type="file" name="app_logo" id="app-logo" accept="image/png,image/jpeg,image/webp" @disabled(! $canEdit)
                                               class="block w-full text-sm text-gray-600 file:mr-3 file:cursor-pointer file:rounded-full file:border-0 file:bg-accent file:px-4 file:py-2 file:text-sm file:font-semibold file:text-accent-on hover:file:bg-accent-hover disabled:opacity-60 dark:text-gray-300 ">

                                        @if ($brand['logo'])
                                            <label for="remove-app-logo" class="mt-3 inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                                                <input type="checkbox" name="remove_app_logo" id="remove-app-logo" value="1" @disabled(! $canEdit)
                                                       class="rounded border-gray-300 text-gray-900 focus:ring-gray-400 dark:border-gray-600 dark:bg-gray-700">
                                                {{ __('Remove the logo and use the built-in one') }}
                                            </label>
                                        @endif
                                    </div>
                                </div>
                            </x-settings.field>
                        </x-settings.section>

                        {{-- The colour of the whole app (App\Support\Theme, config/themes.php). Picking one previews it on
                             this page at once (settings.js); it is only saved with the rest of the form. --}}
                        <x-settings.section :title="__('Colour theme')"
                            :description="__('The colour of buttons, links and highlights on every page.')"
                            :detail="__('It also colours the page you are on in the menu, in light and dark mode. Pick one to see it here before you save.')">
                            <x-settings.field :label="__('Theme')" for="theme-accent" stacked>
                                {{-- The swatches ARE the picker. The dropdown below is the real form field (settings.js tracks it for
                                     Edited / Undo and posts it) but is hidden: two controls for one choice was clutter. --}}
                                <div class="space-y-4" role="group" aria-label="{{ __('Colour theme') }}"
                                     data-theme-swatches data-themes='@json($themePreviews)'>
                                    {{-- Two rows: plain colours, then gradients (a theme with a `gradient` in config/themes.php). --}}
                                    @foreach ([false => __('Colours'), true => __('Gradients')] as $isGradient => $groupTitle)
                                        <div>
                                            <p class="mb-2 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $groupTitle }}</p>
                                            <div class="flex flex-wrap items-center gap-x-2.5 gap-y-3">
                                                @foreach ($themes as $slug => $label)
                                                    @continue($swatches[$slug]['gradient'] !== (bool) $isGradient)
                                                    <button type="button" data-theme-swatch="{{ $slug }}" title="{{ $label }}" @disabled(! $canEdit)
                                                aria-label="{{ $label }}" aria-pressed="{{ $slug === $themeAccent ? 'true' : 'false' }}"
                                                class="group relative h-8 w-8 rounded-full border-2 border-white shadow-sm ring-1 ring-gray-300 transition hover:scale-110 focus:outline-none focus-visible:ring-4 focus-visible:ring-gray-400 disabled:cursor-not-allowed disabled:opacity-60 aria-pressed:ring-2 aria-pressed:ring-gray-900 dark:border-gray-800 dark:ring-gray-600 dark:aria-pressed:ring-white"
                                                style="background: {{ $swatches[$slug]['paint'] }}">
                                            <svg class="absolute inset-0 m-auto hidden h-4 w-4 text-white group-aria-pressed:block" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-7.5 7.5a1 1 0 0 1-1.4 0L3.3 9.7a1 1 0 1 1 1.4-1.4l3.8 3.8 6.8-6.8a1 1 0 0 1 1.4 0Z" clip-rule="evenodd" /></svg>
                                        </button>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endforeach
                                </div>

                                {{-- The name of the picked theme, kept up to date by settings.js. --}}
                                <p data-theme-name class="mt-3 text-sm font-medium text-gray-700 dark:text-gray-200">{{ $themes[$themeAccent] ?? '' }}{{ $themeAccent === $themeDefault ? ' (' . __('default') . ')' : '' }}</p>

                                <select name="theme_accent" id="theme-accent" @disabled(! $canEdit) tabindex="-1" aria-hidden="true" class="sr-only">
                                    @foreach ($themes as $slug => $label)
                                        <option value="{{ $slug }}" @selected($slug === $themeAccent)>{{ __($label) }}{{ $slug === $themeDefault ? ' (' . __('default') . ')' : '' }}</option>
                                    @endforeach
                                </select>
                            </x-settings.field>
                        </x-settings.section>
                    </x-settings.panel>

                    <x-settings.panel id="ordering" :title="__('Ordering')"
                        :description="__('How order sessions, orders and pre-orders behave.')">
                        <x-settings.section :title="__('Order sessions')"
                            :description="__('The times a new order session starts with.')"
                            :detail="__('Only a starting point: each session can still be given its own times.')">
                            <x-settings.field :label="__('Default Start Time')" for="session-start-time">
                                <input type="time" name="session_start_time" id="session-start-time" required
                                       value="{{ $sessionWindow['start_time'] }}" @disabled(! $canEdit)
                                       class="{{ $input }}">
                            </x-settings.field>

                            <x-settings.field :label="__('Default End Time')" for="session-end-time">
                                <input type="time" name="session_end_time" id="session-end-time" required
                                       value="{{ $sessionWindow['end_time'] }}" @disabled(! $canEdit)
                                       class="{{ $input }}">
                            </x-settings.field>
                        </x-settings.section>

                        <x-settings.section :title="__('Orders')" :description="__('What customers may do with an order they placed.')">
                            <x-settings.field :label="__('Customers can cancel their own order')" for="customer-cancel" switch
                                :hint="__('Shows a Cancel button on their pending orders.')"
                                :detail="__('A Cancel button appears on a customer\'s pending order in My Orders. Cancelling gives the plates back to the menu and rejects a payment that was still waiting. Once staff verified the payment only staff can cancel. Staff can always cancel.')">
                                <x-settings.switch name="customer_cancel" id="customer-cancel" :checked="$customerCancel" :disabled="! $canEdit" />
                            </x-settings.field>
                        </x-settings.section>

                        {{-- The size of a pre-order, for every pre-order session. --}}
                        <x-settings.section :title="__('Pre-orders')"
                            :description="__('How many plates one pre-order may hold.')"
                            :detail="__('Whatever the mix of dishes. A pre-order session can ask for more than the minimum, never for less.')">
                            <x-settings.field :label="__('Minimum plates per order')" for="pre-order-min-quantity">
                                <input type="number" name="pre_order_min_quantity" id="pre-order-min-quantity" min="1" max="100000" step="1" required
                                       value="{{ $preOrderLimits['min'] }}" @disabled(! $canEdit)
                                       class="{{ $input }}">
                            </x-settings.field>

                            <x-settings.field :label="__('Maximum plates per order')" for="pre-order-max-quantity"
                                :hint="__('Leave empty for no maximum.')">
                                <input type="number" name="pre_order_max_quantity" id="pre-order-max-quantity" min="1" max="100000" step="1"
                                       value="{{ $preOrderLimits['max'] }}" placeholder="{{ __('No limit') }}" @disabled(! $canEdit)
                                       class="{{ $input }}">
                            </x-settings.field>
                        </x-settings.section>
                    </x-settings.panel>

                    <x-settings.panel id="pricing" :title="__('Pricing')"
                        :description="__('Ways a customer can pay less than the menu price.')">
                        {{-- Where customers are offered a discount. The discounts themselves are on the Discounts page. --}}
                        <x-settings.section :title="__('Discounts')"
                            :description="__('Where customers are offered a discount.')"
                            :detail="__('The discounts they can pick (name, percent, proof) are managed on the Discounts page.')">
                            <x-settings.field :label="__('Offer discounts on')" for="discount-mode"
                                :hint="__('Switch it off to stop new discounts.')"
                                :detail="__('Switching this off stops new discounts at once. Orders that already have a discount keep it, and staff can still approve or reject it.')">
                                <select name="discount_mode" id="discount-mode" @disabled(! $canEdit) class="{{ $input }}">
                                    <option value="off" @selected($discountMode === 'off')>{{ __('Nothing (discounts are off)') }}</option>
                                    <option value="orders" @selected($discountMode === 'orders')>{{ __('Regular orders only') }}</option>
                                    <option value="pre_orders" @selected($discountMode === 'pre_orders')>{{ __('Pre-orders only') }}</option>
                                    <option value="both" @selected($discountMode === 'both')>{{ __('Regular orders and pre-orders') }}</option>
                                </select>
                            </x-settings.field>
                        </x-settings.section>

                        {{-- Ala carte: a plate without its rice, for less. Which dishes come with rice is ticked on the Dish Masterlist. --}}
                        <x-settings.section :title="__('Ala carte (no rice)')"
                            :description="__('Let customers order a plate without its rice, for less.')"
                            :detail="__('Only dishes marked \'Comes with rice\' on the Dish Masterlist can be ordered this way.')">
                            <x-settings.field :label="__('Offer ala carte on')" for="ala-carte-mode">
                                <select name="ala_carte_mode" id="ala-carte-mode" @disabled(! $canEdit) class="{{ $input }}">
                                    <option value="off" @selected($alaCarteMode === 'off')>{{ __('Nothing (ala carte is off)') }}</option>
                                    <option value="orders" @selected($alaCarteMode === 'orders')>{{ __('Regular orders only') }}</option>
                                    <option value="pre_orders" @selected($alaCarteMode === 'pre_orders')>{{ __('Pre-orders only') }}</option>
                                    <option value="both" @selected($alaCarteMode === 'both')>{{ __('Regular orders and pre-orders') }}</option>
                                </select>
                            </x-settings.field>

                            <x-settings.field :label="__('Rice price (taken off each plate)')" for="ala-carte-rice-price"
                                :hint="__('Taken off every plate ordered without rice.')"
                                :detail="__('A plate ordered without rice costs the dish price minus this amount. A dish that costs no more than the rice is not offered without it. Switching this off stops new ala carte plates at once; orders that already have them keep their price.')">
                                <input type="number" name="ala_carte_rice_price" id="ala-carte-rice-price" min="0" max="100000" step="0.01" required
                                       value="{{ number_format($ricePrice, 2, '.', '') }}" @disabled(! $canEdit)
                                       class="{{ $input }}">
                            </x-settings.field>
                        </x-settings.section>
                    </x-settings.panel>

                    {{--
                        Who receives a copy of EVERY notification. Customers always get their own
                        (order placed, payment verified / rejected, order completed or cancelled);
                        the users picked here also get everyone's, plus staff-only ones (a customer
                        sent a new proof, a dish sold out).
                    --}}
                    <x-settings.panel id="notifications" :title="__('Notifications')" cards
                        :description="__('Customers are always told about their own orders, in the bell. Here: what else is sent, and who else is told.')">
                      <x-settings.card :title="__('Order notifications')"
                          :description="__('Placed, paid, ready, completed or cancelled orders.')">
                        <x-settings.section :title="__('Copies')" :description="__('Both need a running queue worker.')">
                            <x-settings.field :label="__('Send notifications by email')" for="email-notifications" switch
                                :hint="__('Also email each notification to its recipient.')"
                                :detail="__('Everyone who gets a notification also gets it by email, at the address of their account. Needs the mail settings of the server and a running queue worker.')">
                                <x-slot:note>
                                    @if ($emailNotifications && in_array($mailer, ['log', 'array'], true))
                                        <p class="mt-2 text-xs font-medium text-red-700 dark:text-red-300">
                                            {{ __('The server mailer is ":mailer": emails are only written to the log, not delivered.', ['mailer' => $mailer]) }}
                                        </p>
                                    @endif
                                </x-slot:note>
                                <x-settings.switch name="email_notifications" id="email-notifications" :checked="$emailNotifications" :disabled="! $canEdit" />
                            </x-settings.field>

                            <x-settings.field :label="__('Send notifications to Google Chat')" for="google-chat-notifications" switch
                                :hint="__('Also send each notification as a Google Chat message.')"
                                :detail="__('Everyone who gets a notification also gets it as a Google Chat direct message. Only people who signed in with Google and added the Chat app can be reached; the others still get the bell. Needs a running queue worker.')">
                                <x-slot:note>
                                    @if ($googleChatNotifications && ! $googleChatReady)
                                        <p class="mt-2 text-xs font-medium text-red-700 dark:text-red-300">
                                            {{ __('The Chat app key is not set up on the server (GOOGLE_CHAT_CREDENTIALS), so nothing is sent.') }}
                                        </p>
                                    @endif
                                </x-slot:note>
                                <x-settings.switch name="google_chat_notifications" id="google-chat-notifications" :checked="$googleChatNotifications" :disabled="! $canEdit" />
                            </x-settings.field>
                        </x-settings.section>

                        <x-settings.section :title="__('Also notify')"
                            :description="__('Pick the users who should also receive every notification of every customer.')">
                            <x-settings.field :label="__('Users')" for="notification-watchers" stacked
                                :hint="__('They get a copy of every customer\'s notifications.')"
                                :detail="__('They see them under Notifications in the menu. Whoever causes an event is not told about their own action.')">
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
                            </x-settings.field>
                        </x-settings.section>
                      </x-settings.card>

                      {{-- Its own card, one plain block of fields (no section titles): who is told, then how. --}}
                      <x-settings.card :title="__('New dish alerts')"
                          :description="__('When a dish is added to the Dish Masterlist with the Add Dish form.')">
                        <x-settings.section>
                            <x-settings.field :label="__('Who is told')" for="new-dish-watchers" stacked
                                :hint="__('They are told each time a dish is added.')"
                                :detail="__('Only dishes added with the Add Dish form count (not the Excel import, not edits). Whoever adds the dish is not told about their own action. They always get it in the bell; the switches below add email and Google Chat.')">
                                {{-- An empty entry is always sent, so un-picking everybody is saved as "nobody". --}}
                                <input type="hidden" name="new_dish_watchers[]" value="">
                                <select multiple name="new_dish_watchers[]" id="new-dish-watchers" @disabled(! $canEdit)
                                        data-placeholder="{{ __('Nobody') }}" data-count-label="{{ __('{n} users') }}"
                                        class="{{ $input }}">
                                    @foreach ($users as $user)
                                        <option value="{{ $user->id }}" @selected(in_array((int) $user->id, $newDishWatchers, true))>
                                            {{ $user->name }} · {{ $user->email }}
                                        </option>
                                    @endforeach
                                </select>
                            </x-settings.field>

                            {{-- Their own switches: independent of the "Copies" switches above, which are for order notifications. --}}
                            <x-settings.field :label="__('Send by email')" for="new-dish-email" switch
                                :hint="__('Also email the alert to these users (the bell they always get).')"
                                :detail="__('Only the new dish alert, whatever the order notification switch above says. Sent to the address of each picked user. Needs the mail settings of the server and a running queue worker.')">
                                <x-slot:note>
                                    @if ($newDishEmail && in_array($mailer, ['log', 'array'], true))
                                        <p class="mt-2 text-xs font-medium text-red-700 dark:text-red-300">
                                            {{ __('The server mailer is ":mailer": emails are only written to the log, not delivered.', ['mailer' => $mailer]) }}
                                        </p>
                                    @endif
                                </x-slot:note>
                                <x-settings.switch name="new_dish_email" id="new-dish-email" :checked="$newDishEmail" :disabled="! $canEdit" />
                            </x-settings.field>

                            <x-settings.field :label="__('Send to Google Chat')" for="new-dish-google-chat" switch
                                :hint="__('Also send the alert as a Google Chat message.')"
                                :detail="__('Only the new dish alert, whatever the order notification switch above says. Only people who signed in with Google and added the Chat app can be reached; the others still get the bell. Needs a running queue worker.')">
                                <x-slot:note>
                                    @if ($newDishGoogleChat && ! $googleChatReady)
                                        <p class="mt-2 text-xs font-medium text-red-700 dark:text-red-300">
                                            {{ __('The Chat app key is not set up on the server (GOOGLE_CHAT_CREDENTIALS), so nothing is sent.') }}
                                        </p>
                                    @endif
                                </x-slot:note>
                                <x-settings.switch name="new_dish_google_chat" id="new-dish-google-chat" :checked="$newDishGoogleChat" :disabled="! $canEdit" />
                            </x-settings.field>
                        </x-settings.section>
                      </x-settings.card>
                    </x-settings.panel>

                    {{-- The three public text pages. Each is a collapsible block (Markdown; empty = the built-in text). --}}
                    <x-settings.panel id="public-pages" :title="__('Public pages')" cards="compact"
                        :description="__('The texts anyone can read, signed in or not. Open one to edit it.')">
                        {{-- The public Help and Support page: contact details + a Markdown help text. --}}
                        @include('settings.partials.support-card', ['support' => $support])

                        @include('settings.partials.legal-card', [
                            'page' => 'privacy-policy',
                            'heading' => __('Privacy policy'),
                            'description' => __('The public page customers read before they sign up: what is collected and why. Linked from the home page, the sign in and sign up pages and the account menu.'),
                            'legal' => $legal['privacy-policy'],
                        ])

                        @include('settings.partials.legal-card', [
                            'page' => 'terms-of-service',
                            'heading' => __('Terms of service'),
                            'description' => __('The public page with the rules of ordering: payment, pick up, cancelling. Linked from the same places as the privacy policy.'),
                            'legal' => $legal['terms-of-service'],
                        ])
                    </x-settings.panel>

                    @if ($canEdit)
                        {{-- Always in reach, whichever tab is open or however far it is scrolled. Any refused save shows
                             its reasons right above the button (filled in by settings.js). --}}
                        <div class="sticky bottom-4 z-30 mt-6 space-y-2">
                            <div id="settings-errors" class="hidden rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700 shadow-card dark:border-red-900 dark:bg-red-950 dark:text-red-300" role="alert"></div>

                            <div class="flex items-center justify-end gap-3 rounded-2xl border border-gray-200 bg-white/95 px-4 py-3 shadow-card-lift backdrop-blur dark:border-gray-700 dark:bg-gray-800/95">
                                {{-- How many fields differ from what is saved, over every tab (filled in by settings.js). --}}
                                <p id="settings-changes" class="mr-auto text-sm text-gray-500 dark:text-gray-400" aria-live="polite">{{ __('No unsaved changes') }}</p>
                                <button type="submit" id="settings-submit" data-loading-label="{{ __('Saving...') }}" class="{{ $submitButton }}">
                                    {{ __('Save Configuration') }}
                                </button>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
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
        {{-- Page behaviour lives in resources/js/pages/settings.js; started by resources/js/ui/soft-nav.js. --}}
        <x-page-module name="settings" />
    @endpush
</x-app-layout>
