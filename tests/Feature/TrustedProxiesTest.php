<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Behind the load balancer (docs/deployment.md) the app must read the real client from the
// X-Forwarded-* headers, but ONLY when TRUSTED_PROXIES says so: otherwise any client could
// forge its address and dodge the login throttle.
class TrustedProxiesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        putenv('TRUSTED_PROXIES');
        unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);

        parent::tearDown();
    }

    private function loginUrlSeenBehindProxy(): string
    {
        // The middleware list is built when the app boots, so rebuild it after setting the variable.
        $this->refreshApplication();
        // A new app means a new in-memory database.
        $this->artisan('migrate');

        $page = $this->actingAs(User::factory()->create())
            ->withHeaders([
                'X-Forwarded-For' => '203.0.113.9',
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Host' => 'cucina.example.test',
            ])
            ->get('/profile');

        $page->assertOk();
        preg_match('/<meta name="login-url" content="([^"]+)">/', $page->getContent(), $found);

        return $found[1] ?? '';
    }

    public function test_forwarded_headers_are_ignored_when_no_proxy_is_trusted(): void
    {
        $this->assertStringStartsWith('http://', $this->loginUrlSeenBehindProxy());
    }

    public function test_forwarded_headers_are_used_when_the_balancer_is_trusted(): void
    {
        putenv('TRUSTED_PROXIES=*');
        $_ENV['TRUSTED_PROXIES'] = '*';

        $this->assertSame('https://cucina.example.test/login', $this->loginUrlSeenBehindProxy());
    }

    public function test_only_the_listed_proxy_is_trusted(): void
    {
        // The test client is 127.0.0.1: a list that does not contain it must not be trusted.
        putenv('TRUSTED_PROXIES=10.0.0.0/8, 192.168.0.0/16');
        $_ENV['TRUSTED_PROXIES'] = '10.0.0.0/8, 192.168.0.0/16';

        $this->assertStringStartsWith('http://', $this->loginUrlSeenBehindProxy());
    }
}
