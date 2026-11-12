@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'rounded-xl border-gray-200 bg-gray-50 px-3.5 py-2.5 text-gray-900 shadow-none transition placeholder-gray-400 focus:border-gray-900 focus:bg-white focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700/60 dark:text-white dark:focus:border-gray-300 dark:focus:bg-gray-700 dark:focus:ring-gray-600']) }}>
