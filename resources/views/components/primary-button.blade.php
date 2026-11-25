<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center justify-center rounded-xl border border-transparent bg-accent px-5 py-2.5 text-sm font-normal text-accent-on shadow-md transition hover:bg-accent-hover focus:outline-none focus:ring-4 focus:ring-gray-300 active:bg-accent-hover disabled:opacity-50 dark:focus:ring-gray-600']) }}>
    {{ $slot }}
</button>
