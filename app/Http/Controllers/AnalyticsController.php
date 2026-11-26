<?php

namespace App\Http\Controllers;

use App\Support\Analytics;
use App\Support\Analytics\Export;
use App\Support\Analytics\Period;
use App\Support\Memo;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Symfony\Component\HttpFoundation\StreamedResponse;

// The Analytics page: history and trends over a date range, one tab at a time. Read only.
class AnalyticsController extends Controller implements HasMiddleware
{
    // Any analytics permission opens the page; each widget is then judged on its own. Taking one
    // out as a CSV needs the export permission as well.
    public static function middleware(): array
    {
        return [
            new Middleware('permission:' . implode('|', Analytics::permissions())),
            new Middleware('permission:' . Analytics::EXPORT, only: ['export']),
        ];
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $tabs = Analytics::tabsFor($user);

        // Holding only widgets whose pages they may not open leaves nothing to show.
        abort_if($tabs === [], 403);

        // A tab that is unknown or not theirs opens the first one they do have. (A query string
        // can send a list where a word is expected.)
        $tab = $request->query('tab');
        $tab = is_string($tab) && array_key_exists($tab, $tabs) ? $tab : array_key_first($tabs);

        $period = Period::fromInput($request->query());
        $widgets = Analytics::widgetsFor($user, $tab, $period);
        $canExport = $user->can(Analytics::EXPORT);

        // Each card's "Download CSV" link, for who may export.
        foreach ($widgets as $key => &$widget) {
            $widget['data']['csv'] = $canExport
                ? route('analytics.export', ['widget' => $key] + $period->query())
                : null;
        }

        unset($widget);

        return view('analytics.index', [
            'tabs' => $tabs,
            'tab' => $tab,
            'period' => $period,
            'widgets' => $widgets,
        ]);
    }

    // One widget's data as a CSV file. Taking data out is itself an audit trail entry
    // (`analytics.export` is in config('audit.always_log')).
    public function export(Request $request): StreamedResponse
    {
        $key = $request->query('widget');
        $key = is_string($key) ? $key : '';
        $widget = config('analytics.widgets')[$key] ?? null;

        abort_if(! $widget, 404);
        abort_unless(Analytics::canSee($request->user(), $key), 403);

        $period = Period::fromInput($request->query())->forExport();
        $tables = Export::tables($key, Memo::during(fn () => call_user_func($widget['data'], $request->user(), $period)));
        $range = $period->label();

        return response()->streamDownload(function () use ($tables, $range) {
            $out = fopen('php://output', 'w');

            // The mark that tells Excel the file is UTF-8 (peso signs, accented names).
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [__('Date range'), $range]);
            fputcsv($out, [__('Made on'), now()->format('Y-m-d H:i')]);

            foreach ($tables as [$title, $headings, $rows]) {
                fputcsv($out, []);
                fputcsv($out, [Export::cell($title)]);
                fputcsv($out, array_map(Export::cell(...), $headings));

                foreach ($rows as $row) {
                    fputcsv($out, array_map(Export::cell(...), $row));
                }
            }

            fclose($out);
        }, 'analytics-' . $key . '-' . $period->fromDate() . '-to-' . $period->toDate() . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
