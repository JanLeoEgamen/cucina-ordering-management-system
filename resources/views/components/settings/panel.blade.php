@props([
    'id',
    'title',
    'description' => null,
    'active' => false,
])

{{--
    One tab of System Configuration. Every panel stays inside the ONE form (a hidden panel is
    only `hidden`, never disabled), so Save sends all of them; settings.js shows one at a time.

        id           the tab name (also the URL hash: #notifications)
        title        the panel's heading
        description  one line under it
        active       the panel drawn first (the others start hidden)

    The card holds <x-settings.section>s, separated by a thin rule.
--}}
<div id="settings-panel-{{ $id }}" data-settings-panel="{{ $id }}" role="tabpanel" aria-labelledby="settings-tab-{{ $id }}"
     tabindex="-1" @class(['focus:outline-none', 'hidden' => ! $active])>
    <div class="mb-4">
        <h3 class="text-lg font-semibold tracking-tight text-gray-900 dark:text-white">{{ $title }}</h3>
        @if ($description)
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $description }}</p>
        @endif
    </div>

    <div class="divide-y divide-gray-100 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card dark:divide-gray-700 dark:border-gray-700 dark:bg-gray-800">
        {{ $slot }}
    </div>
</div>
