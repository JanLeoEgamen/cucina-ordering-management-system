<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// The Companies page: the list the "Company" dropdowns of register / profile / Users are made of.
class CompanyTest extends TestCase
{
    use RefreshDatabase;

    private function ajax(): array
    {
        return ['X-Requested-With' => 'XMLHttpRequest'];
    }

    public function test_the_page_and_the_feed_list_live_companies_with_their_users(): void
    {
        $acme = Company::factory()->create(['company_name' => 'Acme Foods']);
        Company::factory()->create(['company_name' => 'Gone Inc', 'deleted_at' => now()]);
        User::factory()->count(2)->create(['company_id' => $acme->company_id]);
        // A deleted account is not counted.
        User::factory()->create(['company_id' => $acme->company_id, 'deleted_at' => now()]);

        $admin = $this->superAdmin();

        $this->actingAs($admin)->get(route('companies.index'))->assertOk()->assertSee('Companies');

        $rows = collect($this->actingAs($admin)->withHeaders($this->ajax())
            ->getJson(route('companies.index'))->assertOk()->json('data'));

        // (The admin's own company is a row too.)
        $this->assertContains('Acme Foods', $rows->pluck('company_name')->all());
        $this->assertNotContains('Gone Inc', $rows->pluck('company_name')->all());

        $row = $rows->firstWhere('company_name', 'Acme Foods');
        $this->assertSame(2, (int) $row['users_count']);
        $this->assertStringContainsString('data-status="active"', $row['is_active']);
    }

    public function test_the_feed_sends_the_name_as_typed_for_the_page_to_escape_once(): void
    {
        Company::factory()->create(['company_name' => "O'Kon & <b>Sons</b>"]);

        $rows = collect($this->actingAs($this->superAdmin())->withHeaders($this->ajax())
            ->getJson(route('companies.index'))->json('data'));

        $this->assertContains("O'Kon & <b>Sons</b>", $rows->pluck('company_name')->all());
    }

    public function test_a_company_can_be_created_edited_and_read(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->postJson(route('companies.store'), ['company_name' => 'Acme Foods', 'is_active' => true])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $company = Company::query()->where('company_name', 'Acme Foods')->firstOrFail();

        $this->actingAs($admin)
            ->putJson(route('companies.update', $company->company_id), ['company_name' => 'Acme Global', 'is_active' => false])
            ->assertOk();

        $this->assertDatabaseHas('companies', ['company_id' => $company->company_id, 'company_name' => 'Acme Global', 'is_active' => 0]);

        $this->actingAs($admin)->getJson(route('companies.edit', $company->company_id))
            ->assertOk()
            ->assertJsonPath('company_name', 'Acme Global')
            ->assertJsonPath('users_count', 0)
            ->assertJsonStructure(['status']);
    }

    public function test_the_name_is_required_and_unique_among_live_companies(): void
    {
        $admin = $this->superAdmin();
        Company::factory()->create(['company_name' => 'Acme Foods']);

        $this->actingAs($admin)->postJson(route('companies.store'), ['company_name' => '', 'is_active' => true])
            ->assertStatus(422)->assertJsonValidationErrors('company_name');

        $this->actingAs($admin)->postJson(route('companies.store'), ['company_name' => 'Acme Foods', 'is_active' => true])
            ->assertStatus(422)->assertJsonValidationErrors('company_name');

        $this->actingAs($admin)->postJson(route('companies.store'), ['company_name' => str_repeat('a', 256), 'is_active' => true])
            ->assertStatus(422)->assertJsonValidationErrors('company_name');

        // A deleted company does not hold its name, and a company may be saved under its own name.
        Company::factory()->create(['company_name' => 'Old Name', 'deleted_at' => now()]);
        $this->actingAs($admin)->postJson(route('companies.store'), ['company_name' => 'Old Name', 'is_active' => true])->assertOk();

        $acme = Company::query()->where('company_name', 'Acme Foods')->firstOrFail();
        $this->actingAs($admin)->putJson(route('companies.update', $acme->company_id), ['company_name' => 'Acme Foods', 'is_active' => true])->assertOk();
    }

    public function test_a_company_without_users_can_be_deleted_and_is_then_gone(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->superAdmin())
            ->deleteJson(route('companies.destroy', $company->company_id))
            ->assertOk();

        $this->assertSoftDeleted('companies', ['company_id' => $company->company_id]);

        $this->actingAs($this->superAdmin())->getJson(route('companies.edit', $company->company_id))->assertNotFound();
        $this->actingAs($this->superAdmin())->deleteJson(route('companies.destroy', $company->company_id))->assertNotFound();
    }

    public function test_a_company_that_still_has_users_cannot_be_deleted(): void
    {
        $company = Company::factory()->create();
        $member = User::factory()->create(['company_id' => $company->company_id]);

        $this->actingAs($this->superAdmin())
            ->deleteJson(route('companies.destroy', $company->company_id))
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed');

        $this->assertDatabaseHas('companies', ['company_id' => $company->company_id, 'deleted_at' => null]);

        // Once the last person is gone (soft deleted), the company can be deleted.
        User::deleteUser($member->id);

        $this->actingAs($this->superAdmin())->deleteJson(route('companies.destroy', $company->company_id))->assertOk();
    }

    public function test_the_guest_company_cannot_be_edited_or_deleted(): void
    {
        $guest = Company::factory()->create(['company_name' => Company::GUEST]);
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->putJson(route('companies.update', $guest->company_id), ['company_name' => 'Renamed', 'is_active' => false])
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed');

        $this->actingAs($admin)
            ->deleteJson(route('companies.destroy', $guest->company_id))
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed');

        $this->assertDatabaseHas('companies', ['company_id' => $guest->company_id, 'company_name' => 'Guest', 'is_active' => 1, 'deleted_at' => null]);

        // It can still be read, and it says it is protected.
        $this->actingAs($admin)->getJson(route('companies.edit', $guest->company_id))
            ->assertOk()
            ->assertJsonPath('is_protected', true);
    }

    public function test_the_guest_row_only_offers_view_and_is_marked_protected(): void
    {
        Company::factory()->create(['company_name' => Company::GUEST]);
        Company::factory()->create(['company_name' => 'Acme Foods']);

        $rows = collect($this->actingAs($this->superAdmin())->withHeaders($this->ajax())
            ->getJson(route('companies.index'))->assertOk()->json('data'));

        $guest = $rows->firstWhere('company_name', 'Guest');
        $acme = $rows->firstWhere('company_name', 'Acme Foods');

        $this->assertTrue($guest['is_protected']);
        $this->assertStringContainsString('data-row-action="view"', $guest['action']);
        $this->assertStringNotContainsString('data-row-action="edit"', $guest['action']);
        $this->assertStringNotContainsString('data-row-action="delete"', $guest['action']);

        $this->assertFalse($acme['is_protected']);
        $this->assertStringContainsString('data-row-action="edit"', $acme['action']);
        $this->assertStringContainsString('data-row-action="delete"', $acme['action']);
    }

    public function test_nobody_can_rename_another_company_to_guest_while_it_exists(): void
    {
        Company::factory()->create(['company_name' => Company::GUEST]);
        $other = Company::factory()->create();

        $this->actingAs($this->superAdmin())
            ->putJson(route('companies.update', $other->company_id), ['company_name' => 'Guest', 'is_active' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('company_name');
    }

    public function test_each_action_needs_its_own_permission(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->userWith())->get(route('companies.index'))->assertForbidden();
        $this->actingAs($this->userWith('view companies'))->get(route('companies.index'))->assertOk();
        $this->actingAs($this->userWith('view companies'))->getJson(route('companies.edit', $company->company_id))->assertOk();

        $this->actingAs($this->userWith('view companies'))
            ->postJson(route('companies.store'), ['company_name' => 'X', 'is_active' => true])->assertForbidden();
        $this->actingAs($this->userWith('create companies'))
            ->postJson(route('companies.store'), ['company_name' => 'X', 'is_active' => true])->assertOk();

        $this->actingAs($this->userWith('view companies', 'create companies'))
            ->putJson(route('companies.update', $company->company_id), ['company_name' => 'Y', 'is_active' => true])->assertForbidden();
        $this->actingAs($this->userWith('edit companies'))
            ->putJson(route('companies.update', $company->company_id), ['company_name' => 'Y', 'is_active' => true])->assertOk();

        $this->actingAs($this->userWith('view companies', 'edit companies'))
            ->deleteJson(route('companies.destroy', $company->company_id))->assertForbidden();
        $this->actingAs($this->userWith('delete companies'))
            ->deleteJson(route('companies.destroy', $company->company_id))->assertOk();
    }

    public function test_a_customer_cannot_open_the_companies_page(): void
    {
        $this->seedPermissions();
        $customer = User::factory()->create();
        $customer->assignRole('Customer');

        $this->actingAs($customer)->get(route('companies.index'))->assertForbidden();
    }

    public function test_the_sidebar_link_shows_only_with_the_permission(): void
    {
        $this->actingAs($this->userWith('view companies'))->get(route('companies.index'))
            ->assertSee('href="' . route('companies.index') . '"', false);

        $this->actingAs($this->userWith('view users'))->get(route('users.index'))
            ->assertDontSee('href="' . route('companies.index') . '"', false);
    }

    public function test_company_changes_are_in_the_audit_trail_with_the_company_name(): void
    {
        $company = Company::factory()->create(['company_name' => 'Acme Foods']);

        $this->actingAs($this->superAdmin())
            ->putJson(route('companies.update', $company->company_id), ['company_name' => 'Acme Global', 'is_active' => true])
            ->assertOk();

        $entry = DB::table('audit_logs')->orderByDesc('id')->first();

        $this->assertSame('companies.update', $entry->action);
        $this->assertSame('Edited a company', $entry->description);
        $this->assertSame('Acme Global', $entry->subject_label);
        $this->assertSame(['Acme Foods', 'Acme Global'], json_decode($entry->changes, true)['company_name']);
    }

    // The old free-text `users.company` becomes companies on migrate; this replays the migration
    // on a database that still has the column.
    public function test_the_migration_turns_the_typed_companies_into_records_and_back(): void
    {
        $migration = require database_path('migrations/2026_11_20_150000_create_companies_table_and_link_users.php');

        $migration->down();

        $ids = [];

        foreach ([
            'Acme Foods', ' acme foods ', 'Globex', '   ', null,
        ] as $index => $company) {
            $ids[$index] = DB::table('users')->insertGetId([
                'first_name' => 'U' . $index, 'last_name' => 'T', 'email' => "u{$index}@example.com", 'password' => 'x', 'company' => $company,
            ]);
        }

        $migration->up();

        // "Acme Foods" and " acme foods " are one company (the first spelling wins); a blank and a
        // null leave the person without one.
        $this->assertSame(['Acme Foods', 'Globex'], DB::table('companies')->orderBy('company_id')->pluck('company_name')->all());
        $this->assertSame(DB::table('users')->where('id', $ids[0])->value('company_id'), DB::table('users')->where('id', $ids[1])->value('company_id'));
        $this->assertNotNull(DB::table('users')->where('id', $ids[0])->value('company_id'));
        $this->assertNotNull(DB::table('users')->where('id', $ids[2])->value('company_id'));
        $this->assertNull(DB::table('users')->where('id', $ids[3])->value('company_id'));
        $this->assertNull(DB::table('users')->where('id', $ids[4])->value('company_id'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('users', 'company'));

        // And back: the names are written into the column again.
        $migration->down();

        $this->assertSame('Acme Foods', DB::table('users')->where('id', $ids[1])->value('company'));
        $this->assertSame('Globex', DB::table('users')->where('id', $ids[2])->value('company'));

        $migration->up();
    }
}
