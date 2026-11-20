<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

// Direct messages through the Google Chat API, as a Chat app (a service account).
//
//   GoogleChat::message($googleId, 'Order X is ready', 'Show your order number...', '/my-orders');
//
// What Google allows, and so what this does:
//  - The app signs in with its service account (scope `chat.bot`, which needs no admin
//    approval) and can only write to spaces it is IN. The 1:1 space between the app and a
//    person exists once that person has added / opened the app in Google Chat. Creating it
//    for them would need an admin-approved scope (`chat.app.spaces.create`) and is not used.
//    A person who never did that is simply skipped ("not reachable"), not an error.
//  - The person is found by their Google account id (`users.google_id`, saved when they
//    signed in with Google): spaces.findDirectMessage(name = users/{id}) returns the space.
//    Accounts without a Google id cannot be messaged.
//  - Nothing here throws: every failure is logged (no message text, no key) and answers
//    false, because the bell notification is already saved and a chat problem must never
//    cost it. Network calls have a short timeout.
//
// The key file is never read into the database or the log; only its path is configured
// (config/services.php -> google_chat.credentials, env GOOGLE_CHAT_CREDENTIALS).
class GoogleChat
{
    protected const SCOPE = 'https://www.googleapis.com/auth/chat.bot';

    protected const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    protected const API = 'https://chat.googleapis.com/v1';

    // How long a found DM space is remembered, and how long "not reachable" is remembered.
    protected const SPACE_TTL_DAYS = 30;

    protected const MISSING_TTL_MINUTES = 10;

    /* -------- Setup -------- */

    // The service account (client_email + private_key) from the configured file, or null when
    // nothing is configured or the file is missing / not a service account key.
    public static function credentials(): ?array
    {
        $path = (string) config('services.google_chat.credentials');

        if ($path === '') {
            return null;
        }

        // A relative path is relative to the project, so ".env" can say storage/app/chat.json.
        if (! preg_match('#^([a-zA-Z]:[\\\\/]|/)#', $path)) {
            $path = base_path($path);
        }

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $key = json_decode((string) file_get_contents($path), true);

        return is_array($key) && ! empty($key['client_email']) && ! empty($key['private_key']) ? $key : null;
    }

    public static function configured(): bool
    {
        return self::credentials() !== null;
    }

    /* -------- Sending -------- */

    // Send one direct message. True when Google accepted it.
    //
    // $entry: title, body?, url?, type? (an OrderNotifier event, picks the icon), app? (the name
    // in the card's header) and order? (number, total, method, items[] of dish_name / quantity /
    // sub_total: the card then lists the dishes). It goes out as a card; if Google refuses the
    // card (HTTP 400) the same words are sent as plain text, so a card problem never costs the
    // notification.
    public static function message(string $googleId, array $entry): bool
    {
        try {
            $token = self::token();

            if ($token === null) {
                return false;
            }

            $space = self::dmSpace($googleId, $token);

            if ($space === null) {
                return false;
            }

            $post = fn (array $payload) => Http::withToken($token)->timeout(8)->acceptJson()
                ->post(self::API . '/' . $space . '/messages', $payload);

            $response = $post(['cardsV2' => [self::card($entry)]]);

            if ($response->status() === 400) {
                Log::warning('Google Chat card was refused, sending plain text', ['reason' => self::reason($response)]);

                $response = $post(['text' => self::format($entry['title'], $entry['body'] ?? null, $entry['url'] ?? null)]);
            }

            if ($response->successful()) {
                return true;
            }

            // The person removed the app, or it was removed from the space: look the space up again next time.
            if (in_array($response->status(), [403, 404], true)) {
                Cache::forget(self::spaceKey($googleId));
            }

            Log::warning('Google Chat message was refused', ['reason' => self::reason($response)]);

            return false;
        } catch (Throwable $exception) {
            Log::warning('Google Chat message could not be sent', ['error' => $exception->getMessage()]);

            return false;
        }
    }

    // Is this person's chat with the Chat app there, right now? 'connected', 'not_added' (Google
    // says the chat does not exist: they have not opened / said hi to the app) or 'error' (the key,
    // the token or Google failed: nothing can be said about them). A live lookup, not the cache: the
    // Profile page's "synced" indicator must not claim a chat the person has since removed.
    public static function directMessageState(string $googleId): string
    {
        try {
            $token = self::token();

            if ($token === null) {
                return 'error';
            }

            Cache::forget(self::spaceKey($googleId));
            Cache::forget(self::spaceKey($googleId) . ':missing');

            if (self::dmSpace($googleId, $token) !== null) {
                return 'connected';
            }

            // dmSpace() remembers a 404 (and only a 404) as "missing".
            return Cache::has(self::spaceKey($googleId) . ':missing') ? 'not_added' : 'error';
        } catch (Throwable $exception) {
            Log::warning('Google Chat could not check a direct message space', ['error' => $exception->getMessage()]);

            return 'error';
        }
    }

    // What the Profile card and the sign-in prompt need, or null when Google Chat is not available
    // (the switch is off or the key is not set up), so nobody is told to add an app nothing talks
    // to. The key file is a free check; the setting costs a query, so it comes second.
    public static function pageState(\App\Models\User $user): ?array
    {
        if (! self::configured() || ! \App\Models\Setting::googleChatNotifications()) {
            return null;
        }

        return [
            // Has signed in with Google once: the app finds them by that account's id.
            'connected' => ! empty($user->google_id),
            'app' => self::appName(),
            'url' => (string) (config('services.google_chat.app_url') ?: 'https://chat.google.com'),
            // The link is the app's own page (its Marketplace listing): the button then says Install.
            'listing' => filled(config('services.google_chat.app_url')),
        ];
    }

    // Should the one-time "get your updates in Google Chat" prompt open after sign-in? Only for a
    // person who signed in with Google (the others cannot be found), is not synced / welcomed yet,
    // and has not been checked since this login. It comes back at EVERY login until they are
    // synced: "Maybe later" only closes it for the visit (the session flag below), nothing is
    // remembered. The cheap checks come first, so most page views cost nothing.
    public static function shouldPrompt(?\App\Models\User $user): bool
    {
        if (! $user || empty($user->google_id) || $user->google_chat_welcomed_at !== null) {
            return false;
        }

        if (session('google_chat_checked')) {
            return false;
        }

        return self::pageState($user) !== null;
    }

    // The name people know the app by in Google Chat (Profile card and welcome message).
    public static function appName(): string
    {
        return (string) (config('services.google_chat.app_name') ?: \App\Models\Setting::appName());
    }

    // The Welcome message: the first thing anyone receives from the app in Google Chat.
    public static function welcomeEntry(bool $canOpenOrders): array
    {
        $app = self::appName();

        return [
            'title' => __('Welcome to :app', ['app' => $app]),
            'body' => __('You are all set! From now on your order updates arrive right here, so you do not have to keep checking the website. There is nothing to reply to.'),
            'points' => [
                '🧾 ' . __('When your order is placed'),
                '✅ ' . __('When your payment is checked'),
                '🍽️ ' . __('When your order is ready for pick up'),
                '🎉 ' . __('When your order is completed'),
            ],
            'url' => $canOpenOrders ? route('my-orders.index', [], false) : null,
            'button' => __('Open my orders'),
            'type' => 'welcome',
            'app' => $app,
        ];
    }

    // Send this person the Welcome message if they never got it. True only when it went out just now.
    // The "welcomed" mark is claimed FIRST with one conditional UPDATE, so two requests at the same
    // moment (the Profile page and a notification) cannot both send it; if Google then refuses
    // (they have not added the app yet, an outage) the mark is given back, so it is tried again later.
    public static function welcome(int $userId, string $googleId): bool
    {
        try {
            $claimed = \Illuminate\Support\Facades\DB::table('users')
                ->where('id', $userId)
                ->whereNull('google_chat_welcomed_at')
                ->update(['google_chat_welcomed_at' => now()]);

            if ($claimed === 0) {
                return false;
            }

            $user = \App\Models\User::query()->find($userId);
            $sent = self::message($googleId, self::welcomeEntry((bool) $user?->can('view my orders')));

            if (! $sent) {
                \Illuminate\Support\Facades\DB::table('users')->where('id', $userId)->update(['google_chat_welcomed_at' => null]);
            }

            return $sent;
        } catch (Throwable $exception) {
            Log::warning('Google Chat welcome could not be sent', ['error' => $exception->getMessage()]);

            return false;
        }
    }

    // The icon in front of the title, by event (OrderNotifier's type strings).
    protected const ICONS = [
        'order_placed' => '🧾',
        'payment_verified' => '✅',
        'payment_rejected' => '⚠️',
        'order_ready' => '🍽️',
        'order_completed' => '🎉',
        'order_cancelled' => '❌',
        'payment_resent' => '💳',
        'dish_sold_out' => '📢',
        'welcome' => '👋',
        'connection_check' => '✅',
    ];

    // A long order stays readable: only this many dishes are listed, the rest is counted.
    protected const MAX_DISHES = 12;

    // The card (Chat API cardsV2): a header with the title and the app's name, the words, for an
    // order its dishes with quantities and prices, the total and the payment method, and a button
    // to open it. Card text takes a little HTML, so everything typed by people is escaped.
    public static function card(array $entry): array
    {
        $esc = fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $money = fn ($amount) => '₱' . number_format((float) $amount, 2);

        $sections = [];

        if (! empty($entry['body'])) {
            $sections[] = ['widgets' => [['textParagraph' => ['text' => $esc($entry['body'])]]]];
        }

        // A short list (the welcome message: what you will hear about here), one line each.
        if (! empty($entry['points'])) {
            $sections[] = ['widgets' => [['textParagraph' => ['text' => implode('<br>', array_map($esc, $entry['points']))]]]];
        }

        $order = $entry['order'] ?? null;

        if ($order) {
            $items = array_values($order['items'] ?? []);
            $lines = [];

            foreach (array_slice($items, 0, self::MAX_DISHES) as $item) {
                $lines[] = '<b>' . (int) $item['quantity'] . '×</b> ' . $esc($item['dish_name']) . ' — ' . $money($item['sub_total']);
            }

            if (count($items) > self::MAX_DISHES) {
                $lines[] = '<i>+' . (count($items) - self::MAX_DISHES) . ' ' . $esc(__('more')) . '</i>';
            }

            $widgets = $lines === [] ? [] : [['textParagraph' => ['text' => implode('<br>', $lines)]]];
            $widgets[] = ['decoratedText' => ['topLabel' => __('Total'), 'text' => '<b>' . $money($order['total']) . '</b>']];

            if (! empty($order['method'])) {
                $widgets[] = ['decoratedText' => ['topLabel' => __('Payment'), 'text' => $esc($order['method'])]];
            }

            $sections[] = ['header' => __('Order') . ' ' . $order['number'], 'widgets' => $widgets];
        }

        $url = ! empty($entry['url']) ? url($entry['url']) : null;

        // Chat only opens https links from a button; a local http link stays out of the card.
        if ($url !== null && str_starts_with($url, 'https://')) {
            $sections[] = ['widgets' => [['buttonList' => ['buttons' => [[
                'text' => $entry['button'] ?? ($order ? __('Open order') : __('Open')),
                'onClick' => ['openLink' => ['url' => $url]],
            ]]]]]];
        }

        // A card needs at least one section.
        if ($sections === []) {
            $sections[] = ['widgets' => [['textParagraph' => ['text' => $esc($entry['title'])]]]];
        }

        return [
            'cardId' => 'n' . substr(md5($entry['title'] . ($order['number'] ?? '')), 0, 12),
            'card' => [
                'header' => [
                    'title' => (self::ICONS[$entry['type'] ?? ''] ?? '🔔') . ' ' . $entry['title'],
                    'subtitle' => $entry['app'] ?? '',
                ],
                'sections' => $sections,
            ],
        ];
    }
    // What the person reads: the title in bold, the text, and a link. The syntax follows the
    // "Markup syntax" of the app in the Google Chat API configuration (config
    // services.google_chat.markup): 'markdown' (the default for new Chat apps: **bold**,
    // [text](url)) or 'legacy' (Google Chat's own: *bold*, <url|text>).
    public static function format(string $title, ?string $body, ?string $url): string
    {
        $legacy = config('services.google_chat.markup') === 'legacy';

        $text = $legacy ? '*' . trim($title) . '*' : '**' . trim($title) . '**';

        if ($body !== null && trim($body) !== '') {
            $text .= "\n" . trim($body);
        }

        if ($url !== null && $url !== '') {
            $text .= $legacy
                ? "\n<" . url($url) . '|' . __('Open') . '>'
                : "\n[" . __('Open') . '](' . url($url) . ')';
        }

        return $text;
    }

    // Why Google said no, for the log and the test command: the HTTP status and Google's own
    // error code and sentence. Never the request (it holds the token or the message text).
    protected static function reason(\Illuminate\Http\Client\Response $response): string
    {
        return trim('HTTP ' . $response->status() . ' '
            . ($response->json('error.status') ?? $response->json('error') ?? '') . ' '
            . ($response->json('error.message') ?? $response->json('error_description') ?? ''));
    }

    // Walk the whole path for one person and say where it stops: the key, the token, the direct
    // message space, and (when $send) a real test message. Used by `php artisan google-chat:test`.
    // Returns [[label, ok, detail], ...]; stops at the first failed step.
    public static function diagnose(string $googleId, bool $send, bool $fresh = true, ?int $userId = null): array
    {
        $steps = [];
        $stop = function (string $label, bool $ok, string $detail) use (&$steps) {
            $steps[] = [$label, $ok, $detail];

            return $ok;
        };

        $key = self::credentials();

        if (! $stop('Key file', $key !== null, $key ? 'service account ' . $key['client_email'] : 'GOOGLE_CHAT_CREDENTIALS is empty, the file is missing / unreadable by this user, or it is not a service account key (after changing .env run: php artisan config:clear)')) {
            return $steps;
        }

        // A fresh token, so an old cached one cannot hide a broken key (the command does; a person's own test does not).
        if ($fresh) {
            Cache::forget('google-chat:token');
        }

        try {
            $token = self::token();
        } catch (Throwable $exception) {
            $token = null;
            $stop('Access token', false, $exception->getMessage());

            return $steps;
        }

        if (! $stop('Access token', $token !== null, $token !== null ? 'obtained' : 'refused: see the "Google Chat: no access token" line in storage/logs/laravel.log')) {
            return $steps;
        }

        Cache::forget(self::spaceKey($googleId));
        Cache::forget(self::spaceKey($googleId) . ':missing');

        $lookup = Http::withToken($token)->timeout(8)->acceptJson()
            ->get(self::API . '/spaces:findDirectMessage', ['name' => 'users/' . $googleId]);
        $space = $lookup->successful() ? $lookup->json('name') : null;

        if (! $stop('Direct message space', is_string($space), is_string($space)
            ? $space
            : self::reason($lookup) . ($lookup->status() === 404 ? ' (this person has not added / opened the app in Google Chat, or users/' . $googleId . ' is not their Chat id)' : ''))) {
            return $steps;
        }

        if (! $send) {
            return $steps;
        }

        // The first message anyone gets is the Welcome. If this person already had it, send a plain
        // connection check instead (never a made-up order).
        if ($userId !== null && self::welcome($userId, $googleId)) {
            $stop('Test message', true, 'sent (the Welcome message: they had not received it yet)');

            return $steps;
        }

        $post = Http::withToken($token)->timeout(8)->acceptJson()
            ->post(self::API . '/' . $space . '/messages', ['cardsV2' => [self::card([
                'title' => __('Google Chat is connected'),
                'body' => __('This is a connection check from :app. Nothing else to do.', ['app' => self::appName()]),
                'type' => 'connection_check',
                'app' => self::appName(),
            ])]]);

        $stop('Test message', $post->successful(), $post->successful() ? 'sent (a connection check)' : self::reason($post));
        return $steps;
    }

    /* -------- Google calls -------- */

    protected static function spaceKey(string $googleId): string
    {
        return 'google-chat:space:' . $googleId;
    }

    // The 1:1 space between the app and this person ("spaces/AAAA..."), or null when they have
    // not added the app yet (Google answers 404). A miss is remembered for a few minutes so a
    // burst of notifications does not ask Google again for every one.
    protected static function dmSpace(string $googleId, string $token): ?string
    {
        $key = self::spaceKey($googleId);
        $known = Cache::get($key);

        if (is_string($known) && $known !== '') {
            return $known;
        }

        if (Cache::has($key . ':missing')) {
            return null;
        }

        $response = Http::withToken($token)->timeout(8)->acceptJson()
            ->get(self::API . '/spaces:findDirectMessage', ['name' => 'users/' . $googleId]);

        $name = $response->successful() ? $response->json('name') : null;

        if (is_string($name) && str_starts_with($name, 'spaces/')) {
            Cache::put($key, $name, now()->addDays(self::SPACE_TTL_DAYS));

            return $name;
        }

        if ($response->status() === 404) {
            Cache::put($key . ':missing', 1, now()->addMinutes(self::MISSING_TTL_MINUTES));
        } else {
            Log::warning('Google Chat could not look up a direct message space', ['reason' => self::reason($response)]);
        }

        return null;
    }

    // An access token for the app, kept until shortly before it expires. Signed in-house
    // (RS256 JWT-bearer grant), so no Google library is needed.
    protected static function token(): ?string
    {
        $cached = Cache::get('google-chat:token');

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $key = self::credentials();

        if ($key === null) {
            return null;
        }

        $now = time();
        $segment = fn (array $data) => rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');
        $unsigned = $segment(['alg' => 'RS256', 'typ' => 'JWT']) . '.' . $segment([
            'iss' => $key['client_email'],
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ]);

        $signature = '';

        if (! openssl_sign($unsigned, $signature, $key['private_key'], OPENSSL_ALGO_SHA256)) {
            Log::warning('Google Chat: the service account key could not sign');

            return null;
        }

        $response = Http::asForm()->timeout(8)->post(self::TOKEN_URL, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $unsigned . '.' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '='),
        ]);

        $token = $response->successful() ? $response->json('access_token') : null;

        if (! is_string($token) || $token === '') {
            Log::warning('Google Chat: no access token', ['reason' => self::reason($response)]);

            return null;
        }

        // Google gives an hour; renew five minutes early.
        Cache::put('google-chat:token', $token, max(60, (int) $response->json('expires_in', 3600) - 300));

        return $token;
    }
}
