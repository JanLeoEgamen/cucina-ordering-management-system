{{--
    Sets the theme BEFORE first paint, so a dark page never flashes light. The choice
    is `cucina.theme` in localStorage ('dark' or 'light'), written by the switch
    (resources/js/ui/theme.js); until there is one, the OS setting decides.
    Included in the <head> of both layouts.

    It also prints the COLOUR theme an admin picked (System Configuration -> Branding) as CSS variables
    (the Theme class in app/Support, Theme::css(): brand scale, accent, hero). The string is built only from config/themes.php
    colours turned into "r g b" numbers, never from a request value, so printing it raw is safe. The id lets
    the settings page find the block it previews against.
--}}
<style id="theme-vars">{!! \App\Support\Theme::css() !!}</style>
<script>
    (function () {
        var dark = false;

        try {
            var stored = localStorage.getItem('cucina.theme');

            dark = stored ? stored === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
        } catch (error) {
            // localStorage can throw (private mode): fall back to the OS setting.
            dark = !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
        }

        document.documentElement.classList.toggle('dark', dark);
    })();
</script>
