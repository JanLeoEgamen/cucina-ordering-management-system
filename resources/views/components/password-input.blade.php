@props([
    // Draw the strength bar + rule checklist under the field (the sign up password, not the log in one).
    'meter' => false,
])

{{--
    A password field with a show / hide (eye) button and, with `meter`, a strength indicator
    (Weak / Good / Strong + a checklist of the rules). Takes the same attributes as
    <x-text-input> (id, name, required, autocomplete...); it brings its own top margin, so do not
    pass `mt-1`. The Alpine logic is resources/js/ui/password-field.js; the rules it shows are the
    server's (AppServiceProvider). Without JavaScript it stays a plain password field and the
    checklist is simply the written rules. The eye is a real <button> (Tab reaches it) and
    announces its state through aria-pressed and its label.

        <x-password-input id="password" name="password" required autocomplete="new-password" meter />
--}}
<div x-data="passwordField(@js([
        'showPassword' => __('Show password'),
        'hidePassword' => __('Hide password'),
        'weak' => __('Weak'),
        'good' => __('Good'),
        'strong' => __('Strong'),
    ]))">
    <div class="relative mt-1">
        {{-- pr-11 keeps the text clear of the button; Edge draws its own reveal icon, hidden here. --}}
        <x-text-input {{ $attributes->merge(['class' => 'block w-full pr-11 [&::-ms-reveal]:hidden', 'type' => 'password']) }}
                      x-bind:type="show ? 'text' : 'password'"
                      x-ref="input"
                      x-model="value" />

        <button type="button"
                x-on:click="show = ! show"
                x-bind:aria-pressed="show.toString()"
                x-bind:aria-label="show ? labels.hidePassword : labels.showPassword"
                x-bind:title="show ? labels.hidePassword : labels.showPassword"
                aria-label="{{ __('Show password') }}"
                title="{{ __('Show password') }}"
                class="absolute inset-y-0 right-0 flex w-11 items-center justify-center rounded-r-xl text-gray-400 transition hover:text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-300 dark:text-gray-400 dark:hover:text-white dark:focus-visible:ring-gray-600">
            {{-- Eye: the password is hidden, click to show it. --}}
            <svg x-show="! show" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                <path d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
            </svg>
            {{-- Crossed eye: the password is showing, click to hide it. --}}
            <svg x-show="show" x-cloak class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
            </svg>
        </button>
    </div>

    @if ($meter)
        {{-- Nothing until the first character is typed (an empty field has no strength to report). --}}
        <div class="mt-2.5" x-show="value.length > 0" x-cloak>
            {{-- Three segments that fill with the level, then its word. Red / amber / green is a status
                 (like the order badges), the one place colour is allowed to carry meaning. --}}
            <div class="flex items-center gap-3">
                <div class="flex flex-1 gap-1.5" aria-hidden="true">
                    @foreach ([1, 2, 3] as $segment)
                        {{-- One colour class set at a time (never grey + red together: which one wins would depend on CSS order). --}}
                        <span class="h-1.5 flex-1 rounded-full transition-colors duration-200"
                              x-bind:class="level < {{ $segment }}
                                  ? 'bg-gray-200 dark:bg-gray-600'
                                  : (level === 1 ? 'bg-red-500 dark:bg-red-400' : (level === 2 ? 'bg-amber-500 dark:bg-amber-400' : 'bg-green-600 dark:bg-green-500'))"></span>
                    @endforeach
                </div>

                <span class="w-14 text-right text-xs font-semibold"
                      role="status" aria-live="polite"
                      x-text="levelLabel"
                      x-bind:class="{
                          'text-red-600 dark:text-red-400': level === 1,
                          'text-amber-600 dark:text-amber-400': level === 2,
                          'text-green-700 dark:text-green-400': level === 3,
                      }"></span>
            </div>

            {{-- The rules, ticked off as they are met. Server rendered, so it is also the hint without JavaScript. --}}
            <ul class="mt-2 grid grid-cols-1 gap-x-4 gap-y-1 text-xs sm:grid-cols-2">
                {{-- The order here is the order of `rules` in password-field.js (length, letter, number, special). --}}
                @foreach ([
                    __('At least 8 characters'),
                    __('A letter'),
                    __('A number'),
                    __('A special character (like ! @ # ?)'),
                ] as $text)
                    <li class="flex items-center gap-1.5 text-gray-500 transition-colors dark:text-gray-400"
                        x-bind:class="rules[{{ $loop->index }}].met ? '!text-green-700 dark:!text-green-400' : ''">
                        <svg class="h-3.5 w-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path x-show="rules[{{ $loop->index }}].met" x-cloak d="m5 13 4 4L19 7" />
                            <circle x-show="! rules[{{ $loop->index }}].met" cx="12" cy="12" r="3" fill="currentColor" stroke="none" />
                        </svg>
                        <span>{{ $text }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
