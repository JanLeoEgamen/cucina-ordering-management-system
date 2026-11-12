<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

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
        ]);
    }

    // Save the configuration.
    public function update(Request $request)
    {
        $validated = $request->validate([
            'session_start_time' => ['required', 'date_format:H:i'],
            'session_end_time' => ['required', 'date_format:H:i', 'after:session_start_time'],
        ], [
            'session_end_time.after' => 'The end time must be after the start time.',
        ]);

        Setting::setSessionWindow($validated['session_start_time'], $validated['session_end_time']);

        return $this->successResponse('System configuration saved.');
    }
}
