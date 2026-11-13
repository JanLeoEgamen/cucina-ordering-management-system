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
        ], [
            'session_end_time.after' => 'The end time must be after the start time.',
            'notification_watchers.*.exists' => 'One of the picked users does not exist any more.',
        ]);

        DB::transaction(function () use ($validated, $request) {
            Setting::setSessionWindow($validated['session_start_time'], $validated['session_end_time']);

            if ($request->has('notification_watchers')) {
                Setting::setNotificationWatchers($validated['notification_watchers'] ?? []);
            }
        });

        return $this->successResponse('System configuration saved.');
    }
}
