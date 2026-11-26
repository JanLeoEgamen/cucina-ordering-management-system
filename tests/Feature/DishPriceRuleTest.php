<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\DishType;
use App\Models\OrderSession;
use App\Models\PaymentMethod;
use App\Models\PreOrderSession;
use App\Models\PreOrderSessionDish;
use App\Models\SessionDish;
use App\Support\DishImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

// No dish is free (known-issues F11): the dish form and the import ask for at least 0.01, and a
// dish saved at 0.00 before that rule cannot be ordered. A 100% discount is still allowed.
class DishPriceRuleTest extends TestCase
{
    use RefreshDatabase;

    // PhpSpreadsheet books are only freed by the garbage collector (see CLAUDE.md, Dish import).
    protected function tearDown(): void
    {
        gc_collect_cycles();

        parent::tearDown();
    }

    private function payload(string $price): array
    {
        return [
            'dish_types' => [DishType::factory()->create()->dish_type_id],
            'dish_name' => 'Grilled Chicken',
            'price' => $price,
            'image' => UploadedFile::fake()->image('dish.jpg'),
            'is_active' => '1',
        ];
    }

    public function test_the_dish_form_refuses_a_free_dish(): void
    {
        $admin = $this->superAdmin();

        foreach (['0', '0.00', '0.004'] as $price) {
            $this->actingAs($admin)
                ->postJson(route('dishes.store'), $this->payload($price))
                ->assertStatus(422)
                ->assertJsonValidationErrors('price');
        }

        $this->assertDatabaseCount('dishes', 0);
    }

    public function test_editing_a_dish_cannot_make_it_free(): void
    {
        $dish = Dish::factory()->create(['price' => 50]);
        $payload = $this->payload('0');
        unset($payload['image']);

        $this->actingAs($this->superAdmin())
            ->putJson(route('dishes.update', $dish->dish_id), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('price');

        $this->assertEquals(50, $dish->fresh()->price);
    }

    public function test_one_centavo_is_a_price(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('dishes.store'), $this->payload('0.01'), ['Accept' => 'application/json'])
            ->assertOk();
    }

    public function test_an_import_with_a_free_dish_stores_nothing(): void
    {
        DishType::factory()->create(['type_name' => 'Mains']);

        $book = new Spreadsheet();
        $book->getActiveSheet()->setTitle('Dishes')->fromArray([
            ['Dish Name *', 'Dish Types *', 'Price *'],
            ['Priced', 'Mains', 50],
            ['Free', 'Mains', 0],
        ], null, 'A1', true);

        $path = tempnam(sys_get_temp_dir(), 'dish-price');
        (new Xlsx($book))->save($path);
        $file = UploadedFile::fake()->createWithContent('dishes.xlsx', file_get_contents($path));
        @unlink($path);

        $response = $this->actingAs($this->superAdmin())
            ->postJson(route('dishes.import'), ['file' => $file])
            ->assertStatus(422);

        $this->assertStringContainsString('Row 3: The Price field must be at least 0.01', implode("\n", $response->json('errors.file')));
        $this->assertDatabaseCount('dishes', 0);
    }

    public function test_the_template_asks_excel_for_at_least_one_centavo(): void
    {
        $rule = DishImport::template(collect())->getSheetByName('Dishes')->getDataValidation('C2');

        $this->assertSame('0.01', $rule->getFormula1());
    }

    public function test_a_dish_saved_free_before_the_rule_cannot_be_ordered(): void
    {
        $session = OrderSession::factory()->create();
        $line = SessionDish::factory()->create([
            'session_id' => $session->session_id,
            'dish_id' => Dish::factory()->create(['dish_name' => 'Old Freebie', 'price' => 0])->dish_id,
            'available_stock' => 5,
        ]);
        $cash = PaymentMethod::factory()->create(['method_name' => PaymentMethod::CASH_ON_PICKUP]);

        $response = $this->actingAs($this->userWith('view menu', 'create orders'))->postJson(route('orders.store'), [
            'session_id' => $session->session_id,
            'payment_method_id' => $cash->method_id,
            'items' => [$line->session_dish_id => 1],
        ])->assertStatus(422);

        $this->assertStringContainsString('Old Freebie has no price yet', $response->json('message'));
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(5, (int) DB::table('session_dishes')->where('session_dish_id', $line->session_dish_id)->value('available_stock'));
    }

    public function test_a_dish_saved_free_before_the_rule_cannot_be_pre_ordered(): void
    {
        $session = PreOrderSession::factory()->create(['min_quantity' => 1]);
        $line = PreOrderSessionDish::factory()->create([
            'pre_order_session_id' => $session->pre_order_session_id,
            'dish_id' => Dish::factory()->create(['dish_name' => 'Old Freebie', 'price' => 0])->dish_id,
        ]);
        $cash = PaymentMethod::factory()->create(['method_name' => PaymentMethod::CASH_ON_PICKUP]);

        $response = $this->actingAs($this->userWith('view pre-order-menu', 'create pre-orders'))->postJson(route('pre-orders.store'), [
            'pre_order_session_id' => $session->pre_order_session_id,
            'payment_method_id' => $cash->method_id,
            'items' => [$line->pre_order_session_dish_id => 10],
        ])->assertStatus(422);

        $this->assertStringContainsString('Old Freebie has no price yet', $response->json('message'));
        $this->assertDatabaseCount('pre_orders', 0);
    }
}
