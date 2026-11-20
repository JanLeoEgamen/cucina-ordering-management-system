{{--
    The sign-in prompt for Google Chat (see App\View\Components\GoogleChatPrompt). Drawn only for a
    person who signed in with Google and is not synced yet. It is offered at EVERY login until they
    are synced.

    After the page has loaded it asks GET profile.google-chat.status once (the same endpoint as the
    Profile card; it also sends the Welcome message the first time someone is found synced):
      - synced just now  -> a small "you are connected, we sent you a welcome message" dialog;
      - not synced yet   -> the dialog opens: Install (the animated guide, or Google Chat itself),
                            Check again, Maybe later (closes it for this visit only);
      - anything else    -> nothing (no noise for a Google problem).
    The status call marks the login session as checked, so the next page does not ask again.
    Alpine inline, no script file. $googleChat = connected / app / url / listing.
--}}
@php
    $app = $googleChat['app'];
    $pill = 'inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold leading-5 text-gray-700 dark:bg-gray-700 dark:text-gray-200';
    $secondary = 'inline-flex items-center justify-center rounded-xl border border-gray-300 bg-white px-5 py-2.5 text-sm font-normal text-gray-700 transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 disabled:opacity-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600';
@endphp

<div x-data="{
        state: 'checking', busy: false,
        async check(first = false) {
            this.busy = true;
            try {
                const response = await fetch('{{ route('profile.google-chat.status') }}', { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                const data = await response.json().catch(() => ({}));

                if (response.ok && data.state === 'connected') {
                    // Just welcomed (or the person pressed Check again): say so. Already welcomed on a
                    // first look: nothing to show.
                    this.state = data.welcomed || ! first ? 'connected' : 'quiet';
                } else if (response.ok && data.state === 'not_added') {
                    this.state = 'not_added';
                } else {
                    this.state = first ? 'quiet' : 'error';
                }
            } catch (error) {
                this.state = first ? 'quiet' : 'error';
            } finally {
                this.busy = false;
            }

            if (first && this.state !== 'quiet') { this.show(); }
        },
        show() { window.dispatchEvent(new CustomEvent('open-modal', { detail: 'google-chat-prompt' })); },
        hide() { window.dispatchEvent(new CustomEvent('close-modal', { detail: 'google-chat-prompt' })); },
        install() {
            this.hide();
            @if ($googleChat['listing'])
                window.dispatchEvent(new CustomEvent('open-modal', { detail: 'google-chat-guide' }));
            @else
                window.open('{{ $googleChat['url'] }}', '_blank', 'noopener');
                setTimeout(() => this.show(), 400);
            @endif
        },
        // Only closes it: nothing is remembered, so it is offered again at the next login.
        later() { this.hide(); }
    }"
    x-init="check(true)"
    x-on:google-chat-guide-proceeded.window="if (state === 'not_added') { show(); }"
    x-on:visibilitychange.document="if (! document.hidden && state === 'not_added') { check(); }"
    x-on:focus.window="if (state === 'not_added' && ! busy) { check(); }">

    <x-ui.modal name="google-chat-prompt" size="md">
        <div class="p-6">
            <div class="flex items-start justify-between gap-3">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                    <span x-show="state !== 'connected'">{{ __('Get your order updates in Google Chat') }}</span>
                    <span x-show="state === 'connected'" x-cloak>{{ __('You are all set') }}</span>
                </h3>
                <button type="button" x-on:click="hide()"
                        class="rounded-full border border-gray-200 p-2 text-gray-500 transition hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white">
                    <span class="sr-only">{{ __('Close') }}</span>
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 6L6 18M6 6l12 12" /></svg>
                </button>
            </div>

            <div class="mt-3">
                <span class="{{ $pill }}" data-status="pending" x-bind:data-status="state === 'connected' ? 'active' : (state === 'error' ? 'rejected' : 'pending')" role="status" aria-live="polite">
                    <span x-show="state === 'connected'" x-cloak>{{ __('Synced with Google Chat') }}</span>
                    <span x-show="state === 'error'" x-cloak>{{ __('Could not check') }}</span>
                    <span x-show="state !== 'connected' && state !== 'error'">{{ __('Not synced yet') }}</span>
                </span>
            </div>

            {{-- Not synced --}}
            <div x-show="state !== 'connected'">
                <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">
                    {{ __('Install :app once and we send your order updates straight to Google Chat: when your order is placed, your payment is checked and it is ready for pick up.', ['app' => $app]) }}
                </p>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400" x-show="state === 'error'" x-cloak>
                    {{ __('We could not check your Google Chat right now. Please try again in a moment.') }}
                </p>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('After you install, come back here and press Check again.') }}
                </p>

                <div class="mt-5 flex flex-wrap items-center gap-3">
                    <x-primary-button type="button" x-on:click="install()">
                        {{ $googleChat['listing'] ? __('Install :app', ['app' => $app]) : __('Open Google Chat') }}
                    </x-primary-button>

                    <button type="button" x-on:click="check()" x-bind:disabled="busy" class="{{ $secondary }}">
                        <span x-show="! busy">{{ __('Check again') }}</span>
                        <span x-show="busy" x-cloak>{{ __('Checking...') }}</span>
                    </button>

                    <button type="button" x-on:click="later()"
                            class="text-sm font-medium text-gray-600 underline underline-offset-2 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">
                        {{ __('Maybe later') }}
                    </button>
                </div>
            </div>

            {{-- Synced just now --}}
            <div x-show="state === 'connected'" x-cloak>
                <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">
                    {{ __('Your notifications are sent to your chat with :app. We just sent you a welcome message there.', ['app' => $app]) }}
                </p>

                <div class="mt-5">
                    <x-primary-button type="button" x-on:click="hide()">{{ __('Done') }}</x-primary-button>
                </div>
            </div>
        </div>
    </x-ui.modal>

    @if ($googleChat['listing'])
        {{-- The same three-step guide as the Profile card; the Profile page has its own copy (the prompt is not drawn there). --}}
        @include('profile.partials.google-chat-guide', ['app' => $app, 'url' => $googleChat['url']])
    @endif
</div>
