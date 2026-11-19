<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col items-start gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white">{{ __('Audit entry') }} #{{ $entry->id }}</h2>
                <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ $entry->description }}@if ($entry->subject_label) · {{ $entry->subject_label }}@endif</p>
            </div>

            <a href="{{ route('audit-logs.index') }}"
               class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                {{ __('Back to the list') }}
            </a>
        </div>
    </x-slot>

    @php
        $card = 'overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card dark:border-gray-700 dark:bg-gray-800';
        $dt = 'text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400';
        $dd = 'mt-0.5 break-words text-sm text-gray-900 dark:text-white';
        $th = 'border-b border-gray-100 px-4 py-2 text-left text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:border-gray-700 dark:text-gray-400';
        $text = fn ($value) => $value === null ? '—' : (string) $value;
    @endphp

    <div class="container mx-auto max-w-4xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
        <div class="{{ $card }} p-5">
            <dl class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                <div>
                    <dt class="{{ $dt }}">{{ __('When') }}</dt>
                    <dd class="{{ $dd }}">{{ \Illuminate\Support\Carbon::parse($entry->created_at)->format('M j, Y H:i:s') }}</dd>
                </div>
                <div>
                    <dt class="{{ $dt }}">{{ __('Person') }}</dt>
                    <dd class="{{ $dd }}">
                        @if ($entry->user_id)
                            <a href="{{ route('audit-logs.index', ['user' => $entry->user_id]) }}" class="hover:underline">{{ $entry->user_name ?: __('Unknown') }}</a>
                        @else
                            {{ $entry->user_name ?: ($entry->event === 'system' ? __('System') : __('Not signed in')) }}
                        @endif
                        @if ($entry->user_email)
                            <span class="text-gray-500 dark:text-gray-400">· {{ $entry->user_email }}</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="{{ $dt }}">{{ __('Activity') }}</dt>
                    <dd class="{{ $dd }}">{{ $entry->description }} <span class="text-gray-500 dark:text-gray-400">({{ $entry->action }})</span></dd>
                </div>
                <div>
                    <dt class="{{ $dt }}">{{ __('Result') }}</dt>
                    <dd class="{{ $dd }}">{{ ucfirst($entry->outcome) }}@if ($entry->status) · HTTP {{ $entry->status }}@endif</dd>
                </div>
                @if ($entry->message)
                    <div class="sm:col-span-2">
                        <dt class="{{ $dt }}">{{ __('The app answered') }}</dt>
                        <dd class="{{ $dd }}">{{ $entry->message }}</dd>
                    </div>
                @endif
                @if ($entry->subject_type)
                    <div>
                        <dt class="{{ $dt }}">{{ __('Record') }}</dt>
                        <dd class="{{ $dd }}">{{ $entry->subject_type }}@if ($entry->subject_id) #{{ $entry->subject_id }}@endif @if ($entry->subject_label) · {{ $entry->subject_label }}@endif</dd>
                    </div>
                @endif
                @if ($entry->method)
                    <div>
                        <dt class="{{ $dt }}">{{ __('Request') }}</dt>
                        <dd class="{{ $dd }} font-mono text-xs">{{ $entry->method }} {{ $entry->path }}</dd>
                    </div>
                @endif
                <div>
                    <dt class="{{ $dt }}">{{ __('IP address') }}</dt>
                    <dd class="{{ $dd }}">{{ $entry->ip ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="{{ $dt }}">{{ __('Browser') }}</dt>
                    <dd class="{{ $dd }} text-xs">{{ $entry->user_agent ?: '—' }}</dd>
                </div>
            </dl>
        </div>

        @if ($entry->changes)
            <div class="{{ $card }}">
                <h3 class="px-5 pt-4 text-base font-semibold text-gray-900 dark:text-white">{{ __('What changed') }}</h3>
                <div class="overflow-x-auto p-2">
                    <table class="min-w-full">
                        <thead>
                            <tr>
                                <th scope="col" class="{{ $th }}">{{ __('Field') }}</th>
                                <th scope="col" class="{{ $th }}">{{ __('Before') }}</th>
                                <th scope="col" class="{{ $th }}">{{ __('After') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach ($entry->changes as $name => $pair)
                                <tr>
                                    <td class="px-4 py-2 align-top font-mono text-xs text-gray-900 dark:text-white">{{ $name }}</td>
                                    <td class="px-4 py-2 align-top text-sm break-words text-gray-600 dark:text-gray-300">{{ $text($pair[0] ?? null) }}</td>
                                    <td class="px-4 py-2 align-top text-sm break-words text-gray-900 dark:text-white">{{ $text($pair[1] ?? null) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @if ($entry->input)
            <div class="{{ $card }}">
                <h3 class="px-5 pt-4 text-base font-semibold text-gray-900 dark:text-white">{{ __('What was sent') }}</h3>
                <p class="px-5 text-xs text-gray-500 dark:text-gray-400">{{ __('Passwords and tokens are never stored; uploads show only their name.') }}</p>
                <pre class="m-4 overflow-x-auto rounded-lg bg-gray-50 p-3 text-xs text-gray-800 dark:bg-gray-900/50 dark:text-gray-200">{{ json_encode($entry->input, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
        @endif
    </div>
</x-app-layout>
