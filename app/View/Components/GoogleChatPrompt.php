<?php

namespace App\View\Components;

use App\Support\GoogleChat;
use Illuminate\View\Component;
use Illuminate\View\View;

// The one-time prompt after sign-in that offers Google Chat (resources/views/components/
// google-chat-prompt.blade.php): drawn in the app layout, only when GoogleChat::shouldPrompt()
// says so. Not on the Profile page, which has the full card (and the one install guide).
class GoogleChatPrompt extends Component
{
    public function shouldRender(): bool
    {
        return ! request()->routeIs('profile.edit') && GoogleChat::shouldPrompt(auth()->user());
    }

    public function render(): View
    {
        return view('components.google-chat-prompt', ['googleChat' => GoogleChat::pageState(auth()->user())]);
    }
}
