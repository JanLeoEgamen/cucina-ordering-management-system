<?php

namespace App\Providers;

use App\Listeners\AuditAuthEvents;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The audit trail's sign in / out / failed sign in entries.
        Event::subscribe(AuditAuthEvents::class);
    }
}
