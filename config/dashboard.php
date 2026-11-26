<?php

use App\Support\Dashboard\CheckWidgets;
use App\Support\Dashboard\CounterWidgets;
use App\Support\Dashboard\InsightWidgets;
use App\Support\Dashboard\MyWidgets;

// The dashboard's widgets: ONE entry per graph, list or number card, each behind its own permission.
// App\Support\Dashboard decides who gets which widget (and reads the data of those only), and the
// Roles page uses `row` / `label` to draw the widgets' permissions as chips on a few rows.
//
//   permission  the widget's own permission
//   requires    optional: drawn only for someone who ALSO holds at least one of these (the pages
//               whose rows the widget is made of)
//   section     where dashboard.blade.php puts it: top, insights, counter, mine, checks
//   row, label  its chip on the Roles page
//   view, data  the partial, and the method that reads its data (null = nothing to show)
//
// A new widget = an entry here + its permission in Role::PERMISSIONS (and docs/security.md) + the
// view + the data method + an @can block in resources/views/dashboard.blade.php + tests. A new `row`
// also goes in Role::PERMISSION_SECTIONS. No closures: `php artisan config:cache` cannot store them.
// The order here is the order of the chips.
return [

    'widgets' => [

        /* ---- beside the live session ---- */

        'attention' => [
            'permission' => 'view dashboard-attention',
            // The widget is a list of things to do, each line behind its own permission. Someone
            // holding none of these would get an empty card, so they get no card at all.
            'requires' => ['view payments', 'review discounts', 'view orders'],
            'section' => 'top',
            'row' => 'dashboard',
            'label' => 'Attention',
            'view' => 'dashboard.widgets.attention',
            'data' => [CounterWidgets::class, 'attention'],
        ],

        /* ---- smart insights, across the page ---- */

        'insights' => [
            'permission' => 'view dashboard-insights',
            // Each sentence is behind the permission of the data it is made of.
            'requires' => InsightWidgets::SOURCES,
            'section' => 'insights',
            'row' => 'dashboard',
            'label' => 'Insights',
            'view' => 'dashboard.widgets.insights',
            'data' => [InsightWidgets::class, 'insights'],
        ],

        /* ---- today at the counter ---- */

        'today' => [
            'permission' => 'view dashboard-today',
            'section' => 'counter',
            'row' => 'dashboard',
            'label' => 'Today',
            'view' => 'dashboard.widgets.today',
            'data' => [CounterWidgets::class, 'today'],
        ],

        'live-session' => [
            'permission' => 'view dashboard-live-session',
            'section' => 'counter',
            'row' => 'dashboard',
            'label' => 'Live session',
            'view' => 'dashboard.widgets.live-session',
            'data' => [CounterWidgets::class, 'liveSession'],
        ],

        'cash-expected' => [
            'permission' => 'view dashboard-cash-expected',
            'section' => 'counter',
            'row' => 'dashboard',
            'label' => 'Cash expected',
            'view' => 'dashboard.widgets.cash-expected',
            'data' => [CounterWidgets::class, 'cash'],
        ],

        'unclaimed' => [
            'permission' => 'view dashboard-unclaimed',
            // It names customers and their orders.
            'requires' => ['view orders'],
            'section' => 'counter',
            'row' => 'dashboard',
            'label' => 'Unclaimed orders',
            'view' => 'dashboard.widgets.unclaimed',
            'data' => [CounterWidgets::class, 'unclaimed'],
        ],

        /* ---- the signed-in person's own ---- */

        'my-orders' => [
            'permission' => 'view dashboard-my-orders',
            'requires' => ['view my orders'],
            'section' => 'mine',
            'row' => 'my dashboard',
            'label' => 'Active orders',
            'view' => 'dashboard.widgets.my-orders',
            'data' => [MyWidgets::class, 'orders'],
        ],

        'my-pickup' => [
            'permission' => 'view dashboard-my-pickup',
            'requires' => ['view my orders'],
            'section' => 'mine',
            'row' => 'my dashboard',
            'label' => 'Pickup checklist',
            'view' => 'dashboard.widgets.my-pickup',
            'data' => [MyWidgets::class, 'pickup'],
        ],

        'my-usual' => [
            'permission' => 'view dashboard-my-usual',
            'requires' => ['view my orders'],
            'section' => 'mine',
            'row' => 'my dashboard',
            'label' => 'Usual order',
            'view' => 'dashboard.widgets.my-usual',
            'data' => [MyWidgets::class, 'usual'],
        ],

        'my-favorites' => [
            'permission' => 'view dashboard-my-favorites',
            'requires' => ['view my orders'],
            'section' => 'mine',
            'row' => 'my dashboard',
            'label' => 'Favourites',
            'view' => 'dashboard.widgets.my-favorites',
            'data' => [MyWidgets::class, 'favorites'],
        ],

        'my-spending' => [
            'permission' => 'view dashboard-my-spending',
            'requires' => ['view my orders'],
            'section' => 'mine',
            'row' => 'my dashboard',
            'label' => 'Spending',
            'view' => 'dashboard.widgets.my-spending',
            'data' => [MyWidgets::class, 'spending'],
        ],

        'popular-dishes' => [
            'permission' => 'view dashboard-popular-dishes',
            'requires' => ['view menu'],
            'section' => 'mine',
            'row' => 'my dashboard',
            'label' => 'Popular dishes',
            'view' => 'dashboard.widgets.popular-dishes',
            'data' => [MyWidgets::class, 'popular'],
        ],

        'my-sellout-alert' => [
            'permission' => 'view dashboard-my-sellout-alert',
            'requires' => ['view my orders'],
            'section' => 'mine',
            'row' => 'my dashboard',
            'label' => 'Sell-out alert',
            'view' => 'dashboard.widgets.my-sellout-alert',
            'data' => [MyWidgets::class, 'selloutAlert'],
        ],

        'my-new-dishes' => [
            'permission' => 'view dashboard-my-new-dishes',
            'requires' => ['view my orders'],
            'section' => 'mine',
            'row' => 'my dashboard',
            'label' => 'New to you',
            'view' => 'dashboard.widgets.my-new-dishes',
            'data' => [MyWidgets::class, 'newDishes'],
        ],

        'my-discounts' => [
            'permission' => 'view dashboard-my-discounts',
            'requires' => ['view menu', 'view pre-order-menu'],
            'section' => 'mine',
            'row' => 'my dashboard',
            'label' => 'Discounts',
            'view' => 'dashboard.widgets.my-discounts',
            'data' => [MyWidgets::class, 'discounts'],
        ],

        /* ---- checks ---- */

        'system-health' => [
            'permission' => 'view dashboard-system-health',
            'section' => 'checks',
            'row' => 'system checks',
            'label' => 'System health',
            'view' => 'dashboard.widgets.system-health',
            'data' => [CheckWidgets::class, 'health'],
        ],

        'security' => [
            'permission' => 'view dashboard-security',
            'requires' => ['view audit-logs'],
            'section' => 'checks',
            'row' => 'system checks',
            'label' => 'Security',
            'view' => 'dashboard.widgets.security',
            'data' => [CheckWidgets::class, 'security'],
        ],

        'setup-checklist' => [
            'permission' => 'view dashboard-setup-checklist',
            'requires' => ['view dishes', 'view users', 'view payment-methods', 'view discounts'],
            'section' => 'checks',
            'row' => 'system checks',
            'label' => 'Setup checklist',
            'view' => 'dashboard.widgets.setup-checklist',
            'data' => [CheckWidgets::class, 'setup'],
        ],

    ],

];
