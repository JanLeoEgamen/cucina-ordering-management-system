<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpFoundation\Response;

// Everything the audit trail needs to decide and to write: what a request was about, what
// changed, and the entry itself. Two callers: the AuditTrail middleware (every request of a
// signed-in person) and the AuditAuthEvents listener (sign in, sign out, failed sign in).
// Both wrap it in rescue(): a problem in here is logged and never reaches the person.
class Audit
{
    // The columns that change on every save and say nothing: left out of the "what changed" diff.
    protected const NOISE = ['updated_at', 'created_at'];

    /* -------- The request side -------- */

    // Called BEFORE the request runs. Returns what has to be remembered until after it
    // (who, which record and what it looked like), or null when this request is not logged.
    public static function begin(Request $request): ?array
    {
        if (! config('audit.enabled')) {
            return null;
        }

        $user = $request->user();
        $route = $request->route();

        // Guests are not logged here (their sign in has its own entries); an unknown URL has no route.
        if (! $user || ! $route) {
            return null;
        }

        $name = $route->getName();

        if ($name !== null && in_array($name, config('audit.skip_routes', []), true)) {
            return null;
        }

        $method = $request->getMethod();
        $changes = ! in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);

        if (! $changes) {
            $always = $name !== null && in_array($name, config('audit.always_log', []), true);

            // A GET is a page view: only a full page load, never a table feed or a poll.
            if ($method !== 'GET' || (! $always && (! config('audit.log_views') || $request->ajax() || $request->expectsJson()))) {
                return null;
            }
        }

        $subject = self::subjectOf($request, $name);

        return [
            'actor' => self::actor($user),
            'name' => $name,
            'changes' => $changes,
            'subject' => $subject,
            // The record as it was: the label survives a delete, the values give the diff.
            'before' => $subject ? self::load($subject) : null,
        ];
    }

    // Called AFTER the response exists (terminable middleware): writes the entry.
    public static function finish(Request $request, Response $response, ?array $context): void
    {
        if (! $context) {
            return;
        }

        $status = $response->getStatusCode();
        $name = $context['name'];
        $path = '/' . ltrim($request->path(), '/');

        if ($context['changes']) {
            $event = 'action';
            $description = (config('audit.actions')[$name] ?? null) ?? ($name ? Str::headline($name) : $request->getMethod() . ' ' . $path);
        } elseif (in_array($name, config('audit.always_log', []), true)) {
            $event = 'action';
            $description = (config('audit.actions')[$name] ?? null) ?? Str::headline((string) $name);
        } else {
            // A page view counts only when a page was shown (not a redirect, an error or a download).
            if ($status !== 200 || ! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
                return;
            }

            $event = 'view';
            $description = 'Viewed ' . self::pageName($name, $path);
        }

        $subject = $context['subject'];
        $before = $context['before'];
        $after = ($context['changes'] && $subject) ? self::load($subject) : null;
        $label = ($after['label'] ?? null) ?: ($before['label'] ?? null);

        [$message, $failedBack] = self::messageOf($response, $request);

        AuditLog::record(array_merge($context['actor'], [
            'event' => $event,
            'action' => $name ?? ($request->getMethod() . ' ' . $path),
            'description' => $description,
            'subject_type' => $subject['type'] ?? null,
            'subject_id' => $subject['id'] ?? null,
            'subject_label' => $label,
            'method' => $request->getMethod(),
            'path' => $path,
            'outcome' => self::outcome($status, $failedBack),
            'status' => $status,
            'message' => $message,
            'changes' => $context['changes'] && $subject ? self::diff($before, $after) : null,
            'input' => $context['changes'] ? self::input($request) : null,
            'ip' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ]));
    }

    // success / failed / denied / error from the status, and from "redirected back with errors"
    // (a form post that failed validation answers 302, which is not a success).
    public static function outcome(int $status, bool $failedBack = false): string
    {
        if ($status === 401 || $status === 403) {
            return 'denied';
        }

        if ($status >= 500) {
            return 'error';
        }

        return ($status >= 400 || $failedBack) ? 'failed' : 'success';
    }

    // What the app said back: the `message` of a JSON answer, or the first validation error of
    // a redirect back to a form. Returns [message, redirectedBackWithErrors].
    protected static function messageOf(Response $response, Request $request): array
    {
        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);

            return [is_array($data) && is_string($data['message'] ?? null) ? $data['message'] : null, false];
        }

        if ($response instanceof RedirectResponse && $request->hasSession()) {
            $errors = $request->session()->get('errors');

            if ($errors instanceof ViewErrorBag) {
                foreach ($errors->getBags() as $bag) {
                    if ($bag->any()) {
                        return [$bag->first(), true];
                    }
                }
            }
        }

        return [null, false];
    }

    // "users.index" -> "Users", "order-sessions.show" -> "Order Sessions (details)".
    protected static function pageName(?string $name, string $path): string
    {
        if ($name === null) {
            return $path;
        }

        $page = Str::headline(str_replace(['.index', '.show'], '', $name));

        return str_ends_with($name, '.show') ? $page . ' (details)' : $page;
    }

    /* -------- The record a request is about -------- */

    // ['type' => 'users', 'id' => 5] from the route prefix and `{id}`, when the config knows that prefix.
    protected static function subjectOf(Request $request, ?string $name): ?array
    {
        if ($name === null) {
            return null;
        }

        $type = explode('.', $name)[0];
        $id = $request->route('id');

        if (! config('audit.subjects.' . $type) || ! is_numeric($id)) {
            return null;
        }

        return ['type' => $type, 'id' => (int) $id];
    }

    // The record now: ['label' => 'Jane Doe', 'values' => [column => value]] without its hidden
    // columns, or null when it does not exist (any more). Deleted rows are read too (soft
    // deleted ones still exist; that is what shows `deleted_at` in the diff).
    protected static function load(array $subject): ?array
    {
        $config = config('audit.subjects.' . $subject['type']);

        $row = DB::table($config['table'])->where($config['key'], $subject['id'])->first();

        if (! $row) {
            return null;
        }

        $values = (array) $row;

        $label = trim(implode(' ', array_filter(array_map(fn (string $column) => (string) ($values[$column] ?? ''), $config['label']))));

        foreach (array_merge($config['hide'], self::NOISE) as $column) {
            unset($values[$column]);
        }

        return ['label' => $label, 'values' => $values];
    }

    // {field: [before, after]} for what differs. A record that is gone afterwards (a role is
    // really deleted) is said so; one that did not exist before gets no diff (a create).
    public static function diff(?array $before, ?array $after): ?array
    {
        if ($before === null) {
            return null;
        }

        if ($after === null) {
            return ['_record' => ['present', 'deleted']];
        }

        $changes = [];

        foreach (array_keys($before['values'] + $after['values']) as $field) {
            $old = $before['values'][$field] ?? null;
            $new = $after['values'][$field] ?? null;

            if ($old !== null) {
                $old = (string) $old;
            }

            if ($new !== null) {
                $new = (string) $new;
            }

            if ($old !== $new) {
                $changes[$field] = [self::shorten($old), self::shorten($new)];
            }

            if (count($changes) >= 30) {
                break;
            }
        }

        return $changes ?: null;
    }

    /* -------- What was sent -------- */

    // The request's input with secrets hidden and uploads reduced to a name.
    protected static function input(Request $request): ?array
    {
        $input = self::redact($request->except(['_token', '_method']));

        if ($input === []) {
            return null;
        }

        // A huge payload keeps only its field names: the row stays small and the insert cannot fail.
        if (strlen((string) json_encode($input, JSON_PARTIAL_OUTPUT_ON_ERROR)) > 8000) {
            return ['_truncated' => true, 'fields' => array_keys($input)];
        }

        return $input;
    }

    // Hide the value of every field whose name holds one of config('audit.hidden_fields').
    public static function redact(array $input, int $depth = 0): array
    {
        $clean = [];

        foreach (array_slice($input, 0, 60, true) as $key => $value) {
            if (is_string($key) && Str::contains(Str::lower($key), config('audit.hidden_fields', []))) {
                $clean[$key] = '[hidden]';
            } elseif ($value instanceof UploadedFile) {
                $clean[$key] = '[file] ' . $value->getClientOriginalName() . ' (' . $value->getSize() . ' bytes)';
            } elseif (is_array($value)) {
                $clean[$key] = $depth >= 4 ? '[…]' : self::redact($value, $depth + 1);
            } elseif (is_string($value)) {
                $clean[$key] = self::shorten($value, 300);
            } else {
                $clean[$key] = is_scalar($value) || $value === null ? $value : '[' . get_debug_type($value) . ']';
            }
        }

        return $clean;
    }

    protected static function shorten(?string $value, int $length = 200): ?string
    {
        return $value === null ? null : (mb_strlen($value) > $length ? mb_substr($value, 0, $length) . '…' : $value);
    }

    /* -------- The person, and the auth side -------- */

    // Copies of who did it, so the entry still reads right when the account is gone.
    public static function actor(?Authenticatable $user): array
    {
        if (! $user) {
            return ['user_id' => null, 'user_name' => null, 'user_email' => null];
        }

        return [
            'user_id' => (int) $user->getAuthIdentifier(),
            'user_name' => $user->name ?? null,
            'user_email' => $user->email ?? null,
        ];
    }

    // One sign in / sign out / failed sign in entry (event `auth`). $email is the address typed
    // when there is no account to copy it from.
    public static function auth(string $action, string $description, ?Authenticatable $user, string $outcome = 'success', ?string $email = null, ?string $message = null): void
    {
        if (! config('audit.enabled')) {
            return;
        }

        // An artisan command has no request to describe; a web request (or a test one) has a route.
        $request = request();
        $request = ($request->route() !== null || ! app()->runningInConsole()) ? $request : null;

        $actor = self::actor($user);

        if ($actor['user_email'] === null && $email !== null) {
            $actor['user_email'] = $email;
        }

        AuditLog::record(array_merge($actor, [
            'event' => 'auth',
            'action' => $action,
            'description' => $description,
            'method' => $request?->getMethod(),
            'path' => $request ? '/' . ltrim($request->path(), '/') : null,
            'outcome' => $outcome,
            'message' => $message,
            'ip' => $request?->ip(),
            'user_agent' => $request ? (string) $request->userAgent() : null,
        ]));
    }

    // A thing the system did with no person behind it (the prune command).
    public static function system(string $action, string $description): void
    {
        AuditLog::record([
            'event' => 'system',
            'action' => $action,
            'description' => $description,
        ]);
    }
}
