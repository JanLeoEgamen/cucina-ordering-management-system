<?php

namespace Tests\Feature;

use App\Models\OrderSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Every table's toolbar has the Export menu (Excel / PDF). The files themselves are built
// in the browser (resources/js/ui/table-export.js); here the markup it relies on is checked.
class TableExportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Each page with a table: its route, and the title its export is named after.
     *
     * @return array<string, array{string, string}>
     */
    public static function tables(): array
    {
        return [
            'dishes' => ['dishes.index', 'Dish Masterlist'],
            'order sessions' => ['order-sessions.index', 'Order Sessions'],
            'orders' => ['orders.index', 'Orders'],
            'payments' => ['payments.index', 'Payments'],
            'payment methods' => ['payment-methods.index', 'Payment Methods'],
            'roles' => ['roles.index', 'Roles'],
            'users' => ['users.index', 'Users'],
        ];
    }

    /**
     * @dataProvider tables
     */
    public function test_every_table_toolbar_offers_excel_and_pdf(string $route, string $title): void
    {
        $html = $this->actingAs($this->superAdmin())->get(route($route))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-table-export-toggle'));
        $this->assertStringContainsString('data-table-export="xlsx"', $html);
        $this->assertStringContainsString('data-table-export="pdf"', $html);
        $this->assertStringContainsString('data-export-title="' . $title . '"', $html);

        // The running number and the action buttons are never exported.
        $this->assertStringContainsString('<th scope="col" data-no-export', $html);
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'data-no-export'));
    }

    public function test_the_orders_table_of_a_session_exports_too_and_is_named_after_the_session(): void
    {
        $session = OrderSession::factory()->create();

        $html = $this->actingAs($this->superAdmin())->get(route('order-sessions.show', $session->session_id))->assertOk()->getContent();

        $this->assertStringContainsString('data-table-export="xlsx"', $html);
        $this->assertStringContainsString('data-table-export="pdf"', $html);
        $this->assertMatchesRegularExpression('/data-export-title="Orders [A-Z][a-z]{2} \d{1,2}, \d{4}"/', $html);
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'data-no-export'));
    }
}
