<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;

// What a DataTable in `serverSide: true` mode asks for, read safely, and the word search the
// server does for it. The browser sends ONE page's worth of request (page, size, search words,
// sort column, the page's own filters) and the server answers with that page only, so a table
// with thousands of rows costs the same as one with ten (the Orders and Payments feeds used to
// send every row: 8 MB and ~200 MB of PHP memory at 5,000 orders).
//
// Used by OrderController::all and PaymentController::index; see docs/architecture.md.
class ServerTable
{
    // The most rows one request may return. The page sizes offered are 10-100; the export asks for
    // more, one chunk after the other (resources/js/ui/table-export.js).
    public const MAX_LENGTH = 500;

    // The filters both staff tables share (the page sends only the ones it has). Anything that is not
    // a known value is dropped, so a hand-made URL can only ever narrow the list.
    public static function filters(Request $request): array
    {
        // `?from[]=x` is an array: casting it to text would answer 500.
        $day = fn (string $key) => is_string($request->input($key)) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->input($key)) === 1 ? $request->input($key) : '';

        return [
            'status' => in_array($request->input('status'), ['pending', 'completed', 'cancelled', 'verified', 'rejected'], true) ? (string) $request->input('status') : '',
            'payment' => in_array($request->input('payment'), ['pending', 'verified', 'rejected'], true) ? (string) $request->input('payment') : '',
            'method' => max(0, (int) $request->input('method')),
            'session' => max(0, (int) $request->input('session')),
            'from' => $day('from'),
            'to' => $day('to'),
        ];
    }

    // draw (echoed back), start, length, the search words, the sort column index and direction.
    public static function params(Request $request): array
    {
        $length = (int) $request->input('length', 10);
        // -1 ("all") is not honoured: nothing may ask for the whole table in one go.
        $length = $length < 1 ? 10 : min($length, self::MAX_LENGTH);

        $text = mb_substr(trim((string) $request->input('search.value', '')), 0, 100);
        $order = (array) $request->input('order.0', []);

        return [
            'draw' => (int) $request->input('draw'),
            'start' => max(0, (int) $request->input('start')),
            'length' => $length,
            'words' => array_slice(array_values(array_filter(preg_split('/\s+/', $text) ?: [])), 0, 6),
            'column' => isset($order['column']) ? (int) $order['column'] : -1,
            'dir' => ($order['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc',
        ];
    }

    // Every word must be found (AND), in any of the places (OR): the way the table's own search
    // worked in the browser. $places are SQL expressions, or callables ($query, $like) that add an
    // `orWhere` for something that is not a plain column (a dish name in the order's lines).
    public static function search(Builder $query, array $words, array $places): void
    {
        foreach ($words as $word) {
            // `!` is the escape character: portable across MySQL and SQLite, unlike a backslash.
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($word)) . '%';

            $query->where(function (Builder $any) use ($places, $like) {
                foreach ($places as $place) {
                    if (is_callable($place)) {
                        $place($any, $like);
                    } else {
                        $any->orWhereRaw('LOWER(' . $place . ") LIKE ? ESCAPE '!'", [$like]);
                    }
                }
            });
        }
    }
}
