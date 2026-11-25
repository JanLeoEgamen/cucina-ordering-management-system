<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use App\Support\GoogleChat;
use App\Support\Theme;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

// System configuration: the values the rest of the app starts from: the app's name and logo, its colour theme,
// the default start and end time of a new order session (the create drawer is pre-filled with
// them), the pre-order limits, the public support page, the privacy policy and terms of service and who is notified. The page is laid out in sections so more
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
            // The colour theme: the picked one, the choices, their swatches and the CSS of each (for the live preview).
            'themeAccent' => Theme::current(),
            'themeDefault' => Theme::default(),
            'themes' => Theme::options(),
            'swatches' => Theme::swatches(),
            'themePreviews' => Theme::previews(),
            'sessionWindow' => Setting::sessionWindow(),
            'preOrderLimits' => Setting::preOrderLimits(),
            'support' => Setting::support(),
            // The public legal pages (privacy, policy), each with its text and contact email.
            'legal' => collect(array_keys(Setting::LEGAL_PAGES))
                ->mapWithKeys(fn (string $page) => [$page => Setting::legalPage($page)])
                ->all(),
            'emailNotifications' => Setting::emailNotifications(),
            'googleChatNotifications' => Setting::googleChatNotifications(),
            // Is the Chat app's key file set up in .env and readable? (Never the key itself.)
            'googleChatReady' => GoogleChat::configured(),
            'customerCancel' => Setting::customerCancel(),
            'discountMode' => Setting::discountMode(),
            'alaCarteMode' => Setting::alaCarteMode(),
            'ricePrice' => Setting::ricePrice(),
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
            // The colour theme: one of the blocks of config/themes.php. A caller that leaves it out changes nothing.
            'theme_accent' => ['sometimes', 'required', Rule::in(array_keys(Theme::all()))],
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
            // Where customers are offered a discount. A caller that leaves it out changes nothing.
            'discount_mode' => ['sometimes', 'required', Rule::in(Setting::DISCOUNT_MODES)],
            // Where customers may order a plate WITHOUT rice, and how much less it costs (pesos, up to 2
            // decimals). A caller that leaves them out changes nothing. A price of 0 is only allowed while
            // the choice is off (checked below, because it depends on the other field).
            'ala_carte_mode' => ['sometimes', 'required', Rule::in(Setting::ALA_CARTE_MODES)],
            'ala_carte_rice_price' => ['sometimes', 'required', 'numeric', 'decimal:0,2', 'min:0', 'max:100000'],
            // The plates one pre-order may hold. Sent together by the form; an older
            // caller that sends neither changes nothing. An empty maximum = no limit.
            'pre_order_min_quantity' => ['sometimes', 'required', 'integer', 'min:1', 'max:100000'],
            'pre_order_max_quantity' => ['nullable', 'integer', 'min:1', 'max:100000', 'gte:pre_order_min_quantity'],
            // The public support page (GET /support): a Markdown help text (empty = the built-in one)
            // and how to reach the shop. All optional; a caller that leaves a field out changes nothing.
            'support_content' => ['sometimes', 'nullable', 'string', 'max:' . Setting::LEGAL_MAX_LENGTH],
            'support_email' => ['sometimes', 'nullable', 'email', 'max:120'],
            // Digits with the usual separators: +63 (2) 8123-4567, 0917 123 4567.
            'support_phone' => ['sometimes', 'nullable', 'string', 'max:40', 'regex:/^[0-9+()\-.\s]*$/'],
            'support_hours' => ['sometimes', 'nullable', 'string', 'max:120'],
            // The public legal pages (GET /privacy-policy, GET /terms-of-service): Markdown text, and the address
            // people can write to. An empty text goes back to the built-in one. A caller that leaves
            // a field out changes nothing.
            'privacy_policy_content' => ['sometimes', 'nullable', 'string', 'max:' . Setting::LEGAL_MAX_LENGTH],
            'privacy_policy_contact_email' => ['sometimes', 'nullable', 'email', 'max:120'],
            'terms_of_service_content' => ['sometimes', 'nullable', 'string', 'max:' . Setting::LEGAL_MAX_LENGTH],
            'terms_of_service_contact_email' => ['sometimes', 'nullable', 'email', 'max:120'],
        ], [
            'app_name.required' => 'The app name cannot be empty.',
            'app_name.max' => 'The app name can have at most 60 characters.',
            'app_logo.image' => 'The logo must be a picture (JPG, PNG or WebP).',
            'app_logo.mimes' => 'The logo must be a JPG, PNG or WebP picture.',
            'app_logo.max' => 'The logo can be at most 2 MB.',
            'theme_accent.in' => 'Pick one of the colour themes in the list.',
            'theme_accent.required' => 'Pick a colour theme.',
            'session_end_time.after' => 'The end time must be after the start time.',
            'notification_watchers.*.exists' => 'One of the picked users does not exist any more.',
            'ala_carte_rice_price.decimal' => 'The rice price can have at most 2 decimals.',
            'ala_carte_rice_price.required' => 'Enter how much a plate costs less without rice.',
            'pre_order_min_quantity.min' => 'The minimum pre-order must be at least 1 plate.',
            'pre_order_max_quantity.gte' => 'The maximum pre-order cannot be lower than the minimum.',
            'support_content.max' => 'The support page can have at most :max characters.',
            'support_email.email' => 'The support email must be a valid email address.',
            'support_phone.regex' => 'The support phone can only hold digits, spaces and + ( ) - .',
            'support_phone.max' => 'The support phone can have at most :max characters.',
            'support_hours.max' => 'The support hours can have at most :max characters.',
            'privacy_policy_content.max' => 'The privacy policy can have at most :max characters.',
            'privacy_policy_contact_email.email' => 'The privacy contact must be a valid email address.',
            'terms_of_service_content.max' => 'The terms of service can have at most :max characters.',
            'terms_of_service_contact_email.email' => 'The terms of service contact must be a valid email address.',
        ]);

        // Ala carte on with a rice price of 0 would offer a "discount" of nothing: ask for a real price.
        // Judged on what the setting will be after this save (a field left out keeps its saved value).
        $alaCarteMode = $validated['ala_carte_mode'] ?? Setting::alaCarteMode();
        $ricePrice = (float) ($validated['ala_carte_rice_price'] ?? Setting::ricePrice());

        if ($alaCarteMode !== 'off' && $ricePrice <= 0) {
            throw ValidationException::withMessages([
                'ala_carte_rice_price' => 'Enter how much a plate costs less without rice (more than 0), or switch ala carte off.',
            ]);
        }

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

            if (array_key_exists('theme_accent', $validated)) {
                Setting::setThemeAccent($validated['theme_accent']);
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

            if (array_key_exists('discount_mode', $validated)) {
                Setting::setDiscountMode($validated['discount_mode']);
            }

            if (array_key_exists('ala_carte_mode', $validated) || array_key_exists('ala_carte_rice_price', $validated)) {
                Setting::setAlaCarte(
                    $validated['ala_carte_mode'] ?? Setting::alaCarteMode(),
                    (float) ($validated['ala_carte_rice_price'] ?? Setting::ricePrice()),
                );
            }

            if ($request->has('pre_order_min_quantity')) {
                Setting::setPreOrderLimits((int) $validated['pre_order_min_quantity'], $validated['pre_order_max_quantity'] ?? null);
            }

            $supportFields = ['support_content', 'support_email', 'support_phone', 'support_hours'];

            if (array_intersect($supportFields, array_keys($validated)) !== []) {
                $value = fn (string $key) => array_key_exists($key, $validated) ? (string) $validated[$key] : null;

                Setting::setSupport($value('support_content'), $value('support_email'), $value('support_phone'), $value('support_hours'));
            }

            foreach (array_keys(Setting::LEGAL_PAGES) as $page) {
                // 'privacy-policy' -> privacy_policy_content / privacy_policy_contact_email
                $field = str_replace('-', '_', $page);
                $content = $field . '_content';
                $email = $field . '_contact_email';

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
