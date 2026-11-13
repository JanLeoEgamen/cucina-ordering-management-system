<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Cucina') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- jQuery is no longer needed: DataTables 3 and its styles are bundled
             through Vite in resources/js/app.js. -->

        {{--
            Restore the collapsed sidebar before first paint. Doing it from
            Alpine instead would flash the expanded sidebar for a few frames on
            every page load. The class is the single source of truth for the
            collapsed look (see resources/css/app.css); the Alpine root below
            simply reads it back.
        --}}
        <script>
            (function () {
                try {
                    if (localStorage.getItem('cucina.sidebar.collapsed') === '1') {
                        document.documentElement.classList.add('cucina-sidebar-collapsed');
                    }
                } catch (error) {
                    // localStorage can throw (private mode); the sidebar then
                    // simply starts expanded.
                }

                // The folded sidebar groups, restored before paint for the same reason:
                // html[data-nav-closed="catalog access"] (see app.css and navigation.blade.php).
                try {
                    var closed = JSON.parse(localStorage.getItem('cucina.nav.closed') || '[]');

                    if (Array.isArray(closed) && closed.length) {
                        document.documentElement.setAttribute('data-nav-closed', closed.join(' '));
                    }
                } catch (error) {
                    // Every group simply starts open.
                }
            })();
        </script>

        @include('layouts.theme-script')

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased">
        {{--
            One Alpine root for the whole shell: the sidebar, the mobile top bar
            and the content offset all read the same state. Alpine only binds
            handlers inside an `x-data` tree, which is why the root lives here
            rather than inside the navigation partial.
        --}}
        <div class="min-h-screen bg-gray-50 dark:bg-gray-900"
             x-data="{
                open: false,
                collapsed: document.documentElement.classList.contains('cucina-sidebar-collapsed'),
                toggleSidebar() {
                    this.collapsed = ! this.collapsed;
                    document.documentElement.classList.toggle('cucina-sidebar-collapsed', this.collapsed);

                    try {
                        localStorage.setItem('cucina.sidebar.collapsed', this.collapsed ? '1' : '0');
                    } catch (error) {
                        // Ignore: the sidebar still collapses for this page.
                    }
                },
                // Fold / unfold a sidebar group (Ordering, Service, ...). The folded ones live in the
                // data-nav-closed attribute of <html>, which app.css reads, and in localStorage.
                toggleGroup(key) {
                    const root = document.documentElement;
                    const closed = (root.getAttribute('data-nav-closed') || '').split(' ').filter(Boolean);
                    const index = closed.indexOf(key);

                    if (index === -1) {
                        closed.push(key);
                    } else {
                        closed.splice(index, 1);
                    }

                    root.setAttribute('data-nav-closed', closed.join(' '));

                    const header = document.querySelector('[data-nav-group=\'' + key + '\'] button[aria-controls]');
                    header?.setAttribute('aria-expanded', index === -1 ? 'false' : 'true');

                    try {
                        // Stored for the next page; the group that holds the current page is
                        // opened on arrival whatever is stored (see layouts/navigation).
                        const stored = JSON.parse(localStorage.getItem('cucina.nav.closed') || '[]');
                        const next = Array.isArray(stored) ? stored.filter((item) => item !== key) : [];

                        if (index === -1) {
                            next.push(key);
                        }

                        localStorage.setItem('cucina.nav.closed', JSON.stringify(next));
                    } catch (error) {
                        // Ignore: the group still folds for this page.
                    }
                },
             }"
             @keydown.escape.window="open = false">
            @include('layouts.navigation')

            {{--
                pt-16 clears the fixed mobile top bar; lg:pl-64 clears the fixed
                sidebar. `.app-content` is the hook the collapsed state uses to
                shrink that offset to 5rem (resources/css/app.css).
            --}}
            <div class="app-content pt-16 transition-[padding] duration-300 ease-in-out lg:pt-0 lg:pl-64">
                {{--
                    Desktop top bar: the theme switch, the notification bell (badge + latest
                    notifications) and the account menu, always in the top right. Sticky and
                    blurred so the bell stays in sight while the page scrolls. On a phone
                    the same component lives in the fixed top bar (layouts/navigation).
                    z-30 keeps it under every drawer, modal and the sidebar.
                --}}
                <div class="sticky top-0 z-30 hidden bg-gray-50/80 backdrop-blur dark:bg-gray-900/80 lg:block">
                    <div class="mx-auto flex h-16 max-w-7xl items-center justify-end px-4 sm:px-6 lg:px-8">
                        <x-ui.account-bar id="desktop" />
                    </div>
                </div>

                <!-- Page Heading -->
                @isset($header)
                    {{-- No bar: the title sits straight on the paper, like the pages it introduces. --}}
                    <header>
                        <div class="max-w-7xl mx-auto px-4 pb-1 pt-8 sm:px-6 lg:px-8 lg:pt-2">
                            {{ $header }}
                        </div>
                    </header>
                @endisset

                <!-- Page Content -->
                <main>
                    {{ $slot }}
                </main>
            </div>

            @include('components.ui.notification')
        </div>

        <x-ui.image-viewer />

        {{-- Page level scripts (the module views use @push('scripts')). --}}
        @stack('scripts')
    </body>
</html>
