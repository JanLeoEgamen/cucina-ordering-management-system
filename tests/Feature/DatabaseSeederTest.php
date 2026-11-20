<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Dish;
use App\Models\DishType;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// What `php artisan migrate:fresh --seed` leaves behind.
class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_seed_gives_the_owner_a_super_admin_account(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::where('email', 'jleoegamen6@gmail.com')->firstOrFail();

        $this->assertSame('Jan Leo', $user->first_name);
        $this->assertSame('Egamen', $user->last_name);
        $this->assertTrue(Hash::check('Password123!', $user->password));
        $this->assertNotNull($user->profile_completed_at);
        $this->assertTrue($user->hasRole(Role::SUPER_ADMIN));

        // Super Admin holds every permission the app checks.
        $this->assertEqualsCanonicalizing(Role::PERMISSIONS, $user->getAllPermissions()->pluck('name')->all());
    }

    public function test_a_fresh_seed_loads_roles_permissions_dishes_and_dish_types(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(count(Role::PERMISSIONS), \Spatie\Permission\Models\Permission::count());
        $this->assertSame(1, \Spatie\Permission\Models\Role::where('name', Role::CUSTOMER)->count());
        $this->assertGreaterThan(0, Dish::count());
        $this->assertGreaterThan(0, DishType::count());
    }

    public function test_a_fresh_seed_offers_the_bounty_companies_and_guest_at_registration(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(
            ['Bounty Fresh Inc.', 'Bounty Plus Inc.', 'Guest'],
            Company::where('is_active', true)->orderBy('company_name')->pluck('company_name')->all()
        );

        $this->get('/register')->assertOk()->assertSee('Bounty Fresh Inc.')->assertSee('Bounty Plus Inc.')->assertSee('Guest');
    }

    public function test_reseeding_keeps_a_company_that_was_switched_off_or_renamed(): void
    {
        $this->seed(DatabaseSeeder::class);

        Company::where('company_name', 'Bounty Plus Inc.')->update(['is_active' => false]);
        Company::where('company_name', 'Bounty Fresh Inc.')->update(['company_name' => 'Bounty Fresh Foods']);

        $this->seed(DatabaseSeeder::class);

        // Switched off stays off; the renamed one is not recreated under its old name.
        $this->assertFalse((bool) Company::where('company_name', 'Bounty Plus Inc.')->value('is_active'));
        $this->assertSame(['Bounty Fresh Foods', 'Bounty Fresh Inc.', 'Bounty Plus Inc.', 'Guest'], Company::orderBy('company_name')->pluck('company_name')->all());
    }

    public function test_seeding_twice_changes_nothing(): void
    {
        $this->seed(DatabaseSeeder::class);

        $counts = [User::count(), Dish::count(), DishType::count(), Company::count()];

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($counts, [User::count(), Dish::count(), DishType::count(), Company::count()]);
    }
}
