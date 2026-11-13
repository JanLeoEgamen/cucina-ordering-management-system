<?php

namespace App\Http\Controllers;

use App\Models\Dish;
use App\Models\OrderSession;
use App\Models\PaymentMethod;

// The public landing page. It tells a visitor what the app is for and whether ordering is
// open right now (the session that is live, else the next one that is scheduled), and
// which ways of paying there are, over a collage of the available dishes. Nothing private is
// shown: dish names and photos only, no prices or stock.
class WelcomeController extends Controller
{
    public function __invoke()
    {
        $sessions = OrderSession::getAll();

        return view('welcome', [
            'live' => $sessions->first(fn (object $session) => $session->status === 'live'),
            // The earliest session that has not started yet.
            'next' => $sessions->where('status', 'scheduled')
                ->sortBy(fn (object $session) => $session->order_date . ' ' . $session->start_time)
                ->first(),
            'paymentMethods' => PaymentMethod::activeOptions()->pluck('method_name'),
            // The hero's collage of available dishes (names and photos only; empty without dishes).
            'collage' => Dish::collageColumns(),
        ]);
    }
}
