<?php

namespace App\Http\Controllers;

use App\Models\OrderSession;
use App\Support\Dashboard;
use App\Support\Memo;
use Illuminate\Http\Request;

// The page everyone lands on. It needs no permission of its own: what it shows beyond the live
// session and the "Jump to" tiles is decided widget by widget (App\Support\Dashboard).
class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        // One pass for the banner and the widgets: the sessions are read once for both.
        $data = Memo::during(fn () => [
            // The session that is running right now.
            'live' => OrderSession::live(),
            // Only the widgets this person may see, each with its data.
            'widgets' => Dashboard::widgetsFor($request->user()),
        ]);

        return view('dashboard', $data);
    }
}
