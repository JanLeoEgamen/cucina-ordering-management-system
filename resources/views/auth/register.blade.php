<x-guest-layout :heading="__('Create your account')" :subheading="__('Sign up to order from the live menu and pay cash on pickup or online.')">
    <form method="POST" action="{{ route('register') }}" class="space-y-5">
        @csrf

        <!-- First and last name -->
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <div>
                <x-input-label for="first_name" :value="__('First Name')" />
                <x-text-input id="first_name" class="mt-1 block w-full" type="text" name="first_name" :value="old('first_name')" required autofocus autocomplete="given-name" />
                <x-input-error :messages="$errors->get('first_name')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="last_name" :value="__('Last Name')" />
                <x-text-input id="last_name" class="mt-1 block w-full" type="text" name="last_name" :value="old('last_name')" required autocomplete="family-name" />
                <x-input-error :messages="$errors->get('last_name')" class="mt-2" />
            </div>
        </div>

        <!-- Company (optional) -->
        <div>
            <x-input-label for="company" :value="__('Company')" />
            <x-text-input id="company" class="mt-1 block w-full" type="text" name="company" :value="old('company')" autocomplete="organization" :placeholder="__('Optional')" />
            <x-input-error :messages="$errors->get('company')" class="mt-2" />
        </div>

        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" class="mt-1 block w-full" type="email" name="email" :value="old('email')" required autocomplete="username" placeholder="name@example.com" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div>
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input id="password" class="mt-1 block w-full"
                          type="password"
                          name="password"
                          required autocomplete="new-password" />
            <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">{{ __('At least 8 characters.') }}</p>
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Confirm Password -->
        <div>
            <x-input-label for="password_confirmation" :value="__('Confirm Password')" />
            <x-text-input id="password_confirmation" class="mt-1 block w-full"
                          type="password"
                          name="password_confirmation" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <x-primary-button class="w-full !py-3">
            {{ __('Create account') }}
        </x-primary-button>
    </form>

    <x-ui.google-button />

    <x-slot name="footer">
        {{ __('Already have an account?') }}
        <a href="{{ route('login') }}" class="font-semibold text-gray-900 underline-offset-4 hover:underline dark:text-white">{{ __('Log in') }}</a>
    </x-slot>
</x-guest-layout>
