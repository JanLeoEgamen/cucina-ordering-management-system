{{--
    The public Help and Support page's card in System Configuration: how to reach the shop (email, phone,
    opening hours) and the Markdown help text. Saved with the rest of the form.

        support   Setting::support()
        label, input, canEdit   the settings page's shared classes and permission flag
--}}
<section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card dark:border-gray-700 dark:bg-gray-800">
    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 px-6 py-4 dark:border-gray-700">
        <div class="min-w-0 flex-1">
            <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Support') }}</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                {{ __('The public Help and Support page, open to everyone including visitors who are not signed in: the ways to reach you and the answers to common questions. Linked from the home page, the sign in and sign up pages and the account menu.') }}
            </p>
        </div>
        <a href="{{ route('support') }}" target="_blank" rel="noopener"
           class="inline-flex items-center gap-1.5 rounded-full border border-gray-200 px-3.5 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700 dark:focus:ring-gray-700">
            {{ __('View the page') }}
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13.5 6H18v4.5M18 6l-7.5 7.5M10 6H7a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2-2v-3" /></svg>
        </a>
    </div>

    <div class="space-y-5 p-6">
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <div>
                <label for="support-email" class="{{ $label }}">{{ __('Support email') }}</label>
                <div class="mt-1">
                    <input type="email" name="support_email" id="support-email" maxlength="120"
                           value="{{ $support['email'] }}" placeholder="help@example.com" @disabled(! $canEdit)
                           class="{{ $input }}">
                </div>
            </div>

            <div>
                <label for="support-phone" class="{{ $label }}">{{ __('Support phone') }}</label>
                <div class="mt-1">
                    <input type="tel" name="support_phone" id="support-phone" maxlength="40"
                           value="{{ $support['phone'] }}" placeholder="+63 900 000 0000" @disabled(! $canEdit)
                           class="{{ $input }}">
                </div>
            </div>
        </div>

        <div>
            <label for="support-hours" class="{{ $label }}">{{ __('Support hours') }}</label>
            <div class="mt-1">
                <input type="text" name="support_hours" id="support-hours" maxlength="120"
                       value="{{ $support['hours'] }}" placeholder="{{ __('Mon to Fri, 8:00 to 17:00') }}" @disabled(! $canEdit)
                       class="{{ $input }}">
            </div>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Each of the three is shown on the page only when it is filled in.') }}</p>
        </div>

        <div>
            <label for="support-content" class="{{ $label }}">{{ __('Help text') }}</label>
            <div class="mt-1">
                <textarea name="support_content" id="support-content" rows="16" maxlength="{{ \App\Models\Setting::LEGAL_MAX_LENGTH }}"
                          spellcheck="true" @disabled(! $canEdit)
                          class="{{ $input }} font-mono leading-relaxed">{{ $support['content'] }}</textarea>
            </div>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                @if ($support['custom'])
                    {{ __('This is your own text. Empty the box and save to go back to the built-in text.') }}
                @else
                    {{ __('This is the built-in text: edit it to make it your own, and check that it matches how you really run your business.') }}
                @endif
                {{ __('Write in Markdown: ## for a heading, - for a list item, **bold**, [link text](https://example.com). Typing :tag shows the app name. HTML is not allowed.', ['tag' => '{app_name}']) }}
            </p>
        </div>
    </div>
</section>
