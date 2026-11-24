{{--
    One public legal page in System Configuration (Public pages tab): its Markdown text and a contact email,
    saved with the rest of the form. Included once per page of Setting::LEGAL_PAGES. A collapsible block, closed
    until opened, so three long texts do not stack open; its fields stay in the form either way.

        page         'privacy-policy' | 'terms-of-service' (fields: privacy_policy_content, terms_of_service_content, and the same with _contact_email)
        heading      the block's title
        description  what the page is for
        legal        Setting::legalPage($page)
        input, canEdit   the settings page's shared classes and permission flag
--}}
@php($field = str_replace('-', '_', $page))
<details class="group" data-settings-details>
    <summary class="flex cursor-pointer list-none items-center gap-3 px-5 py-4 transition hover:bg-gray-50 focus:outline-none focus-visible:bg-gray-50 sm:px-6 dark:hover:bg-gray-700/40 dark:focus-visible:bg-gray-700/40 [&::-webkit-details-marker]:hidden">
        <div class="min-w-0 flex-1">
            <h4 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $heading }}</h4>
            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $description }}</p>
        </div>
        <span class="hidden shrink-0 rounded-full border border-gray-200 px-2.5 py-0.5 text-xs font-medium text-gray-600 sm:inline dark:border-gray-600 dark:text-gray-300">
            {{ $legal['custom'] ? __('Your own text') : __('Built-in text') }}
        </span>
        <svg class="h-5 w-5 shrink-0 text-gray-400 transition-transform group-open:rotate-180 motion-reduce:transition-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
    </summary>

    <div class="border-t border-gray-100 px-5 pb-5 pt-2 sm:px-6 dark:border-gray-700/70">
        <div class="flex justify-end pt-2">
            <a href="{{ route($page) }}" target="_blank" rel="noopener"
               class="inline-flex items-center gap-1.5 rounded-full border border-gray-200 px-3.5 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700 dark:focus:ring-gray-700">
                {{ __('View the page') }}
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13.5 6H18v4.5M18 6l-7.5 7.5M10 6H7a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2-2v-3" /></svg>
            </a>
        </div>

        <div class="divide-y divide-gray-100 dark:divide-gray-700/70">
            <x-settings.field :label="__('Page text')" :for="$page . '-content'" stacked
                :hint="($legal['custom']
                    ? __('This is your own text. Empty the box and save to go back to the built-in text.')
                    : __('This is the built-in text: edit it to make it your own, and check that it matches how you really run your business.'))
                    . ' ' . __('Write in Markdown: ## for a heading, - for a list item, **bold**, [link text](https://example.com). Typing :tag shows the app name. HTML is not allowed.', ['tag' => '{app_name}'])">
                <textarea name="{{ $field }}_content" id="{{ $page }}-content" rows="14" maxlength="{{ \App\Models\Setting::LEGAL_MAX_LENGTH }}"
                          spellcheck="true" @disabled(! $canEdit)
                          class="{{ $input }} font-mono leading-relaxed">{{ $legal['content'] }}</textarea>
            </x-settings.field>

            <x-settings.field :label="__('Contact email')" :for="$page . '-contact-email'"
                :hint="__('Shown under the page so people know who to write to. Leave empty to show none.')">
                <input type="email" name="{{ $field }}_contact_email" id="{{ $page }}-contact-email" maxlength="120"
                       value="{{ $legal['contact_email'] }}" placeholder="hello@example.com" @disabled(! $canEdit)
                       class="{{ $input }}">
            </x-settings.field>
        </div>
    </div>
</details>
