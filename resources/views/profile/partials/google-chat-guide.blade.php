{{--
    "How to install" guide, shown BEFORE the person is sent to the app's installation page (the
    Marketplace listing in GOOGLE_CHAT_APP_URL). Three steps, one at a time, each with a small
    animation that plays itself line by line. Opened by the card's Install button
    (open-modal 'google-chat-guide'). $app, $url.

    Alpine inline, no script file. `tick` counts up on a few timers after each step appears, and
    every element of the little illustration shows when tick reaches its number: that is what
    makes it "one by one". With prefers-reduced-motion everything is shown at once and nothing
    moves. Colours stay in the theme (ink / grey; the green dot is the app's own status dot).
--}}
@php
    $pill = 'inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold leading-5 text-gray-700 dark:bg-gray-700 dark:text-gray-200';
    $mockButton = 'inline-flex items-center rounded-full bg-gray-900 px-5 py-1.5 text-sm font-medium text-white transition-all duration-500 motion-reduce:transition-none dark:bg-mist dark:text-ink';
    $stage = 'relative flex h-48 items-center justify-center overflow-hidden rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900/40';
    $fade = 'transition-all duration-500 motion-reduce:transition-none';
@endphp

<x-ui.modal name="google-chat-guide" size="lg">
    <div x-data="{
            step: 0, last: 2, tick: 0, timers: [],
            reduce: window.matchMedia('(prefers-reduced-motion: reduce)').matches,
            reset() { this.go(0); },
            go(n) { this.step = n; this.play(); },
            next() { if (this.step < this.last) { this.go(this.step + 1); } },
            back() { if (this.step > 0) { this.go(this.step - 1); } },
            stop() { this.timers.forEach(clearTimeout); this.timers = []; },
            play() {
                this.stop(); this.tick = 0;
                if (this.reduce) { this.tick = 6; return; }
                [500, 1300, 2100, 3000, 3900, 4800].forEach((ms, i) => this.timers.push(setTimeout(() => { this.tick = i + 1; }, ms)));
            },
            proceed() {
                // A small window centred over this one, not a tab: Google does not allow its pages to be
                // embedded in another site (X-Frame-Options), but the person never loses this page.
                // noopener: the Google page gets no handle on this one.
                const width = 520, height = 720;
                const left = Math.max(0, Math.round(window.screenX + (window.outerWidth - width) / 2));
                const top = Math.max(0, Math.round(window.screenY + (window.outerHeight - height) / 2));
                window.open(this.$root.dataset.url, 'cucina-chat-install', 'popup=yes,noopener,width=' + width + ',height=' + height + ',left=' + left + ',top=' + top);
                window.dispatchEvent(new CustomEvent('close-modal', { detail: 'google-chat-guide' }));
                // The sign-in prompt listens: it comes back so the person can press Check again.
                window.dispatchEvent(new CustomEvent('google-chat-guide-proceeded'));
            }
        }"
        data-url="{{ $url }}"
        x-on:open-modal.window="$event.detail === 'google-chat-guide' && reset()"
        x-effect="if (! show) { stop(); }">

        <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-6 py-4 dark:border-gray-700">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Install :app', ['app' => $app]) }}</h3>
            <button type="button" x-on:click="show = false"
                    class="rounded-full border border-gray-200 p-2 text-gray-500 transition hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white">
                <span class="sr-only">{{ __('Close') }}</span>
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 6L6 18M6 6l12 12" /></svg>
            </button>
        </div>

        <div class="p-6">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ __('Step') }} <span x-text="step + 1"></span> / 3
            </p>

            {{-- Step 1: click Install --}}
            <div x-show="step === 0" x-cloak x-transition:enter="transition duration-300 motion-reduce:transition-none" x-transition:enter-start="opacity-0 translate-x-3" x-transition:enter-end="opacity-100 translate-x-0">
                <h4 class="mt-1 text-base font-semibold text-gray-900 dark:text-white">{{ __('Click Install') }}</h4>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('A Google page opens with :app. Click Install.', ['app' => $app]) }}</p>

                <div class="{{ $stage }} mt-4">
                    <div class="w-64 rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <div class="flex items-center gap-3">
                            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-gray-900 text-sm font-semibold text-white dark:bg-mist dark:text-ink">CF</span>
                            <div>
                                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $app }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Google Chat app') }}</p>
                            </div>
                        </div>
                        <div class="relative mt-4">
                            <span class="{{ $mockButton }}" x-bind:class="tick >= 2 && tick < 3 ? 'scale-95 ring-4 ring-gray-300 dark:ring-gray-600' : ''">
                                <span x-show="tick < 3">{{ __('Install') }}</span>
                                <span x-show="tick >= 3" x-cloak>{{ __('Installed') }} ✓</span>
                            </span>
                            {{-- The pointer glides onto the button, then it is pressed. --}}
                            <svg class="{{ $fade }} absolute left-12 top-3 h-6 w-6 text-gray-900 drop-shadow dark:text-white"
                                 x-bind:class="tick >= 1 ? 'translate-x-0 translate-y-0 opacity-100' : 'translate-x-16 translate-y-10 opacity-0'"
                                 viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M5 3l14 8-6 2-3 6z" /></svg>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Step 2: confirm --}}
            <div x-show="step === 1" x-cloak x-transition:enter="transition duration-300 motion-reduce:transition-none" x-transition:enter-start="opacity-0 translate-x-3" x-transition:enter-end="opacity-100 translate-x-0">
                <h4 class="mt-1 text-base font-semibold text-gray-900 dark:text-white">{{ __('Confirm') }}</h4>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('If Google asks you to continue or allow, say yes. This is only so :app can message you.', ['app' => $app]) }}</p>

                <div class="{{ $stage }} mt-4">
                    <div class="{{ $fade }} w-64 rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800"
                         x-bind:class="tick >= 1 ? 'translate-y-0 opacity-100' : 'translate-y-4 opacity-0'">
                        <div class="h-2 w-40 rounded bg-gray-200 dark:bg-gray-600"></div>
                        <div class="mt-2 h-2 w-52 rounded bg-gray-200 dark:bg-gray-600"></div>
                        <div class="mt-2 h-2 w-32 rounded bg-gray-200 dark:bg-gray-600"></div>
                        <div class="mt-4 flex justify-end gap-2">
                            <span class="rounded-full border border-gray-300 px-4 py-1 text-xs font-medium text-gray-600 dark:border-gray-600 dark:text-gray-300">{{ __('Cancel') }}</span>
                            <span class="rounded-full bg-gray-900 px-4 py-1 text-xs font-medium text-white transition-all duration-500 motion-reduce:transition-none dark:bg-mist dark:text-ink"
                                  x-bind:class="tick >= 2 ? 'scale-105 ring-4 ring-gray-300 dark:ring-gray-600' : ''">
                                <span x-show="tick < 3">{{ __('Continue') }}</span>
                                <span x-show="tick >= 3" x-cloak>✓</span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Step 3: come back, it is checked, the welcome arrives --}}
            <div x-show="step === 2" x-cloak x-transition:enter="transition duration-300 motion-reduce:transition-none" x-transition:enter-start="opacity-0 translate-x-3" x-transition:enter-end="opacity-100 translate-x-0">
                <h4 class="mt-1 text-base font-semibold text-gray-900 dark:text-white">{{ __('Come back and check') }}</h4>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('Come back to this page. We check your Google Chat, and as soon as it says Synced, :app sends you a welcome message.', ['app' => $app]) }}</p>

                <div class="{{ $stage }} mt-4">
                    <div class="flex flex-col items-center gap-3">
                        <span class="{{ $pill }}" data-status="pending" x-bind:data-status="tick >= 2 ? 'active' : 'pending'">
                            <span x-show="tick < 2">{{ __('Not synced yet') }}</span>
                            <span x-show="tick >= 2" x-cloak>{{ __('Synced with Google Chat') }}</span>
                        </span>
                        <div class="{{ $fade }} max-w-[15rem] rounded-2xl rounded-bl-sm bg-white px-4 py-2 text-sm text-gray-800 shadow-sm dark:bg-gray-800 dark:text-gray-200"
                             x-bind:class="tick >= 3 ? 'translate-y-0 opacity-100' : 'translate-y-3 opacity-0'">
                            <p class="font-semibold">👋 {{ __('Welcome to :app', ['app' => $app]) }}</p>
                            <p class="mt-0.5 text-xs text-gray-600 dark:text-gray-400">{{ __('You are all set! From now on your order updates arrive right here.') }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 px-6 py-4 dark:border-gray-700">
            {{-- Progress: one dot per step; the current one is filled. --}}
            <div class="flex items-center gap-2" aria-hidden="true">
                <template x-for="n in 3" x-bind:key="n">
                    <span class="h-2 rounded-full transition-all duration-300 motion-reduce:transition-none"
                          x-bind:class="step === n - 1 ? 'w-6 bg-gray-900 dark:bg-mist' : 'w-2 bg-gray-300 dark:bg-gray-600'"></span>
                </template>
            </div>

            <div class="flex items-center gap-3">
                <button type="button" x-on:click="proceed()" x-show="step < last"
                        class="text-sm font-medium text-gray-600 underline underline-offset-2 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">{{ __('Skip tutorial') }}</button>

                <button type="button" x-on:click="back()" x-show="step > 0" x-cloak
                        class="rounded-full border border-gray-300 bg-white px-5 py-2.5 text-sm font-medium text-gray-700 transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">{{ __('Back') }}</button>

                <x-primary-button type="button" x-show="step < last" x-on:click="next()">{{ __('Next') }}</x-primary-button>
                <x-primary-button type="button" x-show="step === last" x-cloak x-on:click="proceed()">{{ __('Go to the installation page') }}</x-primary-button>
            </div>
        </div>
    </div>
</x-ui.modal>
