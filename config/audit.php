<?php

return [

    /*
     * The audit trail (docs/security.md, "Audit trail"). AUDIT_ENABLED=false switches the
     * whole thing off (nothing is written); the viewer page keeps working on what exists.
     */
    'enabled' => (bool) env('AUDIT_ENABLED', true),

    /*
     * Also log the pages a person opens (one row per full page load, never the AJAX polls
     * and table feeds). OFF by default: navigation is the bulk of the rows and says little.
     * Changes, refusals and sign in / out / failed sign in are logged either way (they do not
     * depend on this switch). Turn on with AUDIT_LOG_VIEWS=true.
     */
    'log_views' => (bool) env('AUDIT_LOG_VIEWS', false),

    /*
     * Days an entry is kept; `php artisan audit:prune` (scheduled daily) deletes older ones.
     * 0 keeps everything for ever.
     */
    'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 365),

    /*
     * Routes never logged: sign out has its own entry (from the auth event), the rest are
     * the polls that would only add noise.
     */
    'skip_routes' => [
        'logout',
        'notifications.feed',
        'notifications.open',
        'menu.stock',
    ],

    /*
     * GET routes that are not pages but still matter: they are logged as an action.
     */
    'always_log' => [
        'audit-logs.export',
    ],

    /*
     * A field whose name CONTAINS one of these is stored as "[hidden]" in the input.
     */
    'hidden_fields' => ['password', 'token', 'secret', 'pin', 'card', 'cvv'],

    /*
     * The record a route is about, found by the route prefix (`users.destroy` -> `users`) and
     * the `{id}` in the URL. It is read BEFORE the request runs (so a record that is deleted
     * still has its label) and again AFTER (so the difference can be stored).
     *
     *   table / key  where the record lives
     *   label        the columns that name it, joined with a space
     *   hide         columns never copied into a log row
     */
    'subjects' => [
        'users' => ['table' => 'users', 'key' => 'id', 'label' => ['first_name', 'last_name'], 'hide' => ['password', 'remember_token', 'google_id']],
        'roles' => ['table' => 'roles', 'key' => 'id', 'label' => ['name'], 'hide' => []],
        'companies' => ['table' => 'companies', 'key' => 'company_id', 'label' => ['company_name'], 'hide' => []],
        'dishes' => ['table' => 'dishes', 'key' => 'dish_id', 'label' => ['dish_name'], 'hide' => []],
        'dish-types' => ['table' => 'dish_types', 'key' => 'dish_type_id', 'label' => ['type_name'], 'hide' => []],
        'payment-methods' => ['table' => 'payment_methods', 'key' => 'method_id', 'label' => ['method_name'], 'hide' => []],
        'order-sessions' => ['table' => 'order_sessions', 'key' => 'session_id', 'label' => ['order_date', 'start_time'], 'hide' => []],
        'pre-order-sessions' => ['table' => 'pre_order_sessions', 'key' => 'pre_order_session_id', 'label' => ['title'], 'hide' => []],
        // The payments and my-orders routes carry the ORDER id.
        'orders' => ['table' => 'orders', 'key' => 'order_id', 'label' => ['order_number'], 'hide' => []],
        'payments' => ['table' => 'orders', 'key' => 'order_id', 'label' => ['order_number'], 'hide' => []],
        'my-orders' => ['table' => 'orders', 'key' => 'order_id', 'label' => ['order_number'], 'hide' => []],
    ],

    /*
     * What each change is called in the log. A route that is not here is still logged, under
     * its headline ("Dishes Store"); add a line to give it a proper sentence.
     */
    'actions' => [
        'users.store' => 'Created a user',
        'users.update' => 'Edited a user',
        'users.destroy' => 'Deleted a user',
        'companies.store' => 'Created a company',
        'companies.update' => 'Edited a company',
        'companies.destroy' => 'Deleted a company',
        'roles.store' => 'Created a role',
        'roles.update' => 'Edited a role',
        'roles.destroy' => 'Deleted a role',
        'dishes.store' => 'Created a dish',
        'dishes.update' => 'Edited a dish',
        'dishes.destroy' => 'Deleted a dish',
        'dish-types.store' => 'Created a dish type',
        'dish-types.update' => 'Edited a dish type',
        'dish-types.destroy' => 'Deleted a dish type',
        'payment-methods.store' => 'Created a payment method',
        'payment-methods.update' => 'Edited a payment method',
        'payment-methods.destroy' => 'Deleted a payment method',
        'payments.verify' => 'Verified a payment',
        'payments.reject' => 'Rejected a payment',
        'payments.undo' => 'Undid a payment decision',
        'payments.destroy' => 'Deleted a payment',
        'order-sessions.store' => 'Created an order session',
        'order-sessions.update' => 'Edited an order session',
        'order-sessions.end' => 'Ended an order session',
        'order-sessions.destroy' => 'Deleted an order session',
        'pre-order-sessions.store' => 'Created a pre-order session',
        'pre-order-sessions.update' => 'Edited a pre-order session',
        'pre-order-sessions.end' => 'Ended a pre-order session',
        'pre-order-sessions.destroy' => 'Deleted a pre-order session',
        'orders.store' => 'Placed an order',
        'orders.complete' => 'Completed an order',
        'orders.ready' => 'Announced an order as ready for pick up',
        'order-sessions.announce-ready' => 'Announced the orders of a session as ready for pick up',
        'orders.cancel' => 'Cancelled an order',
        'pre-orders.store' => 'Placed a pre-order',
        'my-orders.payment.proof' => 'Sent a new payment proof',
        'my-orders.payment.cash' => 'Switched an order to cash on pick up',
        'my-orders.cancel' => 'Cancelled their own order',
        'notifications.read-all' => 'Marked every notification as read',
        'settings.update' => 'Changed the system configuration',
        'profile.update' => 'Updated their profile',
        'profile.destroy' => 'Deleted their own account',
        'profile.complete.store' => 'Completed their profile',
        'password.update' => 'Changed their password',
        'verification.send' => 'Asked for a new verification email',
    ],
];
