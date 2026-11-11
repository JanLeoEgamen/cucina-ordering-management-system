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
            })();
        </script>

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
        <div class="min-h-screen bg-gray-100 dark:bg-gray-900"
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
             }"
             @keydown.escape.window="open = false">
            @include('layouts.navigation')

            {{--
                pt-16 clears the fixed mobile top bar; lg:pl-64 clears the fixed
                sidebar. `.app-content` is the hook the collapsed state uses to
                shrink that offset to 5rem (resources/css/app.css).
            --}}
            <div class="app-content pt-16 transition-[padding] duration-300 ease-in-out lg:pt-0 lg:pl-64">
                <!-- Page Heading -->
                @isset($header)
                    <header class="bg-white dark:bg-gray-800 shadow">
                        <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
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
