<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center justify-center rounded-xl border border-transparent bg-gray-900 px-5 py-2.5 text-sm font-normal text-white shadow-md transition hover:bg-gray-700 focus:outline-none focus:ring-4 focus:ring-gray-300 active:bg-gray-800 disabled:opacity-50 dark:bg-mist dark:text-ink dark:hover:bg-white dark:focus:ring-gray-600']) }}>
    {{ $slot }}
</button>
