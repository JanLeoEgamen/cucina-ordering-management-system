<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

// System configuration: the values the rest of the app starts from. For now the default
// start and end time of a new order session (the create drawer is pre-filled with them);
// the page is laid out in sections so more settings can be added beside it.
class SettingController extends Controller implements HasMiddleware
{
    // Each method only runs with the matching permission.
    public static function middleware(): array
    {
        return [
            new Middleware('permission:view settings', only: ['index']),
            new Middleware('permission:edit settings', only: ['update']),
        ];
    }

    // The configuration page.
    public function index()
    {
        return view('settings.index', [
            'sessionWindow' => Setting::sessionWindow(),
            'bulkLimits' => Setting::bulkLimits(),
            'emailNotifications' => Setting::emailNotifications(),
            // Mail is sent through the mailer of .env; `log` / `array` only record it.
            'mailer' => (string) config('mail.default'),
            // Who can be picked to receive every notification, and who is picked now.
            'users' => User::getAll(),
            'watchers' => Setting::notificationWatchers(),
        ]);
    }

    // Save the configuration.
    public function update(Request $request)
    {
        $validated = $request->validate([
            'session_start_time' => ['required', 'date_format:H:i'],
            'session_end_time' => ['required', 'date_format:H:i', 'after:session_start_time'],
            // The users who get a copy of every notification. The form always sends the
            // key (an empty entry when nobody is picked), so leaving it out - an older
            // caller - changes nothing.
            'notification_watchers' => ['nullable', 'array'],
            'notification_watchers.*' => ['nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            // Also send every notification by email. The form always sends 0 or 1; a
            // caller that leaves it out changes nothing.
            'email_notifications' => ['sometimes', 'boolean'],
            // The plates one bulk order may hold. Sent together by the form; an older
            // caller that sends neither changes nothing. An empty maximum = no limit.
            'bulk_min_quantity' => ['sometimes', 'required', 'integer', 'min:1', 'max:100000'],
            'bulk_max_quantity' => ['nullable', 'integer', 'min:1', 'max:100000', 'gte:bulk_min_quantity'],
        ], [
            'session_end_time.after' => 'The end time must be after the start time.',
            'notification_watchers.*.exists' => 'One of the picked users does not exist any more.',
            'bulk_min_quantity.min' => 'The minimum bulk order must be at least 1 plate.',
            'bulk_max_quantity.gte' => 'The maximum bulk order cannot be lower than the minimum.',
        ]);

        DB::transaction(function () use ($validated, $request) {
            Setting::setSessionWindow($validated['session_start_time'], $validated['session_end_time']);

            if ($request->has('email_notifications')) {
                Setting::setEmailNotifications($request->boolean('email_notifications'));
            }

            if ($request->has('bulk_min_quantity')) {
                Setting::setBulkLimits((int) $validated['bulk_min_quantity'], $validated['bulk_max_quantity'] ?? null);
            }

            if ($request->has('notification_watchers')) {
                Setting::setNotificationWatchers($validated['notification_watchers'] ?? []);
            }
        });

        return $this->successResponse('System configuration saved.');
    }
}
