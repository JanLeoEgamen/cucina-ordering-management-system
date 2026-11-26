<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Dish;
use App\Models\DishType;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// What `php artisan migrate:fresh --seed` leaves behind.
class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER = 'owner@example.com';

    // Whatever the developer's .env says must not decide what these tests see.
    protected function setUp(): void
    {
        parent::setUp();

        config(['seed.owner' => [
            'email' => self::OWNER,
            'password' => 'Owner-pass-1!',
            'first_name' => 'Olive',
            'last_name' => 'Owner',
        ]]);
    }

    private function seedAccounts(): void
    {
        $this->seedPermissions();
        $this->app->make(UserSeeder::class)->setContainer($this->app)->run();
    }

    public function test_a_fresh_seed_gives_the_configured_owner_a_super_admin_account(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::where('email', self::OWNER)->firstOrFail();

        $this->assertSame('Olive', $user->first_name);
        $this->assertSame('Owner', $user->last_name);
        $this->assertTrue(Hash::check('Owner-pass-1!', $user->password));
        $this->assertNotNull($user->profile_completed_at);
        $this->assertTrue($user->hasRole(Role::SUPER_ADMIN));

        // Super Admin holds every permission the app checks.
        $this->assertEqualsCanonicalizing(Role::PERMISSIONS, $user->getAllPermissions()->pluck('name')->all());
    }

    public function test_without_an_owner_password_a_random_one_is_shown_once(): void
    {
        config(['seed.owner.password' => null]);
        $this->seedPermissions();

        $this->artisan('db:seed', ['--class' => UserSeeder::class])
            ->expectsOutputToContain('Owner ' . self::OWNER . ' created with password: ')
            ->assertSuccessful();

        $hash = User::where('email', self::OWNER)->firstOrFail()->password;

        foreach (['', 'password', 'Password123!'] as $guess) {
            $this->assertFalse(Hash::check($guess, $hash));
        }
    }

    public function test_without_an_owner_email_no_owner_is_created(): void
    {
        config(['seed.owner.email' => null]);

        $this->seedAccounts();

        $this->assertSame([UserSeeder::DEVELOPMENT_EMAIL], User::pluck('email')->all());
    }

    public function test_a_server_gets_no_developer_account(): void
    {
        // Before the switch: in production `db:seed` stops to ask for confirmation.
        $this->seedPermissions();
        $this->app['env'] = 'production';

        $this->seedAccounts();

        $this->assertSame([self::OWNER], User::pluck('email')->all());
    }

    public function test_an_account_that_already_has_the_owner_email_is_not_touched_or_promoted(): void
    {
        // Registration does not prove who owns an address.
        $stranger = User::factory()->create(['email' => self::OWNER]);
        $hash = $stranger->password;

        $this->seedAccounts();

        $this->assertSame($hash, $stranger->fresh()->password);
        $this->assertCount(0, $stranger->fresh()->roles);
    }

    public function test_a_deleted_account_with_the_owner_email_does_not_break_the_seed(): void
    {
        User::factory()->create(['email' => self::OWNER])->delete();

        $this->seedAccounts();

        $this->assertNull(User::where('email', self::OWNER)->first());
    }

    public function test_the_permission_seeder_gives_nobody_a_role(): void
    {
        $emails = ['test@example.com', self::OWNER, 'jleoegamen6@gmail.com', 'janleoegamen6@bounty.com.ph'];

        foreach ($emails as $email) {
            User::factory()->create(['email' => $email]);
        }

        $this->seed(PermissionSeeder::class);

        $this->assertDatabaseCount('model_has_roles', 0);
    }

    public function test_seeding_again_does_not_give_back_a_removed_super_admin_role(): void
    {
        $this->seed(DatabaseSeeder::class);
        $owner = User::where('email', self::OWNER)->firstOrFail();
        User::syncUserRoles($owner->id, []);

        $this->seed(DatabaseSeeder::class);

        $this->assertCount(0, $owner->fresh()->roles);
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
