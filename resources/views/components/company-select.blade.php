@props(['companies' => [], 'selected' => null, 'id' => 'company_id', 'name' => 'company_id'])

{{--
    The Company dropdown of the register, complete-profile and profile forms. It is a real
    <select> (native on phones), styled like <x-text-input>. `$companies` is Company::options()
    ([id, name, is_active]); `$selected` is the person's current company id. An inactive company
    stays listed only because the person already belongs to it.
--}}
<select id="{{ $id }}" name="{{ $name }}" {{ $attributes->merge(['class' => 'block w-full rounded-xl border-gray-200 bg-gray-50 px-3.5 py-2.5 pr-10 text-gray-900 shadow-none transition focus:border-gray-900 focus:bg-white focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700/60 dark:text-white dark:focus:border-gray-300 dark:focus:bg-gray-700 dark:focus:ring-gray-600']) }}>
    <option value="">{{ __('Select your company') }}</option>
    @foreach ($companies as $company)
        <option value="{{ $company['id'] }}" @selected((int) $selected === $company['id'])>{{ $company['name'] }}@unless ($company['is_active']) ({{ __('inactive') }})@endunless</option>
    @endforeach
</select>

@if (count($companies) === 0)
    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('No companies have been added yet. Ask the staff to add yours, then try again.') }}</p>
@endif
