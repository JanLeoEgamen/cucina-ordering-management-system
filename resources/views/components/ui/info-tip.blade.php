@props([
    'text',
    'label' => null,
])

{{--
    A small "i" that holds an explanation, so a page is not buried in grey help text.

        text   the sentence(s) shown in the bubble (printed escaped)
        label  the button's accessible name (default "More information")

    Opens on hover or keyboard focus and stays open after a click (a tap on a phone); Escape, a click
    elsewhere or a scroll closes it. The bubble is `position: fixed`, placed from the icon's rectangle
    (below it, above when there is no room), so a card with `overflow: hidden` cannot clip it and a
    sticky bar cannot cover it. `type="button"`: inside a form it must never submit.
--}}
<span {{ $attributes->class(['relative inline-flex align-middle']) }}
      x-data="{
          open: false, pinned: false, top: 0, left: 0, width: 288,
          show() {
              const rect = this.$refs.icon.getBoundingClientRect();
              this.width = Math.min(288, window.innerWidth - 24);
              this.left = Math.max(12, Math.min(rect.left - 10, window.innerWidth - this.width - 12));
              this.top = rect.bottom + 8;
              this.open = true;
              this.$nextTick(() => {
                  const height = this.$refs.bubble.offsetHeight;
                  if (rect.bottom + 8 + height > window.innerHeight - 12 && rect.top - 8 - height > 12) {
                      this.top = rect.top - 8 - height;
                  }
              });
          },
          hide() { this.open = false; this.pinned = false; },
      }"
      @mouseenter="show()"
      @mouseleave="if (! pinned) open = false"
      @keydown.escape.window="hide()"
      @click.outside="hide()"
      @scroll.window.capture="open && hide()"
      @resize.window="open && hide()">
    <button type="button" x-ref="icon"
            @focus="show()"
            @blur="if (! pinned) open = false"
            @click="pinned = ! pinned; pinned ? show() : (open = false)"
            :aria-expanded="open ? 'true' : 'false'"
            aria-label="{{ $label ?? __('More information') }}"
            class="inline-flex h-5 w-5 items-center justify-center rounded-full text-gray-400 transition hover:text-gray-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-gray-200 dark:text-gray-500 dark:hover:text-gray-200 dark:focus-visible:ring-gray-600">
        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="10" cy="10" r="7.5"/>
            <path d="M10 9v4.5"/>
            <path d="M10 6.5h.01"/>
        </svg>
    </button>

    <span x-ref="bubble" x-show="open" x-cloak x-transition.opacity.duration.120ms role="tooltip"
          :style="`top: ${top}px; left: ${left}px; width: ${width}px`"
          class="fixed z-[95] rounded-xl border border-gray-200 bg-white px-3.5 py-2.5 text-xs font-normal leading-relaxed text-gray-600 shadow-card-lift dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">{{ $text }}</span>
</span>
