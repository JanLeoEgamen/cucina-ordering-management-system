{{--
    Dish collage: three tilted columns of dish tiles scrolling UP at their own speeds.

    Used by the signed-out pages (the sign-in panel in layouts/guest, the landing hero). Put it
    inside a `relative overflow-hidden` box: it fills that box and overflows it on purpose (it is
    tilted), so the box clips it. Decoration only: the wrapper should be `aria-hidden`.

        <div class="relative overflow-hidden ..."> <x-ui.dish-collage :columns="$collage" /> </div>

    `columns` comes from Dish::collageColumns(): per column, tiles of [name, image|null, type|null].
    Each column holds its tiles twice and moves half its height (the `marquee-up` keyframes in
    tailwind.config.js), so the loop has no seam. A dish with a photo is a photo tile with its
    name; one without is a typographic tile. Reduced motion stops it (app.css).
--}}
@props(['columns' => []])

<div class="absolute -inset-x-24 -inset-y-48 grid rotate-[8deg] grid-cols-3 gap-4">
    @foreach ($columns as $c => $column)
        <div class="{{ [0 => '', 1 => '-mt-28', 2 => 'mt-12'][$c] ?? '' }}">
            <div data-collage-track class="flex animate-marquee-up flex-col will-change-transform"
                 style="animation-duration: {{ [62, 80, 70][$c] ?? 66 }}s; animation-delay: -{{ [0, 26, 44][$c] ?? 0 }}s">
                @foreach ([0, 1] as $copy)
                    <div class="flex flex-col gap-4 pb-4">
                        @foreach ($column as $i => $tile)
                            @php
                                $height = ['h-52', 'h-64', 'h-44', 'h-60', 'h-48'][($i + $c * 2) % 5];
                                $tone = ['bg-white/[0.07]', 'bg-mist/[0.13]', 'bg-white/[0.12]'][($i + $c) % 3];
                            @endphp
                            <figure class="relative shrink-0 overflow-hidden rounded-2xl ring-1 ring-white/10 {{ $height }}">
                                @if ($tile['image'])
                                    <img src="{{ $tile['image'] }}" alt="" loading="lazy" decoding="async" class="h-full w-full object-cover">
                                    <figcaption class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-ink/85 to-transparent px-3.5 pb-3 pt-12 text-sm font-semibold leading-tight text-white">
                                        {{ $tile['name'] }}
                                    </figcaption>
                                @else
                                    {{-- No photo yet: the dish as a typographic tile. --}}
                                    <div class="flex h-full flex-col justify-end p-4 {{ $tone }}">
                                        <x-application-logo class="absolute right-3.5 top-3.5 h-6 w-6 fill-current text-white/20" />
                                        @if ($tile['type'])
                                            <span class="text-[11px] font-semibold uppercase tracking-wider text-white/50">{{ $tile['type'] }}</span>
                                        @endif
                                        <span class="mt-1 text-lg font-bold leading-snug tracking-tight text-white/90">{{ $tile['name'] }}</span>
                                    </div>
                                @endif
                            </figure>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach
</div>
