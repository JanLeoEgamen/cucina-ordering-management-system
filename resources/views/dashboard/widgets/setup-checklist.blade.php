{{-- Dashboard widget `setup-checklist`: things set up only half way. A line is built only for
     someone who may open the page that fixes it. --}}
<x-dashboard.card :title="__('Setup checklist')" class="lg:col-span-2">
    @forelse ($lines as $line)
        <a href="{{ $line['url'] }}" class="mt-2 flex items-center gap-3 rounded-xl border border-gray-100 px-3 py-2 transition hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-700/40">
            <span class="min-w-[2rem] text-lg font-bold tabular-nums text-gray-900 dark:text-white">{{ $line['count'] }}</span>
            <span class="min-w-0 text-sm text-gray-700 dark:text-gray-200">{{ $line['label'] }}</span>
        </a>
    @empty
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ __('Everything checked here is set up.') }}</p>
    @endforelse
</x-dashboard.card>
