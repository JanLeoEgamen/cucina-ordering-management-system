{{--
    Google Chat card of the Profile page (shown only while System Configuration -> Notifications ->
    "Send notifications to Google Chat" is on and the Chat key is set up: see
    ProfileController::googleChatState). $googleChat = connected / app / url.
    The test button is Alpine, no script file: POST profile.google-chat.test, then print the answer.
--}}
<section x-data="{
        sending: false, message: '', ok: false,
        async test() {
            this.sending = true; this.message = '';
            try {
                const response = await fetch('{{ route('profile.google-chat.test') }}', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await response.json().catch(() => ({}));
                this.ok = response.ok;
                this.message = data.message || '{{ __('Something went wrong. Please try again.') }}';
            } catch (error) {
                this.ok = false;
                this.message = '{{ __('Something went wrong. Please try again.') }}';
            } finally {
                this.sending = false;
            }
        }
    }">
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            {{ __('Google Chat') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            {{ __('Get your order updates as a direct message from :app in Google Chat.', ['app' => $googleChat['app']]) }}
        </p>
    </header>

    @if ($googleChat['connected'])
        <ol class="mt-4 list-decimal space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-300">
            <li>{{ __('Open Google Chat and click New chat.') }}</li>
            <li>{{ __('Search for :app, open it and say hi. You only do this once.', ['app' => $googleChat['app']]) }}</li>
            <li>{{ __('Press the button below to check it works.') }}</li>
        </ol>

        <div class="mt-6 flex flex-wrap items-center gap-3">
            <a href="{{ $googleChat['url'] }}" target="_blank" rel="noopener noreferrer"
               class="inline-flex items-center justify-center rounded-xl border border-gray-300 bg-white px-5 py-2.5 text-sm font-normal text-gray-700 transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                {{ __('Open Google Chat') }}
            </a>

            <x-primary-button type="button" x-on:click="test()" x-bind:disabled="sending">
                <span x-show="! sending">{{ __('Send me a test message') }}</span>
                <span x-show="sending" x-cloak>{{ __('Sending...') }}</span>
            </x-primary-button>
        </div>

        <p x-show="message" x-cloak role="status" x-text="message"
           class="mt-3 text-sm"
           x-bind:class="ok ? 'text-gray-900 dark:text-gray-100' : 'text-red-700 dark:text-red-300'"></p>
    @else
        <p class="mt-4 rounded-lg bg-gray-50 p-3 text-sm text-gray-700 dark:bg-gray-700/40 dark:text-gray-300">
            {{ __('To get messages here, log out and use "Continue with Google" once with this email. Then come back to this page.') }}
        </p>
    @endif
</section>
