<?php

namespace Tests\Feature;

use App\Models\OrderSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// A person is a first and a last name; `name` is only their joined display form.
class UserNameTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        $this->seedPermissions();

        return $overrides + [
            'first_name' => 'Jan Leo',
            'last_name' => 'Egamen',
            'email' => 'jan@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'roles' => [DB::table('roles')->value('id')],
        ];
    }

    public function test_an_admin_creates_a_user_with_a_first_and_a_last_name(): void
    {
        $this->actingAs($this->superAdmin())
            ->postJson(route('users.store'), $this->payload())
            ->assertOk();

        $user = User::where('email', 'jan@example.com')->firstOrFail();

        $this->assertSame('Jan Leo', $user->first_name);
        $this->assertSame('Egamen', $user->last_name);
        // The full name is computed, not stored.
        $this->assertSame('Jan Leo Egamen', $user->name);
    }

    public function test_both_names_are_required(): void
    {
        $this->actingAs($this->superAdmin())
            ->postJson(route('users.store'), $this->payload(['first_name' => '', 'last_name' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['first_name', 'last_name']);
    }

    public function test_the_feed_and_the_edit_record_carry_the_parts_and_the_full_name(): void
    {
        $admin = $this->superAdmin();
        $user = User::factory()->create(['first_name' => 'Bea', 'last_name' => 'Cruz']);

        $rows = $this->actingAs($admin)->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route('users.index'))->assertOk()->json('data');

        $row = collect($rows)->firstWhere('id', $user->id);

        $this->assertSame('Bea Cruz', $row['name']);
        $this->assertSame('Bea', $row['first_name']);
        $this->assertSame('Cruz', $row['last_name']);

        $this->actingAs($admin)->getJson(route('users.edit', $user->id))
            ->assertOk()
            ->assertJsonPath('first_name', 'Bea')
            ->assertJsonPath('last_name', 'Cruz')
            ->assertJsonPath('name', 'Bea Cruz');
    }

    public function test_the_profile_and_registration_forms_ask_for_both(): void
    {
        $this->get('/register')->assertOk()->assertSee('name="first_name"', false)->assertSee('name="last_name"', false);

        $this->actingAs(User::factory()->create())->get('/profile')
            ->assertOk()->assertSee('name="first_name"', false)->assertSee('name="last_name"', false);
    }

    public function test_other_pages_show_the_creator_by_full_name(): void
    {
        $creator = User::factory()->create(['first_name' => 'Cy', 'last_name' => 'Dela Cruz']);
        OrderSession::factory()->create(['created_by' => $creator->id]);

        $row = $this->actingAs($this->userWith('view order-sessions'))
            ->getJson(route('order-sessions.index'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->json('data.0');

        $this->assertSame('Cy Dela Cruz', $row['created_by_name']);
    }

    public function test_the_migration_splits_an_old_name_on_its_last_space(): void
    {
        $migration = require database_path('migrations/2026_11_12_200000_split_user_name_into_first_and_last_name.php');

        // Back to the old shape, with three kinds of names written the old way.
        $migration->down();

        foreach (['Jan Leo Egamen' => 'a@example.com', 'Madonna' => 'b@example.com', '  Ana   Reyes ' => 'c@example.com'] as $name => $email) {
            DB::table('users')->insert(['name' => $name, 'email' => $email, 'password' => 'x']);
        }

        $migration->up();

        $split = DB::table('users')->orderBy('email')->get(['first_name', 'last_name'])
            ->map(fn ($row) => [$row->first_name, $row->last_name])->all();

        $this->assertSame([['Jan Leo', 'Egamen'], ['Madonna', ''], ['Ana', 'Reyes']], $split);
    }
}
