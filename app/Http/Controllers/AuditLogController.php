<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

// The audit trail, read only: the list with its filters, one entry in full, and a CSV of the
// filtered list. There is no create / edit / delete action on purpose, and no route for one.
class AuditLogController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:view audit-logs'),
        ];
    }

    // The list, newest first, a page at a time.
    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $perPage = $this->perPage($request);

        $entries = AuditLog::page($filters, $perPage);

        // A page past the end (the filter got narrower, an old link): the last one.
        if ($entries->currentPage() > $entries->lastPage()) {
            return redirect($entries->appends($request->query())->url($entries->lastPage()));
        }

        // "Rows per page": one link per size, keeping every filter, back on page 1 (the page
        // the reader was on means something else at another size).
        $perPageLinks = [];

        foreach (AuditLog::PAGE_SIZES as $size) {
            $perPageLinks[$size] = $request->fullUrlWithQuery(['per_page' => $size, 'page' => null]);
        }

        return view('audit-logs.index', [
            'entries' => $entries->appends($request->query()),
            'perPage' => $perPage,
            'perPageLinks' => $perPageLinks,
            'filters' => $filters,
            'events' => AuditLog::EVENTS,
            'outcomes' => AuditLog::OUTCOMES,
            'exportUrl' => route('audit-logs.export', array_filter($filters)),
            'exportLimit' => AuditLog::EXPORT_LIMIT,
        ]);
    }

    // One entry with what changed and what was sent.
    public function show($id)
    {
        $entry = AuditLog::findById((int) $id);

        abort_if(! $entry, 404);

        return view('audit-logs.show', ['entry' => $entry]);
    }

    // The filtered list as a CSV (at most AuditLog::EXPORT_LIMIT rows, newest first). Taking the
    // log out is itself an entry (`audit-logs.export` is in config('audit.always_log')).
    public function export(Request $request): StreamedResponse
    {
        $query = AuditLog::filtered($this->filters($request))->limit(AuditLog::EXPORT_LIMIT);

        $columns = ['id', 'created_at', 'user_name', 'user_email', 'event', 'action', 'description', 'subject_type', 'subject_id', 'subject_label', 'outcome', 'status', 'message', 'method', 'path', 'ip', 'changes', 'input'];

        return response()->streamDownload(function () use ($query, $columns) {
            $out = fopen('php://output', 'w');

            fputcsv($out, $columns);

            foreach ($query->cursor() as $row) {
                fputcsv($out, array_map(fn (string $column) => $this->csvCell($row->{$column} ?? null), $columns));
            }

            fclose($out);
        }, 'audit-log-' . now()->format('Ymd-His') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // The filters the page and the export share, validated. An unknown event / outcome is a 422
    // rather than a silent empty list.
    protected function filters(Request $request): array
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'event' => ['nullable', Rule::in(AuditLog::EVENTS)],
            'outcome' => ['nullable', Rule::in(AuditLog::OUTCOMES)],
            'user' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return array_filter($validated, fn ($value) => $value !== null && $value !== '');
    }

    // Rows per page: one of AuditLog::PAGE_SIZES, else the default. An unknown size is a 422, like
    // an unknown filter, rather than a silent fallback (and a page size can never be asked for
    // in the thousands to dump the table through the viewer: that is what the CSV export is for).
    protected function perPage(Request $request): int
    {
        $validated = $request->validate([
            'per_page' => ['nullable', Rule::in(AuditLog::PAGE_SIZES)],
        ]);

        return (int) ($validated['per_page'] ?? AuditLog::DEFAULT_PAGE_SIZE) ?: AuditLog::DEFAULT_PAGE_SIZE;
    }

    // A cell that starts with = + - @ is read as a formula by Excel, and anything typed into
    // a form ends up in this log: a leading quote makes it plain text.
    protected function csvCell(mixed $value): string
    {
        $text = (string) $value;

        return ($text !== '' && str_contains('=+-@', $text[0]) && ! is_numeric($text)) ? "'" . $text : $text;
    }
}
