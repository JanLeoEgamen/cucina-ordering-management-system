@props([
    'name',
    'id',
    'checked' => false,
    'disabled' => false,
])

{{-- An on / off switch. A 0 is always sent first, so switching off is saved (an unticked box posts nothing). --}}
<input type="hidden" name="{{ $name }}" value="0">
<label class="relative inline-flex shrink-0 cursor-pointer items-center">
    <input type="checkbox" role="switch" name="{{ $name }}" id="{{ $id }}" value="1"
           class="peer sr-only" @checked($checked) @disabled($disabled)>
    <span class="h-6 w-11 rounded-full bg-gray-300 transition peer-checked:bg-indigo-600 peer-focus-visible:ring-4 peer-focus-visible:ring-gray-300 peer-disabled:opacity-60 dark:bg-gray-600 dark:peer-focus-visible:ring-gray-600"></span>
    <span class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-5 motion-reduce:transition-none"></span>
</label>
