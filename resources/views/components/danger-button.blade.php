<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center justify-center rounded-xl border border-transparent bg-red-600 px-5 py-2.5 text-sm font-normal text-white shadow-md transition hover:bg-red-500 focus:outline-none focus:ring-4 focus:ring-red-200 active:bg-red-700 dark:focus:ring-red-900']) }}>
    {{ $slot }}
</button>
