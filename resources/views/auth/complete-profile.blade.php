<x-guest-layout :heading="__('One last step')" :subheading="__('Tell us a little more so we can finish setting up your account.')">
    <p class="mb-5 text-sm text-gray-500 dark:text-gray-400">
        {{ __('Signed in as') }} <span class="font-medium text-gray-900 dark:text-white">{{ $user->email }}</span>
    </p>

    <form method="POST" action="{{ route('profile.complete.store') }}" class="space-y-5">
        @csrf

        <!-- First and last name (filled from Google, fix them if they are wrong) -->
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <div>
                <x-input-label for="first_name" :value="__('First Name')" />
                <x-text-input id="first_name" class="mt-1 block w-full" type="text" name="first_name" :value="old('first_name', $user->first_name)" required autocomplete="given-name" />
                <x-input-error :messages="$errors->get('first_name')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="last_name" :value="__('Last Name')" />
                <x-text-input id="last_name" class="mt-1 block w-full" type="text" name="last_name" :value="old('last_name', $user->last_name)" required autocomplete="family-name" />
                <x-input-error :messages="$errors->get('last_name')" class="mt-2" />
            </div>
        </div>

        <!-- Company -->
        <div>
            <x-input-label for="company" :value="__('Company')" />
            <x-text-input id="company" class="mt-1 block w-full" type="text" name="company" :value="old('company', $user->company)" required autofocus autocomplete="organization" />
            <x-input-error :messages="$errors->get('company')" class="mt-2" />
        </div>

        <x-primary-button class="w-full !py-3">
            {{ __('Continue') }}
        </x-primary-button>
    </form>

    <x-slot name="footer">
        <form method="POST" action="{{ route('logout') }}" class="inline">
            @csrf
            <button type="submit" class="font-semibold text-gray-900 underline-offset-4 hover:underline dark:text-white">{{ __('Log out') }}</button>
        </form>
    </x-slot>
</x-guest-layout>
