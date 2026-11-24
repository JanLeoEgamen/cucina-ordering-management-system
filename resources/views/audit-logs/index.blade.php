<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col items-start gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
            <div>
                <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white">{{ __('Audit Trail') }}</h2>
                <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                    {{ __('Who did what, and when. Entries cannot be edited or deleted.') }}
                </p>
            </div>

            <a href="{{ $exportUrl }}"
               class="inline-flex w-full items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 sm:w-auto dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600"
               title="{{ __('The filtered list, newest first, up to :count rows.', ['count' => number_format($exportLimit)]) }}">
                {{ __('Export CSV') }}
            </a>
        </div>
    </x-slot>

    @php
        $field = 'block w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-base text-gray-900 sm:text-sm focus:border-gray-900 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:focus:border-gray-300 dark:focus:ring-gray-600';
        $label = 'mb-1 block text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400';
        $th = 'border-b border-gray-100 bg-transparent px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-gray-500 whitespace-nowrap dark:border-gray-700 dark:text-gray-400';
        $td = 'px-4 py-3 align-top text-sm text-gray-600 dark:text-gray-300';
    @endphp

    <div class="container mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        {{-- Two columns even on a phone (Type | Result, From | To) so the form is four short rows instead of
             six tall ones; the search always spans the full width; six across from lg. text-base below sm
             keeps iOS from zooming into a field. --}}
        <form method="GET" action="{{ route('audit-logs.index') }}"
              class="mb-4 grid grid-cols-2 gap-3 rounded-2xl border border-gray-200 bg-white p-3 shadow-card sm:p-4 lg:grid-cols-6 dark:border-gray-700 dark:bg-gray-800">
            @if (! empty($filters['user']))
                <input type="hidden" name="user" value="{{ $filters['user'] }}">
            @endif

            <div class="col-span-2">
                <label for="audit-q" class="{{ $label }}">{{ __('Search') }}</label>
                <input type="search" id="audit-q" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100"
                       placeholder="{{ __('Person, action, record, IP…') }}" class="{{ $field }}">
            </div>

            <div>
                <label for="audit-event" class="{{ $label }}">{{ __('Type') }}</label>
                <select id="audit-event" name="event" class="{{ $field }}">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($events as $event)
                        <option value="{{ $event }}" @selected(($filters['event'] ?? '') === $event)>{{ ucfirst($event) }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="audit-outcome" class="{{ $label }}">{{ __('Result') }}</label>
                <select id="audit-outcome" name="outcome" class="{{ $field }}">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($outcomes as $outcome)
                        <option value="{{ $outcome }}" @selected(($filters['outcome'] ?? '') === $outcome)>{{ ucfirst($outcome) }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="audit-from" class="{{ $label }}">{{ __('From') }}</label>
                <input type="date" id="audit-from" name="from" value="{{ $filters['from'] ?? '' }}" class="{{ $field }}">
            </div>

            <div>
                <label for="audit-to" class="{{ $label }}">{{ __('To') }}</label>
                <input type="date" id="audit-to" name="to" value="{{ $filters['to'] ?? '' }}" class="{{ $field }}">
            </div>

            <div class="col-span-2 flex flex-wrap items-center gap-2 lg:col-span-6">
                <button type="submit"
                        class="flex-1 rounded-full bg-indigo-600 px-5 py-2.5 text-sm sm:flex-none font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-gray-300 dark:focus:ring-gray-600">
                    {{ __('Filter') }}
                </button>

                @if ($filters)
                    <a href="{{ route('audit-logs.index') }}"
                       class="flex-1 rounded-full border border-gray-200 bg-white px-5 py-2.5 text-center text-sm font-medium text-gray-700 transition hover:bg-gray-50 sm:flex-none focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                        {{ __('Reset') }}
                    </a>
                @endif

                @if (! empty($filters['user']))
                    <span class="basis-full text-sm text-gray-500 sm:basis-auto dark:text-gray-400">{{ __('Showing one person only.') }}</span>
                @endif
            </div>
        </form>

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card dark:border-gray-700 dark:bg-gray-800">
            @if ($entries->isEmpty())
                <p class="p-10 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('No entries match.') }}</p>
            @else
                {{-- Phones: one card per entry (a six column table only scrolls sideways there). From md up the
                     table below is shown instead; both print the same data. --}}
                <ul class="divide-y divide-gray-100 md:hidden dark:divide-gray-700" aria-label="{{ __('Audit Trail') }}">
                    @foreach ($entries as $entry)
                        @php($bad = $entry->outcome !== 'success')
                        <li class="space-y-2 p-4">
                            <div class="flex items-start justify-between gap-3">
                                <time class="text-xs tabular-nums text-gray-500 dark:text-gray-400" datetime="{{ \Illuminate\Support\Carbon::parse($entry->created_at)->toIso8601String() }}">{{ \Illuminate\Support\Carbon::parse($entry->created_at)->format('M j, Y H:i:s') }}</time>
                                <span class="inline-flex shrink-0 items-center rounded-md border px-2 py-0.5 text-xs font-medium {{ $bad ? 'border-red-200 text-red-700 dark:border-red-900 dark:text-red-400' : 'border-gray-200 text-gray-600 dark:border-gray-600 dark:text-gray-300' }}">{{ ucfirst($entry->outcome) }}@if ($entry->status) · {{ $entry->status }}@endif</span>
                            </div>

                            <p class="break-words text-sm text-gray-600 dark:text-gray-300">
                                <span class="font-medium text-gray-900 dark:text-white">{{ $entry->description }}</span>
                                @if ($entry->subject_label)
                                    <span class="text-gray-500 dark:text-gray-400">· {{ $entry->subject_label }}</span>
                                @endif
                            </p>

                            @if ($entry->message)
                                <p class="break-words text-xs text-gray-500 dark:text-gray-400">{{ $entry->message }}</p>
                            @endif

                            <div class="flex items-end justify-between gap-3">
                            <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                                @if ($entry->user_id)
                                    <a href="{{ route('audit-logs.index', ['user' => $entry->user_id]) }}" class="font-medium text-gray-900 hover:underline dark:text-white">{{ $entry->user_name ?: __('Unknown') }}</a>
                                @else
                                    <span class="font-medium text-gray-900 dark:text-white">{{ $entry->user_name ?: ($entry->event === 'system' ? __('System') : __('Not signed in')) }}</span>
                                @endif
                                @if ($entry->ip)
                                    <span class="tabular-nums">{{ $entry->ip }}</span>
                                @endif
                                <span class="rounded border border-gray-200 px-1.5 text-[11px] uppercase tracking-wide dark:border-gray-600">{{ $entry->event }}</span>
                            </div>

                            <a href="{{ route('audit-logs.show', $entry->id) }}"
                               class="inline-flex min-h-10 shrink-0 items-center justify-center rounded-full border border-gray-200 px-3 py-1.5 text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-300 dark:border-gray-600 dark:text-slate-400 dark:hover:bg-slate-700/40 dark:hover:text-slate-200">{{ __('Details') }}</a>
                            </div>
                        </li>
                    @endforeach
                </ul>

                <div class="hidden overflow-x-auto md:block">
                    <table id="audit-table" class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50/70 dark:bg-gray-900/40">
                            <tr>
                                <th scope="col" class="{{ $th }}">{{ __('When') }}</th>
                                <th scope="col" class="{{ $th }}">{{ __('Person') }}</th>
                                <th scope="col" class="{{ $th }}">{{ __('Activity') }}</th>
                                <th scope="col" class="{{ $th }}">{{ __('Result') }}</th>
                                <th scope="col" class="{{ $th }} hidden xl:table-cell">{{ __('IP') }}</th>
                                <th scope="col" class="{{ $th }} text-right"><span class="sr-only">{{ __('Details') }}</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach ($entries as $entry)
                                @php($bad = $entry->outcome !== 'success')
                                <tr>
                                    <td class="{{ $td }} whitespace-nowrap tabular-nums">
                                        <time datetime="{{ \Illuminate\Support\Carbon::parse($entry->created_at)->toIso8601String() }}">{{ \Illuminate\Support\Carbon::parse($entry->created_at)->format('M j, Y H:i:s') }}</time>
                                    </td>
                                    <td class="{{ $td }}">
                                        @if ($entry->user_id)
                                            <a href="{{ route('audit-logs.index', ['user' => $entry->user_id]) }}"
                                               class="font-medium text-gray-900 hover:underline dark:text-white" title="{{ __('Everything this person did') }}">{{ $entry->user_name ?: __('Unknown') }}</a>
                                        @else
                                            <span class="font-medium text-gray-900 dark:text-white">{{ $entry->user_name ?: ($entry->event === 'system' ? __('System') : __('Not signed in')) }}</span>
                                        @endif
                                        @if ($entry->user_email)
                                            <span class="block text-xs text-gray-400 dark:text-gray-500">{{ $entry->user_email }}</span>
                                        @endif
                                        {{-- The IP column needs xl; narrower, it rides under the person. --}}
                                        @if ($entry->ip)
                                            <span class="block text-xs tabular-nums text-gray-400 xl:hidden dark:text-gray-500">{{ $entry->ip }}</span>
                                        @endif
                                    </td>
                                    <td class="{{ $td }}">
                                        <span class="font-medium text-gray-900 dark:text-white">{{ $entry->description }}</span>
                                        @if ($entry->subject_label)
                                            <span class="text-gray-500 dark:text-gray-400">· {{ $entry->subject_label }}</span>
                                        @endif
                                        @if ($entry->message)
                                            <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $entry->message }}</span>
                                        @endif
                                        <span class="mt-0.5 inline-block rounded border border-gray-200 px-1.5 text-[11px] uppercase tracking-wide text-gray-500 dark:border-gray-600 dark:text-gray-400">{{ $entry->event }}</span>
                                    </td>
                                    <td class="{{ $td }} whitespace-nowrap">
                                        <span class="inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-medium {{ $bad ? 'border-red-200 text-red-700 dark:border-red-900 dark:text-red-400' : 'border-gray-200 text-gray-600 dark:border-gray-600 dark:text-gray-300' }}">{{ ucfirst($entry->outcome) }}@if ($entry->status) · {{ $entry->status }}@endif</span>
                                    </td>
                                    <td class="{{ $td }} hidden whitespace-nowrap tabular-nums text-xs xl:table-cell">{{ $entry->ip ?: '—' }}</td>
                                    <td class="{{ $td }} text-right">
                                        <a href="{{ route('audit-logs.show', $entry->id) }}"
                                           class="rounded-full border border-gray-200 px-3 py-1.5 text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-300 dark:border-gray-600 dark:text-slate-400 dark:hover:bg-slate-700/40 dark:hover:text-slate-200">{{ __('Details') }}</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- Always drawn (a single page too): the count and the rows-per-page choice are how the reader knows how much there is. --}}
        {{ $entries->links('pagination.numbered', ['always' => true, 'perPage' => $perPage, 'perPageLinks' => $perPageLinks]) }}
    </div>
</x-app-layout>
