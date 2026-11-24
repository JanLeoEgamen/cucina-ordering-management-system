<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The opt-in Server-Timing header (SERVER_TIMING=true): why a request is slow, in DevTools.
class ServerTimingTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_is_off_by_default(): void
    {
        $this->actingAs($this->superAdmin())->getJson(route('order-sessions.index'))
            ->assertOk()->assertHeaderMissing('Server-Timing');
    }

    public function test_when_on_staff_who_read_the_audit_trail_get_php_and_database_time(): void
    {
        config(['app.server_timing' => true]);

        $response = $this->actingAs($this->superAdmin())->getJson(route('order-sessions.index'))->assertOk();

        $this->assertMatchesRegularExpression('/^boot;dur=\d+, total;dur=\d+, db;dur=\d+;desc="\d+ queries", php;desc="[\w-]+, opcache (on|OFF)"$/', $response->headers->get('Server-Timing'));
    }

    public function test_nobody_else_sees_it(): void
    {
        config(['app.server_timing' => true]);

        $this->actingAs($this->userWith('view order-sessions'))->getJson(route('order-sessions.index'))
            ->assertOk()->assertHeaderMissing('Server-Timing');

        $this->get(route('login'))->assertHeaderMissing('Server-Timing');
    }
}
