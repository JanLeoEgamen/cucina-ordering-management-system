@props(['companies' => [], 'selected' => null, 'employeeId' => null, 'autofocus' => false])

{{--
    Company dropdown + Employee ID of the register and complete-profile forms. Everyone whose
    company is not Guest must give their employee id (Company::employeeIdRules is the real check;
    this only hides the box for Guest, and without JavaScript the box simply stays visible).
--}}
@php
    $guestId = collect($companies)->firstWhere('name', \App\Models\Company::GUEST)['id'] ?? 0;
@endphp

<div class="space-y-5" x-data="{ company: '{{ (int) $selected ?: '' }}', guest: '{{ $guestId }}' }">
    <div>
        <x-input-label for="company_id" :value="__('Company')" />
        <x-company-select class="mt-1" x-model="company" :companies="$companies" :selected="$selected" required :autofocus="$autofocus" autocomplete="organization" />
        <x-input-error :messages="$errors->get('company_id')" class="mt-2" />
    </div>

    <div x-show="company === '' || company !== guest" x-transition.opacity>
        <x-input-label for="employee_id" :value="__('Employee ID')" />
        <x-text-input id="employee_id" class="mt-1 block w-full" type="text" name="employee_id" :value="old('employee_id', $employeeId)" maxlength="50" autocomplete="off" x-bind:required="company !== guest" x-bind:disabled="company !== '' && company === guest" />
        <x-input-error :messages="$errors->get('employee_id')" class="mt-2" />
    </div>
</div>
