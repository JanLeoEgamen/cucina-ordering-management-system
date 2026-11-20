{{--
    A public legal page, the privacy policy or the terms of service (LegalPageController). The text is
    written by an admin in System Configuration -> Privacy policy / Terms of service as Markdown; the
    controller has already turned it into safe HTML ($html, App\Support\SafeMarkdown), so it is the one
    thing printed unescaped here. Styled by `.legal-prose` in app.css.
--}}
<x-public-page :title="$title">
    <h1 class="text-3xl font-bold tracking-tight">{{ $title }}</h1>

    @if ($updatedAt)
        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
            {{ __('Last updated :date', ['date' => $updatedAt->translatedFormat('F j, Y')]) }}
        </p>
    @endif

    <div class="legal-prose mt-8">
        {!! $html !!}
    </div>

    @if ($contactEmail !== '')
        <div class="mt-10 border-t border-gray-100 pt-6 dark:border-gray-700">
            <h2 class="text-lg font-semibold">{{ __('Questions?') }}</h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                {{ __('Write to us at') }}
                <a href="mailto:{{ $contactEmail }}" class="font-semibold text-gray-900 underline underline-offset-4 dark:text-white">{{ $contactEmail }}</a>.
            </p>
        </div>
    @endif
</x-public-page>
