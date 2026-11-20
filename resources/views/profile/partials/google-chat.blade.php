{{--
    Google Chat card of the Profile page (shown only while System Configuration -> Notifications ->
    "Send notifications to Google Chat" is on and the Chat key is set up: see
    ProfileController::googleChatState). $googleChat = connected / app / url.

    The indicator says whether the person's notifications are synced to Google Chat, i.e. whether
    their chat with the Chat app exists. The page asks after it has loaded (GET
    profile.google-chat.status, one Google lookup), so opening Profile never waits for Google.
    The how-to steps only show while it is NOT synced. The first time a person is found synced, the app
    sends them its Welcome message (ProfileController::googleChatStatus). Alpine inline, no script file.
--}}
@php
    $app = $googleChat['app'];
    $pill = 'inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold leading-5 text-gray-700 dark:bg-gray-700 dark:text-gray-200';
    $secondary = 'inline-flex items-center justify-center rounded-xl border border-gray-300 bg-white px-5 py-2.5 text-sm font-normal text-gray-700 transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 disabled:opacity-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600';
@endphp

<section @if ($googleChat['connected']) x-data="{
        state: 'checking',
        labels: @js(['checking' => __('Checking...'), 'connected' => __('Synced with Google Chat'), 'not_added' => __('Not synced yet'), 'error' => __('Could not check')]),
        dot() { return { checking: 'ended', connected: 'active', not_added: 'pending', error: 'rejected' }[this.state]; },
        async check() {
            this.state = 'checking';
            try {
                const response = await fetch('{{ route('profile.google-chat.status') }}', { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                const data = await response.json().catch(() => ({}));
                this.state = response.ok && ['connected', 'not_added'].includes(data.state) ? data.state : 'error';
            } catch (error) {
                this.state = 'error';
            }
        }
    }" x-init="check()" x-on:visibilitychange.document="if (! document.hidden && state === 'not_added') { check(); }" x-on:focus.window="if (state === 'not_added') { check(); }" @endif>
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            {{ __('Google Chat') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            {{ __('Get your order updates as a direct message from :app in Google Chat.', ['app' => $app]) }}
        </p>
    </header>

    @if ($googleChat['connected'])
        {{-- The indicator. data-status gives the dot its colour (resources/css/app.css). --}}
        <div class="mt-4 flex flex-wrap items-center gap-3">
            <span class="{{ $pill }}" data-status="ended" x-bind:data-status="dot()" role="status" aria-live="polite">
                <span x-text="labels[state]">{{ __('Checking...') }}</span>
            </span>
        </div>

        {{-- Synced --}}
        <div x-show="state === 'connected'" x-cloak>
            <p class="mt-3 text-sm text-gray-700 dark:text-gray-300">
                {{ __('Your notifications are sent to your chat with :app.', ['app' => $app]) }}
            </p>
        </div>

        {{-- Not synced: how to connect --}}
        <div x-show="state === 'not_added'" x-cloak>
            <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-300">
                @if ($googleChat['listing'])
                    <li>{{ __('Click Install :app and follow the Google page.', ['app' => $app]) }}</li>
                @else
                    <li>{{ __('Open Google Chat and click New chat.') }}</li>
                    <li>{{ __('Search for :app and open it.', ['app' => $app]) }}</li>
                @endif
                <li>{{ __('Come back here and press Check again. When it says Synced, :app sends you a welcome message.', ['app' => $app]) }}</li>
            </ol>

            <div class="mt-4 flex flex-wrap items-center gap-3">
                @if ($googleChat['listing'])
                    {{-- The how-to guide opens first (google-chat-guide.blade.php); its last step goes to the page. --}}
                    <button type="button" class="{{ $secondary }}"
                            x-on:click="window.dispatchEvent(new CustomEvent('open-modal', { detail: 'google-chat-guide' }))">
                        {{ __('Install :app', ['app' => $app]) }}
                    </button>
                @else
                    <a href="{{ $googleChat['url'] }}" target="_blank" rel="noopener noreferrer" class="{{ $secondary }}">
                        {{ __('Open Google Chat') }}
                    </a>
                @endif

                <x-primary-button type="button" x-on:click="check()">{{ __('Check again') }}</x-primary-button>
            </div>
        </div>

        {{-- Google or the server could not answer --}}
        <div x-show="state === 'error'" x-cloak>
            <p class="mt-3 text-sm text-gray-700 dark:text-gray-300">
                {{ __('We could not check your Google Chat right now. Please try again in a moment.') }}
            </p>

            <x-primary-button type="button" class="mt-4" x-on:click="check()">{{ __('Check again') }}</x-primary-button>
        </div>

        @if ($googleChat['listing'])
            @include('profile.partials.google-chat-guide', ['app' => $app, 'url' => $googleChat['url']])
        @endif

    @else
        <div class="mt-4">
            <span class="{{ $pill }}" data-status="pending">{{ __('Not synced yet') }}</span>
        </div>

        <p class="mt-3 rounded-lg bg-gray-50 p-3 text-sm text-gray-700 dark:bg-gray-700/40 dark:text-gray-300">
            {{ __('To get messages here, log out and use "Continue with Google" once with this email. Then come back to this page.') }}
        </p>
    @endif
</section>
