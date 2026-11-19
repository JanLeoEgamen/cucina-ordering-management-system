/**
 * Alpine data for <x-password-input> (resources/views/components/password-input.blade.php):
 * the show / hide switch and, when the field asks for it (`meter`), the strength indicator.
 *
 * The rules mirror the server's (AppServiceProvider: Password::min(8)->letters()->numbers()
 * ->symbols()) with the SAME Unicode classes Laravel uses, so the list never says "done" for
 * something the server then refuses. The server stays the judge; this is only a guide.
 *
 *   Weak   - one of the four rules is not met yet
 *   Good   - all four are met (the form will accept it)
 *   Strong - all four, at least 12 characters, and both lower and upper case
 */
import Alpine from 'alpinejs';

const LETTER = /\p{L}/u;
const NUMBER = /\p{N}/u;
const SYMBOL = /[\p{Z}\p{S}\p{P}]/u; // Laravel's symbols() = separator, symbol or punctuation
const LOWER = /\p{Ll}/u;
const UPPER = /\p{Lu}/u;

// The level of a value: 0 (empty), 1 weak, 2 good, 3 strong.
export const passwordLevel = (value) => {
    if (!value) {
        return 0;
    }

    const met = LETTER.test(value) && NUMBER.test(value) && SYMBOL.test(value) && [...value].length >= 8;

    if (!met) {
        return 1;
    }

    return [...value].length >= 12 && LOWER.test(value) && UPPER.test(value) ? 3 : 2;
};

Alpine.data('passwordField', (labels = {}) => ({
    show: false,
    value: '',
    labels,

    init() {
        // A password manager may have filled the field before Alpine started.
        this.value = this.$refs.input?.value ?? '';
    },

    get level() {
        return passwordLevel(this.value);
    },

    get levelLabel() {
        // Index 0 is the empty field: no word until something is typed.
        return ['', this.labels.weak, this.labels.good, this.labels.strong][this.level];
    },

    // One entry per rule, in the order of the checklist under the field (the view prints the words).
    get rules() {
        return [
            { met: [...this.value].length >= 8 },
            { met: LETTER.test(this.value) },
            { met: NUMBER.test(this.value) },
            { met: SYMBOL.test(this.value) },
        ];
    },
}));
