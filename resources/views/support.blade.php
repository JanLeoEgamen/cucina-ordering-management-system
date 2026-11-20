{{--
    The public Help and Support page (SupportController): the ways to reach the shop (only the ones an
    admin filled in) over the help text. $html is already safe (App\Support\SafeMarkdown), so it is the
    one thing printed unescaped here; the contact details are escaped like everything else.
--}}
<x-public-page :title="__('Help and Support')">
    <h1 class="text-3xl font-bold tracking-tight">{{ __('Help and Support') }}</h1>

    @if ($email !== '' || $phone !== '' || $hours !== '')
        <section aria-labelledby="support-contact" class="mt-6 rounded-xl border border-gray-200 bg-gray-50 p-5 dark:border-gray-700 dark:bg-gray-900/40">
            <h2 id="support-contact" class="text-sm font-semibold text-gray-500 dark:text-gray-400">{{ __('Contact us') }}</h2>

            <dl class="mt-3 space-y-2 text-sm">
                @if ($email !== '')
                    <div class="flex flex-wrap gap-x-3">
                        <dt class="w-16 shrink-0 font-medium text-gray-500 dark:text-gray-400">{{ __('Email') }}</dt>
                        <dd><a href="mailto:{{ $email }}" class="font-semibold text-gray-900 underline underline-offset-4 dark:text-white">{{ $email }}</a></dd>
                    </div>
                @endif

                @if ($phone !== '')
                    <div class="flex flex-wrap gap-x-3">
                        <dt class="w-16 shrink-0 font-medium text-gray-500 dark:text-gray-400">{{ __('Phone') }}</dt>
                        <dd>
                            @if ($phoneHref !== '')
                                <a href="tel:{{ $phoneHref }}" class="font-semibold text-gray-900 underline underline-offset-4 dark:text-white">{{ $phone }}</a>
                            @else
                                {{ $phone }}
                            @endif
                        </dd>
                    </div>
                @endif

                @if ($hours !== '')
                    <div class="flex flex-wrap gap-x-3">
                        <dt class="w-16 shrink-0 font-medium text-gray-500 dark:text-gray-400">{{ __('Hours') }}</dt>
                        <dd class="text-gray-800 dark:text-gray-200">{{ $hours }}</dd>
                    </div>
                @endif
            </dl>
        </section>
    @endif

    <div class="legal-prose mt-8">
        {!! $html !!}
    </div>
</x-public-page>
