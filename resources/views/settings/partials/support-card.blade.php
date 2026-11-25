{{--
    The public Help and Support page in System Configuration (Public pages tab): how to reach the shop (email, phone,
    opening hours) and the Markdown help text. Saved with the rest of the form. A collapsible block, open by default.

        support   Setting::support()
        input, canEdit   the settings page's shared classes and permission flag
--}}
<details class="group overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card dark:border-gray-700 dark:bg-gray-800" data-settings-details open>
    <summary class="flex cursor-pointer list-none items-center gap-3 px-5 py-4 transition hover:bg-gray-50 focus:outline-none focus-visible:bg-gray-50 sm:px-6 dark:hover:bg-gray-700/40 dark:focus-visible:bg-gray-700/40 [&::-webkit-details-marker]:hidden">
        <div class="min-w-0 flex-1">
            <h4 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Support') }}</h4>
            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                {{ __('The public Help and Support page: how to reach you and the answers to common questions. Open to visitors who are not signed in.') }}
            </p>
        </div>
        <span class="hidden shrink-0 rounded-full border border-gray-200 px-2.5 py-0.5 text-xs font-medium text-gray-600 sm:inline dark:border-gray-600 dark:text-gray-300">
            {{ $support['custom'] ? __('Your own text') : __('Built-in text') }}
        </span>
        <svg class="h-5 w-5 shrink-0 text-gray-400 transition-transform group-open:rotate-180 motion-reduce:transition-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
    </summary>

    <div class="border-t border-gray-100 px-5 pb-5 pt-2 sm:px-6 dark:border-gray-700/70">
        <div class="flex justify-end pt-2">
            <a href="{{ route('support') }}" target="_blank" rel="noopener"
               class="inline-flex items-center gap-1.5 rounded-full border border-gray-200 px-3.5 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700 dark:focus:ring-gray-700">
                {{ __('View the page') }}
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13.5 6H18v4.5M18 6l-7.5 7.5M10 6H7a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2-2v-3" /></svg>
            </a>
        </div>

        <div class="divide-y divide-gray-100 dark:divide-gray-700/70">
            <x-settings.field :label="__('Support email')" for="support-email">
                <input type="email" name="support_email" id="support-email" maxlength="120"
                       value="{{ $support['email'] }}" placeholder="help@example.com" @disabled(! $canEdit)
                       class="{{ $input }}">
            </x-settings.field>

            <x-settings.field :label="__('Support phone')" for="support-phone">
                <input type="tel" name="support_phone" id="support-phone" maxlength="40"
                       value="{{ $support['phone'] }}" placeholder="+63 900 000 0000" @disabled(! $canEdit)
                       class="{{ $input }}">
            </x-settings.field>

            <x-settings.field :label="__('Support hours')" for="support-hours"
                :hint="__('Each of the three is shown on the page only when it is filled in.')">
                <input type="text" name="support_hours" id="support-hours" maxlength="120"
                       value="{{ $support['hours'] }}" placeholder="{{ __('Mon to Fri, 8:00 to 17:00') }}" @disabled(! $canEdit)
                       class="{{ $input }}">
            </x-settings.field>

            <x-settings.field :label="__('Help text')" for="support-content" stacked
                :hint="$support['custom'] ? __('This is your own text.') : __('This is the built-in text: edit it to make it your own.')"
                :detail="($support['custom']
                    ? __('Empty the box and save to go back to the built-in text.')
                    : __('Check that it matches how you really run your business.'))
                    . ' ' . __('Write in Markdown: ## for a heading, - for a list item, **bold**, [link text](https://example.com). Typing :tag shows the app name. HTML is not allowed.', ['tag' => '{app_name}'])">
                <textarea name="support_content" id="support-content" rows="14" maxlength="{{ \App\Models\Setting::LEGAL_MAX_LENGTH }}"
                          spellcheck="true" @disabled(! $canEdit)
                          class="{{ $input }} font-mono leading-relaxed">{{ $support['content'] }}</textarea>
            </x-settings.field>
        </div>
    </div>
</details>
