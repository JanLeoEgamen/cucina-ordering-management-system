<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col items-start gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-900 dark:text-white">{{ __('Notifications') }}</h2>
                <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                    {{ $unread > 0 ? trans_choice('{1} 1 unread|[2,*] :count unread', $unread, ['count' => $unread]) : __('You are all caught up.') }}
                </p>
            </div>

            @if ($unread > 0)
                <form method="POST" action="{{ route('notifications.read-all') }}" class="w-full sm:w-auto">
                    @csrf
                    <button type="submit"
                            class="inline-flex w-full items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 sm:w-auto dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                        {{ __('Mark all as read') }}
                    </button>
                </form>
            @endif
        </div>
    </x-slot>

    <div class="container mx-auto max-w-3xl px-4 py-6 sm:px-6 lg:px-8">
        @if ($notifications->isEmpty() && $notifications->currentPage() === 1)
            <div class="rounded-xl border border-gray-200 bg-white p-10 text-center dark:border-gray-700 dark:bg-gray-800">
                <svg class="mx-auto h-10 w-10 text-gray-400 dark:text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
                </svg>
                <h3 class="mt-3 text-base font-semibold text-gray-900 dark:text-white">{{ __('No notifications yet') }}</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('When something happens to one of your orders, you will see it here.') }}</p>
            </div>
        @else
            <ul class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800" role="list">
                @foreach ($notifications as $notification)
                    @php($unreadRow = $notification->read_at === null)
                    <li class="border-b border-gray-100 last:border-b-0 dark:border-gray-700/70">
                        {{-- Opening marks it read and goes to what it is about. --}}
                        <a href="{{ route('notifications.open', $notification->id) }}"
                           class="flex items-start gap-3 px-5 py-4 transition hover:bg-gray-50 focus:bg-gray-50 focus:outline-none dark:hover:bg-gray-700/40 dark:focus:bg-gray-700/40 {{ $unreadRow ? 'bg-gray-50/70 dark:bg-gray-700/20' : '' }}">
                            <span class="mt-1.5 h-2 w-2 flex-shrink-0 rounded-full {{ $unreadRow ? 'bg-indigo-600 dark:bg-mist' : 'bg-transparent' }}" aria-hidden="true"></span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm {{ $unreadRow ? 'font-semibold text-gray-900 dark:text-white' : 'font-medium text-gray-700 dark:text-gray-300' }}">{{ $notification->title }}</span>
                                @if ($notification->body)
                                    <span class="mt-0.5 block text-sm text-gray-500 dark:text-gray-400">{{ $notification->body }}</span>
                                @endif
                                <span class="mt-1 block text-xs text-gray-400 dark:text-gray-500">
                                    <time datetime="{{ \Illuminate\Support\Carbon::parse($notification->created_at)->toIso8601String() }}">{{ \Illuminate\Support\Carbon::parse($notification->created_at)->diffForHumans() }}</time>
                                    @if ($unreadRow)
                                        <span class="sr-only">· {{ __('Unread') }}</span>
                                    @endif
                                </span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>

            {{ $notifications->links('pagination.numbered') }}
        @endif
    </div>
</x-app-layout>
