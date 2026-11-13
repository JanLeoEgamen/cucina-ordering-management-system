{{--
    Sets the theme BEFORE first paint, so a dark page never flashes light. The choice
    is `cucina.theme` in localStorage ('dark' or 'light'), written by the switch
    (resources/js/ui/theme.js); until there is one, the OS setting decides.
    Included in the <head> of both layouts.
--}}
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
