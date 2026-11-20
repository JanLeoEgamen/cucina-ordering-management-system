<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use App\Support\GoogleChat;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

// System configuration: the values the rest of the app starts from: the app's name and logo,
// the default start and end time of a new order session (the create drawer is pre-filled with
// them), the pre-order limits, the public privacy policy and terms and who is notified. The page is laid out in sections so more
// settings can be added beside them.
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
            'brand' => Setting::brand(),
            'sessionWindow' => Setting::sessionWindow(),
            'preOrderLimits' => Setting::preOrderLimits(),
            // The public legal pages (privacy, policy), each with its text and contact email.
            'legal' => collect(array_keys(Setting::LEGAL_PAGES))
                ->mapWithKeys(fn (string $page) => [$page => Setting::legalPage($page)])
                ->all(),
            'emailNotifications' => Setting::emailNotifications(),
            'googleChatNotifications' => Setting::googleChatNotifications(),
            // Is the Chat app's key file set up in .env and readable? (Never the key itself.)
            'googleChatReady' => GoogleChat::configured(),
            'customerCancel' => Setting::customerCancel(),
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
        // "  Cucina  " is "Cucina"; a name of spaces only is empty and fails `required`.
        if (is_string($request->input('app_name'))) {
            $request->merge(['app_name' => trim($request->input('app_name'))]);
        }

        $validated = $request->validate([
            // The name shown on every page and in the emails. Sent by the form; a caller that
            // leaves it out changes nothing.
            'app_name' => ['sometimes', 'required', 'string', 'max:60'],
            // The logo: a raster picture only. NOT svg: an uploaded svg opened on its own URL
            // runs its scripts on this site's origin, and `image` (without allow_svg) refuses it.
            'app_logo' => ['nullable', 'file', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            'remove_app_logo' => ['sometimes', 'boolean'],
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
            // Also send every notification as a Google Chat direct message (same 0 / 1 rule).
            'google_chat_notifications' => ['sometimes', 'boolean'],
            // Let customers cancel their own pending order. The form always sends 0 or 1; a
            // caller that leaves it out changes nothing.
            'customer_cancel' => ['sometimes', 'boolean'],
            // The plates one pre-order may hold. Sent together by the form; an older
            // caller that sends neither changes nothing. An empty maximum = no limit.
            'pre_order_min_quantity' => ['sometimes', 'required', 'integer', 'min:1', 'max:100000'],
            'pre_order_max_quantity' => ['nullable', 'integer', 'min:1', 'max:100000', 'gte:pre_order_min_quantity'],
            // The public legal pages (GET /privacy, GET /policy): Markdown text, and the address
            // people can write to. An empty text goes back to the built-in one. A caller that leaves
            // a field out changes nothing.
            'privacy_content' => ['sometimes', 'nullable', 'string', 'max:' . Setting::LEGAL_MAX_LENGTH],
            'privacy_contact_email' => ['sometimes', 'nullable', 'email', 'max:120'],
            'policy_content' => ['sometimes', 'nullable', 'string', 'max:' . Setting::LEGAL_MAX_LENGTH],
            'policy_contact_email' => ['sometimes', 'nullable', 'email', 'max:120'],
        ], [
            'app_name.required' => 'The app name cannot be empty.',
            'app_name.max' => 'The app name can have at most 60 characters.',
            'app_logo.image' => 'The logo must be a picture (JPG, PNG or WebP).',
            'app_logo.mimes' => 'The logo must be a JPG, PNG or WebP picture.',
            'app_logo.max' => 'The logo can be at most 2 MB.',
            'session_end_time.after' => 'The end time must be after the start time.',
            'notification_watchers.*.exists' => 'One of the picked users does not exist any more.',
            'pre_order_min_quantity.min' => 'The minimum pre-order must be at least 1 plate.',
            'pre_order_max_quantity.gte' => 'The maximum pre-order cannot be lower than the minimum.',
            'privacy_content.max' => 'The privacy policy can have at most :max characters.',
            'privacy_contact_email.email' => 'The privacy contact must be a valid email address.',
            'policy_content.max' => 'The terms and policy can have at most :max characters.',
            'policy_contact_email.email' => 'The terms and policy contact must be a valid email address.',
        ]);

        // The new logo is written BEFORE the transaction (a file cannot be rolled back) and the
        // old one is deleted only AFTER the row points at the new one, so a failed save never
        // leaves the app without its logo; a failed save removes the new file again.
        $previousLogo = (string) Setting::get(Setting::APP_LOGO);
        $newLogo = $request->hasFile('app_logo') ? $request->file('app_logo')->store('branding', 'public') : null;
        $logoChanged = $newLogo !== null || ($request->boolean('remove_app_logo') && $previousLogo !== '');

        try {
            $this->saveSettings($validated, $request, $newLogo, $logoChanged);
        } catch (Throwable $error) {
            if ($newLogo !== null) {
                Storage::disk('public')->delete($newLogo);
            }

            throw $error;
        }

        if ($logoChanged && $previousLogo !== '') {
            Storage::disk('public')->delete($previousLogo);
        }

        return $this->successResponse('System configuration saved.');
    }

    // Every write of one save, in one transaction.
    protected function saveSettings(array $validated, Request $request, ?string $newLogo, bool $logoChanged): void
    {
        DB::transaction(function () use ($validated, $request, $newLogo, $logoChanged) {
            if (array_key_exists('app_name', $validated)) {
                Setting::setAppName($validated['app_name']);
            }

            if ($logoChanged) {
                // A new file replaces it; otherwise "remove" went back to the built-in mark.
                Setting::setAppLogo($newLogo);
            }

            Setting::setSessionWindow($validated['session_start_time'], $validated['session_end_time']);

            if ($request->has('email_notifications')) {
                Setting::setEmailNotifications($request->boolean('email_notifications'));
            }

            if ($request->has('google_chat_notifications')) {
                Setting::setGoogleChatNotifications($request->boolean('google_chat_notifications'));
            }

            if ($request->has('customer_cancel')) {
                Setting::setCustomerCancel($request->boolean('customer_cancel'));
            }

            if ($request->has('pre_order_min_quantity')) {
                Setting::setPreOrderLimits((int) $validated['pre_order_min_quantity'], $validated['pre_order_max_quantity'] ?? null);
            }

            foreach (array_keys(Setting::LEGAL_PAGES) as $page) {
                $content = $page . '_content';
                $email = $page . '_contact_email';

                if (array_key_exists($content, $validated) || array_key_exists($email, $validated)) {
                    Setting::setLegalPage(
                        $page,
                        array_key_exists($content, $validated) ? (string) $validated[$content] : null,
                        array_key_exists($email, $validated) ? (string) $validated[$email] : null,
                    );
                }
            }

            if ($request->has('notification_watchers')) {
                Setting::setNotificationWatchers($validated['notification_watchers'] ?? []);
            }
        });
    }
}
