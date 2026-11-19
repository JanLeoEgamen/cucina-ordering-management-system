<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// One-off clean-up (known-issues F2): accounts that registered BEFORE sign-ups got the
// Customer role have no role, so the menu answers 403. This gives it to every active account
// that holds no role at all. Accounts that already have any role are never touched, so it is
// safe to run twice. Look first with --dry-run: an admin may have emptied someone's roles on
// purpose to block them (the Users page), and this would give that person Customer access back.
class GiveCustomerRole extends Command
{
    protected $signature = 'users:give-customer-role {--dry-run : Only list the accounts, change nothing}';

    protected $description = 'Give the Customer role to every active account that has no role (accounts registered before sign-ups got it)';

    public function handle(): int
    {
        $roleExists = DB::table('roles')->where('name', Role::CUSTOMER)->where('guard_name', 'web')->exists();

        if (! $roleExists) {
            $this->error('The Customer role does not exist yet. Run: php artisan db:seed --class=PermissionSeeder');

            return self::FAILURE;
        }

        $users = User::rolelessUsers();

        if ($users === []) {
            $this->info('Every active account already has a role. Nothing to do.');

            return self::SUCCESS;
        }

        foreach ($users as $user) {
            $this->line('  #' . $user->id . '  ' . $user->email);
        }

        if ($this->option('dry-run')) {
            $this->info(count($users) . ' account(s) have no role (dry run, nothing changed).');

            return self::SUCCESS;
        }

        foreach ($users as $user) {
            User::giveCustomerRole((int) $user->id);
        }

        $this->info(count($users) . ' account(s) now have the Customer role.');

        return self::SUCCESS;
    }
}
