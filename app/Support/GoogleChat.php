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
                'text' => $order ? __('Open order') : __('Open'),
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
    public static function diagnose(string $googleId, bool $send, bool $fresh = true): array
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

        $post = Http::withToken($token)->timeout(8)->acceptJson()
            ->post(self::API . '/' . $space . '/messages', ['cardsV2' => [self::card([
                'title' => 'Order ORD-TEST-0001 is ready for pick up',
                'body' => 'If you can read this, Google Chat notifications work. This is how an order looks.',
                'url' => '/my-orders',
                'type' => 'order_ready',
                'app' => \App\Models\Setting::appName(),
                'order' => ['number' => 'ORD-TEST-0001', 'total' => 185, 'method' => 'Cash on Pick up', 'items' => [
                    ['dish_name' => 'Chicken Adobo', 'quantity' => 2, 'sub_total' => 130],
                    ['dish_name' => 'Garlic Rice', 'quantity' => 1, 'sub_total' => 55],
                ]],
            ])]]);

        $stop('Test message', $post->successful(), $post->successful() ? 'sent' : self::reason($post));

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
