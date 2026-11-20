{{--
    One public legal page's card in System Configuration: its Markdown text and a contact email,
    saved with the rest of the form. Included once per page of Setting::LEGAL_PAGES.

        page         'privacy' | 'terms' (the field names are <page>_content / <page>_contact_email)
        heading      the card's title
        description  what the page is for
        legal        Setting::legalPage($page)
        label, input, canEdit   the settings page's shared classes and permission flag
--}}
<section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card dark:border-gray-700 dark:bg-gray-800">
    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 px-6 py-4 dark:border-gray-700">
        <div class="min-w-0 flex-1">
            <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ $heading }}</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $description }}</p>
        </div>
        <a href="{{ route($page) }}" target="_blank" rel="noopener"
           class="inline-flex items-center gap-1.5 rounded-full border border-gray-200 px-3.5 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700 dark:focus:ring-gray-700">
            {{ __('View the page') }}
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13.5 6H18v4.5M18 6l-7.5 7.5M10 6H7a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2-2v-3" /></svg>
        </a>
    </div>

    <div class="space-y-5 p-6">
        <div>
            <label for="{{ $page }}-content" class="{{ $label }}">{{ __('Page text') }}</label>
            <div class="mt-1">
                <textarea name="{{ $page }}_content" id="{{ $page }}-content" rows="16" maxlength="{{ \App\Models\Setting::LEGAL_MAX_LENGTH }}"
                          spellcheck="true" @disabled(! $canEdit)
                          class="{{ $input }} font-mono leading-relaxed">{{ $legal['content'] }}</textarea>
            </div>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                @if ($legal['custom'])
                    {{ __('This is your own text. Empty the box and save to go back to the built-in text.') }}
                @else
                    {{ __('This is the built-in text: edit it to make it your own, and check that it matches how you really run your business.') }}
                @endif
                {{ __('Write in Markdown: ## for a heading, - for a list item, **bold**, [link text](https://example.com). Typing :tag shows the app name. HTML is not allowed.', ['tag' => '{app_name}']) }}
            </p>
        </div>

        <div>
            <label for="{{ $page }}-contact-email" class="{{ $label }}">{{ __('Contact email') }}</label>
            <div class="mt-1">
                <input type="email" name="{{ $page }}_contact_email" id="{{ $page }}-contact-email" maxlength="120"
                       value="{{ $legal['contact_email'] }}" placeholder="hello@example.com" @disabled(! $canEdit)
                       class="{{ $input }}">
            </div>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Shown under the page so people know who to write to. Leave empty to show none.') }}</p>
        </div>
    </div>
</section>
