<?php

namespace App\Models;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

// System configuration: one row per key in `settings`. Like the other models it is a set
// of static query builder methods. A key that was never saved falls back to DEFAULTS, so
// the app works before anyone opens the configuration page.
class Setting
{
    protected const TABLE = 'settings';

    // The keys the app knows, with the value used until one is saved.
    public const SESSION_START_TIME = 'order_session.default_start_time';

    public const SESSION_END_TIME = 'order_session.default_end_time';

    // The users who receive a copy of EVERY notification (a JSON list of user ids).
    public const NOTIFICATION_WATCHERS = 'notifications.watchers';

    // '1' = every notification is also sent by email; '0' = in-app only.
    public const EMAIL_NOTIFICATIONS = 'notifications.email_enabled';

    // '1' = every notification is also sent as a Google Chat direct message; '0' = not.
    public const GOOGLE_CHAT_NOTIFICATIONS = 'notifications.google_chat_enabled';

    // '1' = a customer may cancel their own pending order from My Orders; '0' = only staff can.
    public const CUSTOMER_CANCEL = 'orders.customer_cancel';

    // The fewest and the most plates ONE pre-order may hold. The maximum is empty
    // when there is no limit.
    public const PRE_ORDER_MIN_QUANTITY = 'pre_orders.min_quantity';

    public const PRE_ORDER_MAX_QUANTITY = 'pre_orders.max_quantity';

    // The name shown on every page, the title bar and the emails, and the path (on the public
    // disk) of the uploaded logo. An empty logo = the built-in Cucina mark.
    public const APP_NAME = 'app.name';

    public const APP_LOGO = 'app.logo';

    // What the name is until an admin changes it: the name of the product, not .env's APP_NAME
    // (which is Laravel's "Laravel" on a fresh install).
    public const DEFAULT_APP_NAME = 'Cucina';

    // The two public legal pages, written by an admin in Markdown: the privacy policy (GET /privacy)
    // and the terms of service of ordering (GET /terms). Each has its text (empty = the built-in
    // text in resources/legal/<page>.md) and an optional address people can write to.
    public const PRIVACY_CONTENT = 'privacy.content';

    public const PRIVACY_CONTACT_EMAIL = 'privacy.contact_email';

    public const TERMS_CONTENT = 'terms.content';

    public const TERMS_CONTACT_EMAIL = 'terms.contact_email';

    // page => the settings behind it.
    public const LEGAL_PAGES = [
        'privacy' => ['content' => self::PRIVACY_CONTENT, 'email' => self::PRIVACY_CONTACT_EMAIL],
        'terms' => ['content' => self::TERMS_CONTENT, 'email' => self::TERMS_CONTACT_EMAIL],
    ];

    // The longest page text. The value column is TEXT (65,535 BYTES) and a character can take
    // four, so 15,000 characters always fit.
    public const LEGAL_MAX_LENGTH = 15000;

    public const DEFAULTS = [
        self::PRIVACY_CONTENT => '',
        self::PRIVACY_CONTACT_EMAIL => '',
        self::TERMS_CONTENT => '',
        self::TERMS_CONTACT_EMAIL => '',
        self::APP_NAME => self::DEFAULT_APP_NAME,
        self::APP_LOGO => '',
        self::SESSION_START_TIME => '09:00',
        self::SESSION_END_TIME => '17:00',
        self::NOTIFICATION_WATCHERS => '[]',
        // Off until an admin turns it on: mail has to be set up in .env first.
        self::EMAIL_NOTIFICATIONS => '0',
        // Off until an admin turns it on: the Chat app's key has to be set up in .env first.
        self::GOOGLE_CHAT_NOTIFICATIONS => '0',
        // Off until an admin allows it: until now only staff cancelled an order.
        self::CUSTOMER_CANCEL => '0',
        self::PRE_ORDER_MIN_QUANTITY => '10',
        self::PRE_ORDER_MAX_QUANTITY => '',
    ];

    /* -------- Reads -------- */

    // One setting: the saved value, else the key's default (null for an unknown key).
    public static function get(string $key): ?string
    {
        $value = DB::table(self::TABLE)->where('key', $key)->value('value');

        return $value ?? (self::DEFAULTS[$key] ?? null);
    }

    // The app's name and logo: ['name' => string, 'logo' => ?path, 'logo_url' => ?url].
    //
    // It is read on EVERY page (layouts, welcome, error pages, emails), so:
    //  - the two keys come in ONE query, remembered for the rest of the web request (not in the
    //    console: a queue worker lives for hours and must see a change; the tests count as web),
    //    and every set() forgets it, so a save is never read back stale;
    //  - it can never fail: an error page asks too, and a database that is down must not turn a
    //    500 into a second error, so any problem reads as the defaults;
    //  - a logo whose file is gone (uploads not copied to a new server) reads as "no logo" instead
    //    of a broken picture.
    public static function brand(): array
    {
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        if ($request && $request->attributes->has('brand')) {
            return $request->attributes->get('brand');
        }

        $brand = ['name' => self::DEFAULT_APP_NAME, 'logo' => null, 'logo_url' => null];

        try {
            $saved = DB::table(self::TABLE)->whereIn('key', [self::APP_NAME, self::APP_LOGO])->pluck('value', 'key');

            $name = trim((string) ($saved[self::APP_NAME] ?? ''));
            $logo = trim((string) ($saved[self::APP_LOGO] ?? ''));

            if ($name !== '') {
                $brand['name'] = $name;
            }

            if ($logo !== '' && Storage::disk('public')->exists($logo)) {
                $brand['logo'] = $logo;
                $brand['logo_url'] = Storage::disk('public')->url($logo);
            }
        } catch (Throwable $error) {
            // The defaults above stand.
        }

        $request?->attributes->set('brand', $brand);

        return $brand;
    }

    public static function appName(): string
    {
        return self::brand()['name'];
    }

    // The URL of the uploaded logo, or null when the built-in mark is used.
    public static function appLogoUrl(): ?string
    {
        return self::brand()['logo_url'];
    }

    // The default window of a new order session, as H:i strings.
    public static function sessionWindow(): array
    {
        return [
            'start_time' => self::get(self::SESSION_START_TIME),
            'end_time' => self::get(self::SESSION_END_TIME),
        ];
    }

    // Is the email copy of notifications switched on?
    public static function emailNotifications(): bool
    {
        return self::get(self::EMAIL_NOTIFICATIONS) === '1';
    }

    // Is the Google Chat copy of notifications switched on?
    public static function googleChatNotifications(): bool
    {
        return self::get(self::GOOGLE_CHAT_NOTIFICATIONS) === '1';
    }

    // May a customer cancel their own pending order? Read fresh on every cancel, so switching it
    // off stops the very next request.
    public static function customerCancel(): bool
    {
        return self::get(self::CUSTOMER_CANCEL) === '1';
    }

    // The plates one pre-order may hold: ['min' => int >= 1, 'max' => ?int]. A max of
    // null means "no limit". A damaged value reads as the default, and a maximum below
    // the minimum is ignored, so an order is never impossible to place.
    public static function preOrderLimits(): array
    {
        $min = (int) self::get(self::PRE_ORDER_MIN_QUANTITY);
        $min = $min >= 1 ? $min : (int) self::DEFAULTS[self::PRE_ORDER_MIN_QUANTITY];

        $max = (int) self::get(self::PRE_ORDER_MAX_QUANTITY);
        $max = $max >= $min ? $max : null;

        return ['min' => $min, 'max' => $max];
    }

    // The built-in text of a legal page (Markdown, with {app_name}); '' if the file cannot be read.
    public static function defaultLegalContent(string $page): string
    {
        $text = @file_get_contents(resource_path('legal/' . $page . '.md'));

        return $text === false ? '' : str_replace("\r\n", "\n", trim($text));
    }

    // A legal page as the public page and the configuration form need it ($page: a key of LEGAL_PAGES):
    //   content       the Markdown to show (what was saved, else the built-in text)
    //   custom        true when an admin saved their own text
    //   contact_email the address to write to, or '' for none
    //   updated_at    when the text or the address last changed (null while nothing was saved)
    public static function legalPage(string $page): array
    {
        $keys = self::LEGAL_PAGES[$page];

        $saved = trim((string) self::get($keys['content']));
        $email = trim((string) self::get($keys['email']));

        $rows = DB::table(self::TABLE)->whereIn('key', array_values($keys))->pluck('updated_at');

        return [
            'content' => $saved !== '' ? $saved : self::defaultLegalContent($page),
            'custom' => $saved !== '',
            'contact_email' => $email,
            'updated_at' => $rows->isEmpty() ? null : Carbon::parse($rows->max()),
        ];
    }
    // The ids of the users who receive every notification, as ints. A damaged
    // value reads as "nobody" rather than breaking an order.
    public static function notificationWatchers(): array
    {
        $ids = json_decode((string) self::get(self::NOTIFICATION_WATCHERS), true);

        return is_array($ids)
            ? array_values(array_unique(array_filter(array_map('intval', $ids))))
            : [];
    }

    /* -------- Writes -------- */

    // Save the app name (the caller has trimmed and validated it).
    public static function setAppName(string $name): void
    {
        self::set(self::APP_NAME, $name);
    }

    // Save the logo's path on the public disk; null goes back to the built-in mark.
    public static function setAppLogo(?string $path): void
    {
        self::set(self::APP_LOGO, $path ?? '');
    }

    // Switch the email copy of notifications on or off.
    public static function setEmailNotifications(bool $enabled): void
    {
        self::set(self::EMAIL_NOTIFICATIONS, $enabled ? '1' : '0');
    }

    // Switch the Google Chat copy of notifications on or off.
    public static function setGoogleChatNotifications(bool $enabled): void
    {
        self::set(self::GOOGLE_CHAT_NOTIFICATIONS, $enabled ? '1' : '0');
    }

    // Allow or forbid customers to cancel their own pending order.
    public static function setCustomerCancel(bool $allowed): void
    {
        self::set(self::CUSTOMER_CANCEL, $allowed ? '1' : '0');
    }

    // Save the pre-order limits (a null maximum = no limit).
    public static function setPreOrderLimits(int $min, ?int $max): void
    {
        DB::transaction(function () use ($min, $max) {
            self::set(self::PRE_ORDER_MIN_QUANTITY, (string) $min);
            self::set(self::PRE_ORDER_MAX_QUANTITY, $max === null ? '' : (string) $max);
        });
    }

    // Save a legal page (the caller has validated both). Only a value that really changed is
    // written, so "last updated" moves when the page does and not on every save of the form. The
    // text equal to the built-in one, or empty, is stored as '' (= keep following the built-in
    // text). A null argument leaves that value alone.
    public static function setLegalPage(string $page, ?string $content, ?string $contactEmail): void
    {
        $keys = self::LEGAL_PAGES[$page];
        $values = [];

        if ($content !== null) {
            $content = trim(str_replace("\r\n", "\n", $content));
            $values[$keys['content']] = $content === self::defaultLegalContent($page) ? '' : $content;
        }

        if ($contactEmail !== null) {
            $values[$keys['email']] = trim($contactEmail);
        }

        DB::transaction(function () use ($values) {
            foreach ($values as $key => $value) {
                if ((string) self::get($key) !== $value) {
                    self::set($key, $value);
                }
            }
        });
    }
    // Save who receives every notification.
    public static function setNotificationWatchers(array $userIds): void
    {
        self::set(self::NOTIFICATION_WATCHERS, json_encode(array_values(array_unique(array_filter(array_map('intval', $userIds))))));
    }

    // Save one setting (insert, or replace the value of the existing key).
    public static function set(string $key, ?string $value): void
    {
        // The name / logo remembered for this request (see brand()) may be the one just replaced.
        request()->attributes->remove('brand');

        $now = now();

        // Not updateOrInsert: it would rewrite created_at on every save.
        $changed = DB::table(self::TABLE)->where('key', $key)->update(['value' => $value, 'updated_at' => $now]);

        if ($changed === 0 && ! DB::table(self::TABLE)->where('key', $key)->exists()) {
            DB::table(self::TABLE)->insert(['key' => $key, 'value' => $value, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    // Save the default order session window.
    public static function setSessionWindow(string $start, string $end): void
    {
        DB::transaction(function () use ($start, $end) {
            self::set(self::SESSION_START_TIME, $start);
            self::set(self::SESSION_END_TIME, $end);
        });
    }
}
