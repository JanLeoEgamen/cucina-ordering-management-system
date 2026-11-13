<x-guest-layout :heading="__('Welcome back')" :subheading="__('Log in to order from the menu and follow your orders.')">
    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}" class="space-y-5">
        @csrf

        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" class="mt-1 block w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" placeholder="name@example.com" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div>
            <div class="flex items-center justify-between">
                <x-input-label for="password" :value="__('Password')" />

                @if (Route::has('password.request'))
                    <a class="rounded-md text-sm text-gray-500 underline-offset-4 transition hover:text-gray-900 hover:underline focus:outline-none focus:ring-2 focus:ring-gray-300 dark:text-gray-400 dark:hover:text-white dark:focus:ring-gray-600" href="{{ route('password.request') }}">
                        {{ __('Forgot your password?') }}
                    </a>
                @endif
            </div>

            <x-text-input id="password" class="mt-1 block w-full"
                          type="password"
                          name="password"
                          required autocomplete="current-password" />

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Remember Me -->
        <label for="remember_me" class="inline-flex cursor-pointer items-center gap-2.5">
            <input id="remember_me" type="checkbox" name="remember"
                   class="h-4 w-4 rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:focus:ring-gray-600">
            <span class="text-sm text-gray-600 dark:text-gray-400">{{ __('Keep me logged in') }}</span>
        </label>

        <x-primary-button class="w-full !py-3">
            {{ __('Log in') }}
        </x-primary-button>
    </form>

    <x-ui.google-button />

    @if (Route::has('register'))
        <x-slot name="footer">
            {{ __('New here?') }}
            <a href="{{ route('register') }}" class="font-semibold text-gray-900 underline-offset-4 hover:underline dark:text-white">{{ __('Create an account') }}</a>
        </x-slot>
    @endif
</x-guest-layout>
