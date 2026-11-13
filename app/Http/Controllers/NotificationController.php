<?php

namespace App\Http\Controllers;

use App\Models\UserNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

// A person's own notifications: the page, the small feed the bell polls, and
// marking them read. There is no permission on purpose (like the profile): every
// query is scoped to the signed-in user, so nobody can read or change anyone
// else's, and a notification they cannot act on simply has no link.
class NotificationController extends Controller
{
    // The list, newest first, a page at a time.
    public function index()
    {
        $notifications = UserNotification::page((int) auth()->id());

        // A page past the end (rows were pruned or read away, an old link): the last one.
        if ($notifications->currentPage() > $notifications->lastPage()) {
            return redirect($notifications->url($notifications->lastPage()));
        }

        return view('notifications.index', [
            'notifications' => $notifications,
            'unread' => UserNotification::unreadCount((int) auth()->id()),
        ]);
    }

    // What the bell polls (AJAX, JSON, about every 8 seconds, only while the tab
    // is visible). Cheap by design:
    //  - without `after` (the first call of a page) it answers the unread count and
    //    the newest id only, no list;
    //  - with `after=N` it also returns what arrived after N (at most 10), which is
    //    empty almost every time.
    public function feed(Request $request)
    {
        $userId = (int) auth()->id();

        $payload = [
            'status' => 'success',
            'unread' => UserNotification::unreadCount($userId),
            'latest_id' => UserNotification::latestId($userId),
            'items' => [],
        ];

        if ($request->has('after')) {
            $payload['items'] = array_map(fn (object $item) => [
                'id' => (int) $item->id,
                'type' => $item->type,
                'title' => $item->title,
                'body' => $item->body,
                'open_url' => route('notifications.open', $item->id),
            ], UserNotification::since($userId, max(0, (int) $request->query('after'))));
        }

        // `recent=1`: the bell's panel was opened (or something new arrived while it is
        // open) and wants the latest few to list. Only then is the list read.
        if ($request->boolean('recent')) {
            $payload['recent'] = array_map(fn (object $item) => [
                'id' => (int) $item->id,
                'title' => $item->title,
                'body' => $item->body,
                'open_url' => route('notifications.open', $item->id),
                'unread' => $item->read_at === null,
                'time' => Carbon::parse($item->created_at)->diffForHumans(),
            ], UserNotification::recent($userId));
        }

        return response()->json($payload)->header('Cache-Control', 'no-store');
    }

    // Open one: mark it read and go where it points (an unknown id, or one that is
    // someone else's, is a 404).
    public function open($id)
    {
        $notification = UserNotification::findFor((int) auth()->id(), (int) $id);

        if (! $notification) {
            abort(404);
        }

        UserNotification::markRead((int) auth()->id(), (int) $notification->id);

        // Only a local path is ever followed: the url is written by the app, but a
        // redirect to anywhere else would be an open redirect if that ever changed.
        $url = (string) $notification->url;

        return redirect($url !== '' && str_starts_with($url, '/') && ! str_starts_with($url, '//')
            ? $url
            : route('notifications.index'));
    }

    // Mark everything of this person as read (one UPDATE).
    public function readAll(Request $request)
    {
        UserNotification::markAllRead((int) auth()->id());

        if ($request->expectsJson()) {
            return $this->successResponse('All notifications marked as read.');
        }

        return redirect()->route('notifications.index');
    }
}
