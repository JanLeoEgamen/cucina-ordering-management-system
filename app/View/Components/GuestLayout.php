<?php

namespace App\View\Components;

use App\Models\Dish;
use Illuminate\View\Component;
use Illuminate\View\View;

class GuestLayout extends Component
{
    /**
     * Get the view / contents that represents the component.
     */
    public function render(): View
    {
        return view('layouts.guest', [
            // The dishes of the brand panel's collage (empty: the panel shows plain text).
            'collage' => Dish::collageColumns(),
        ]);
    }
}
