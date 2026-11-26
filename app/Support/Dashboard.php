<?php

namespace App\Support;

use App\Models\User;

// Which dashboard widgets a person gets, and what each one shows. The list is config/dashboard.php;
// the data methods are in App\Support\Dashboard\ (MyWidgets, CounterWidgets, CheckWidgets).
//
// @can in the view only decides what is DRAWN. This class is the gate for the data: a widget the
// person may not see is not in the result and its data method never runs, so nothing is read for it
// and nothing can reach the page.
class Dashboard
{
    // The widgets $user may see and that have something to show: key => [section, view, data].
    // Rows several widgets share are read once for the whole pass (Memo).
    public static function widgetsFor(User $user): array
    {
        return Memo::during(fn () => self::readWidgets($user));
    }

    private static function readWidgets(User $user): array
    {
        $widgets = [];

        foreach (config('dashboard.widgets') as $key => $widget) {
            if (! self::allows($user, $widget)) {
                continue;
            }

            $data = call_user_func($widget['data'], $user);

            // null = nothing to show.
            if ($data === null) {
                continue;
            }

            $widgets[$key] = [
                'section' => $widget['section'],
                'view' => $widget['view'],
                'data' => $data,
            ];
        }

        return $widgets;
    }

    // The sections whose tab gets a dot, so a problem is not missed while another tab is open. It
    // only looks at the widgets it is given (what widgetsFor() allowed this person) and reads nothing.
    public static function sectionsNeedingALook(array $widgets): array
    {
        $health = $widgets['system-health']['data'] ?? [];
        $security = $widgets['security']['data'] ?? [];
        $myOrders = $widgets['my-orders']['data']['orders'] ?? [];

        $needs = [
            // A job stuck or failed, or a queue that could not be read.
            'checks' => ($health['stuck'] ?? 0) > 0
                || ($health['failed'] ?? 0) > 0
                || ($health['mode'] ?? null) === 'unknown'
                || ($security['lockouts'] ?? 0) > 0,
            // One of the person's own orders is waiting for them: to fix it, or to pick it up.
            'mine' => array_intersect(array_column($myOrders, 'status'), ['rejected', 'ready']) !== [],
        ];

        return array_keys(array_filter($needs));
    }

    // The widget's own permission, plus (when it names any) at least one of those it `requires`.
    // The Analytics page's widgets are judged by this too (App\Support\Analytics).
    public static function allows(User $user, array $widget): bool
    {
        if (! $user->can($widget['permission'])) {
            return false;
        }

        $requires = $widget['requires'] ?? [];

        return $requires === [] || $user->canAny($requires);
    }
}
