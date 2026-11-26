<?php

use App\Support\Analytics\CustomerWidgets;
use App\Support\Analytics\KitchenWidgets;
use App\Support\Analytics\MoneyWidgets;
use App\Support\Analytics\PlanningWidgets;
use App\Support\Analytics\SalesWidgets;

// The Analytics page's widgets: ONE entry per chart, table or number card, each behind its own
// permission. Same shape and same rules as config/dashboard.php, with `tab` instead of `section`;
// `data` is given the user and the date range (App\Support\Analytics\Period). Only the open tab's
// widgets are read. The order here is the order on the page and of the chips on the Roles page.
// A new widget also needs its CSV tables in App\Support\Analytics\Export.
return [

    'tabs' => [
        'sales' => 'Sales',
        'money' => 'Money',
        'kitchen' => 'Kitchen',
        'customers' => 'Customers',
    ],

    'widgets' => [

        /* ---- sales ---- */

        'sales-summary' => [
            'permission' => 'view analytics-sales-summary',
            'tab' => 'sales',
            'row' => 'sales analytics',
            'label' => 'Summary',
            'view' => 'analytics.widgets.sales-summary',
            'data' => [SalesWidgets::class, 'summary'],
        ],

        'sales-trend' => [
            'permission' => 'view analytics-sales-trend',
            'tab' => 'sales',
            'row' => 'sales analytics',
            'label' => 'Trend',
            'view' => 'analytics.widgets.sales-trend',
            'data' => [SalesWidgets::class, 'trend'],
        ],

        'order-times' => [
            'permission' => 'view analytics-order-times',
            'tab' => 'sales',
            'row' => 'sales analytics',
            'label' => 'Order times',
            'view' => 'analytics.widgets.order-times',
            'data' => [SalesWidgets::class, 'times'],
        ],

        'order-status' => [
            'permission' => 'view analytics-order-status',
            'tab' => 'sales',
            'row' => 'sales analytics',
            'label' => 'Order status',
            'view' => 'analytics.widgets.order-status',
            'data' => [SalesWidgets::class, 'status'],
        ],

        'payment-methods' => [
            'permission' => 'view analytics-payment-methods',
            'tab' => 'sales',
            'row' => 'sales analytics',
            'label' => 'Payment methods',
            'view' => 'analytics.widgets.payment-methods',
            'data' => [SalesWidgets::class, 'methods'],
        ],

        'basket-size' => [
            'permission' => 'view analytics-basket-size',
            'tab' => 'sales',
            'row' => 'sales analytics',
            'label' => 'Basket size',
            'view' => 'analytics.widgets.basket-size',
            'data' => [SalesWidgets::class, 'basket'],
        ],

        'cancelled-value' => [
            'permission' => 'view analytics-cancelled-value',
            'tab' => 'sales',
            'row' => 'sales analytics',
            'label' => 'Cancellations',
            'view' => 'analytics.widgets.cancelled-value',
            'data' => [SalesWidgets::class, 'cancelled'],
        ],

        /* ---- money ---- */

        'collections' => [
            'permission' => 'view analytics-collections',
            'tab' => 'money',
            'row' => 'money analytics',
            'label' => 'Collections',
            'view' => 'analytics.widgets.collections',
            'data' => [MoneyWidgets::class, 'collections'],
        ],

        'discounts' => [
            'permission' => 'view analytics-discounts',
            'tab' => 'money',
            'row' => 'money analytics',
            'label' => 'Discounts',
            'view' => 'analytics.widgets.discounts',
            'data' => [MoneyWidgets::class, 'discounts'],
        ],

        'review-time' => [
            'permission' => 'view analytics-review-time',
            'tab' => 'money',
            'row' => 'money analytics',
            'label' => 'Review times',
            'view' => 'analytics.widgets.review-time',
            'data' => [MoneyWidgets::class, 'reviewTimes'],
        ],

        'duplicate-references' => [
            'permission' => 'view analytics-duplicate-references',
            // It lists customers, their orders and their payments.
            'requires' => ['view payments'],
            'tab' => 'money',
            'row' => 'money analytics',
            'label' => 'Repeated references',
            'view' => 'analytics.widgets.duplicate-references',
            'data' => [MoneyWidgets::class, 'references'],
        ],

        'money-held' => [
            'permission' => 'view analytics-money-held',
            // It lists customers and their orders.
            'requires' => ['view orders'],
            'tab' => 'money',
            'row' => 'money analytics',
            'label' => 'Paid, not completed',
            'view' => 'analytics.widgets.money-held',
            'data' => [MoneyWidgets::class, 'held'],
        ],

        /* ---- kitchen ---- */

        'prep-suggestions' => [
            'permission' => 'view analytics-prep-suggestions',
            'tab' => 'kitchen',
            'row' => 'kitchen analytics',
            'label' => 'Prep suggestions',
            'view' => 'analytics.widgets.prep-suggestions',
            'data' => [PlanningWidgets::class, 'prep'],
        ],

        'dish-sales' => [
            'permission' => 'view analytics-dish-sales',
            'tab' => 'kitchen',
            'row' => 'kitchen analytics',
            'label' => 'Dish sales',
            'view' => 'analytics.widgets.dish-sales',
            'data' => [KitchenWidgets::class, 'dishSales'],
        ],

        'sell-through' => [
            'permission' => 'view analytics-sell-through',
            'tab' => 'kitchen',
            'row' => 'kitchen analytics',
            'label' => 'Leftovers',
            'view' => 'analytics.widgets.sell-through',
            'data' => [KitchenWidgets::class, 'sellThrough'],
        ],

        'sellout-speed' => [
            'permission' => 'view analytics-sellout-speed',
            'tab' => 'kitchen',
            'row' => 'kitchen analytics',
            'label' => 'Sell-out speed',
            'view' => 'analytics.widgets.sellout-speed',
            'data' => [KitchenWidgets::class, 'selloutSpeed'],
        ],

        'rice' => [
            'permission' => 'view analytics-rice',
            'tab' => 'kitchen',
            'row' => 'kitchen analytics',
            'label' => 'Rice',
            'view' => 'analytics.widgets.rice',
            'data' => [KitchenWidgets::class, 'rice'],
        ],

        'weekday-demand' => [
            'permission' => 'view analytics-weekday-demand',
            'tab' => 'kitchen',
            'row' => 'kitchen analytics',
            'label' => 'Weekday demand',
            'view' => 'analytics.widgets.weekday-demand',
            'data' => [PlanningWidgets::class, 'weekdays'],
        ],

        'bring-back' => [
            'permission' => 'view analytics-bring-back',
            'tab' => 'kitchen',
            'row' => 'kitchen analytics',
            'label' => 'Bring back',
            'view' => 'analytics.widgets.bring-back',
            'data' => [PlanningWidgets::class, 'bringBack'],
        ],

        'menu-fatigue' => [
            'permission' => 'view analytics-menu-fatigue',
            'tab' => 'kitchen',
            'row' => 'kitchen analytics',
            'label' => 'Menu fatigue',
            'view' => 'analytics.widgets.menu-fatigue',
            'data' => [PlanningWidgets::class, 'fatigue'],
        ],

        'new-dish-performance' => [
            'permission' => 'view analytics-new-dish-performance',
            'tab' => 'kitchen',
            'row' => 'kitchen analytics',
            'label' => 'New dishes',
            'view' => 'analytics.widgets.new-dish-performance',
            'data' => [PlanningWidgets::class, 'newDishes'],
        ],

        'pairings' => [
            'permission' => 'view analytics-pairings',
            'tab' => 'kitchen',
            'row' => 'kitchen analytics',
            'label' => 'Pairings',
            'view' => 'analytics.widgets.pairings',
            'data' => [PlanningWidgets::class, 'pairings'],
        ],

        'session-scorecard' => [
            'permission' => 'view analytics-session-scorecard',
            'tab' => 'kitchen',
            'row' => 'kitchen analytics',
            'label' => 'Session scorecard',
            'view' => 'analytics.widgets.session-scorecard',
            'data' => [KitchenWidgets::class, 'scorecard'],
        ],

        /* ---- customers ---- */

        'customers' => [
            'permission' => 'view analytics-customers',
            'tab' => 'customers',
            'row' => 'customer analytics',
            'label' => 'New and returning',
            'view' => 'analytics.widgets.customers',
            'data' => [CustomerWidgets::class, 'overview'],
        ],

        'repeat-rate' => [
            'permission' => 'view analytics-repeat-rate',
            'tab' => 'customers',
            'row' => 'customer analytics',
            'label' => 'Repeat rate',
            'view' => 'analytics.widgets.repeat-rate',
            'data' => [CustomerWidgets::class, 'repeat'],
        ],

        'never-ordered' => [
            'permission' => 'view analytics-never-ordered',
            'tab' => 'customers',
            'row' => 'customer analytics',
            'label' => 'Never ordered',
            'view' => 'analytics.widgets.never-ordered',
            'data' => [CustomerWidgets::class, 'never'],
        ],

        'companies' => [
            'permission' => 'view analytics-companies',
            'tab' => 'customers',
            'row' => 'customer analytics',
            'label' => 'Companies',
            'view' => 'analytics.widgets.companies',
            'data' => [CustomerWidgets::class, 'companies'],
        ],

        'company-participation' => [
            'permission' => 'view analytics-company-participation',
            'tab' => 'customers',
            'row' => 'customer analytics',
            'label' => 'Company participation',
            'view' => 'analytics.widgets.company-participation',
            'data' => [CustomerWidgets::class, 'participation'],
        ],

        'lapsed-customers' => [
            'permission' => 'view analytics-lapsed-customers',
            // It names customers and what they ordered.
            'requires' => ['view orders'],
            'tab' => 'customers',
            'row' => 'customer analytics',
            'label' => 'Lapsed customers',
            'view' => 'analytics.widgets.lapsed-customers',
            'data' => [CustomerWidgets::class, 'lapsed'],
        ],

        'top-customers' => [
            'permission' => 'view analytics-top-customers',
            // It names customers and what they spent.
            'requires' => ['view orders'],
            'tab' => 'customers',
            'row' => 'customer analytics',
            'label' => 'Top customers',
            'view' => 'analytics.widgets.top-customers',
            'data' => [CustomerWidgets::class, 'top'],
        ],

    ],

];
