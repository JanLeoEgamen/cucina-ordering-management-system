<?php

namespace App\Http\Controllers;

use App\Models\PreOrderSession;
use App\Models\PreOrderSessionDish;
use App\Models\Dish;
use App\Models\OrderSession;
use App\Models\PaymentMethod;
use App\Models\SessionDish;

// The public landing page. It tells a visitor what the app is for and whether ordering is
// open right now (the session that is live, else the next one that is scheduled), and
// which ways of paying there are, over a collage of the available dishes. While a
// session is live it also previews what is on offer: dish names, photos and prices, so
// a visitor can see the food before signing up (ordering itself stays behind login).
// Stock is never shown: only whether a dish is sold out.
class WelcomeController extends Controller
{
    // Dishes printed per preview; a longer menu is closed with "and N more".
    protected const PREVIEW_LIMIT = 8;

    public function __invoke()
    {
        $sessions = OrderSession::getAll();
        $live = $sessions->first(fn (object $session) => $session->status === 'live');
        $preOrder = PreOrderSession::live();

        return view('welcome', [
            'live' => $live,
            // The earliest session that has not started yet.
            'next' => $sessions->where('status', 'scheduled')
                ->sortBy(fn (object $session) => $session->order_date . ' ' . $session->start_time)
                ->first(),
            'paymentMethods' => PaymentMethod::activeOptions()->pluck('method_name'),
            // The hero's collage of available dishes (names and photos only; empty without dishes).
            'collage' => Dish::collageColumns(),
            // What the live order session offers (the same dishes as the menu), as a preview.
            'menuDishes' => $live ? SessionDish::offeredForSession($live->session_id) : collect(),
            // The running pre-order session, its dishes and the plates one order must hold.
            'preOrder' => $preOrder,
            'preOrderDishes' => $preOrder ? PreOrderSessionDish::offeredForSession($preOrder->pre_order_session_id) : collect(),
            'preOrderLimits' => $preOrder ? PreOrderSession::limitsFor($preOrder) : ['min' => 0, 'max' => null],
            'previewLimit' => self::PREVIEW_LIMIT,
        ]);
    }
}
