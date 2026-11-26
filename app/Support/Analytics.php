<?php

namespace App\Support;

use App\Models\User;
use App\Support\Analytics\Period;
use App\Support\Memo;

// Which widgets of the Analytics page (config/analytics.php) a person gets. The same gate as the
// dashboard's: @can only decides what is drawn, and a widget the person may not see is never read.
class Analytics
{
    // Every analytics permission: holding none of them, there is no page to open.
    public static function permissions(): array
    {
        return array_values(array_column(config('analytics.widgets'), 'permission'));
    }

    // The tabs holding at least one widget this person may see: key => label. Reads no data.
    public static function tabsFor(User $user): array
    {
        $allowed = array_column(self::allowed($user), 'tab');

        return array_filter(config('analytics.tabs'), fn (string $tab) => in_array($tab, $allowed, true), ARRAY_FILTER_USE_KEY);
    }

    // The permission, on top of a widget's own, that lets its data be taken out as a CSV.
    public const EXPORT = 'export analytics';

    // The widgets of one tab, each with its data for the date range: key => [permission, view,
    // data].
    public static function widgetsFor(User $user, string $tab, Period $period): array
    {
        // Rows several widgets share are read once for the whole tab.
        return Memo::during(fn () => self::readWidgets($user, $tab, $period));
    }

    private static function readWidgets(User $user, string $tab, Period $period): array
    {
        $widgets = [];

        foreach (self::allowed($user) as $key => $widget) {
            if ($widget['tab'] !== $tab) {
                continue;
            }

            $widgets[$key] = [
                'permission' => $widget['permission'],
                'view' => $widget['view'],
                'data' => call_user_func($widget['data'], $user, $period),
            ];
        }

        return $widgets;
    }

    // May this person see this widget (its permission and the pages it needs)?
    public static function canSee(User $user, string $key): bool
    {
        return array_key_exists($key, self::allowed($user));
    }

    private static function allowed(User $user): array
    {
        return array_filter(config('analytics.widgets'), fn (array $widget) => Dashboard::allows($user, $widget));
    }
}
