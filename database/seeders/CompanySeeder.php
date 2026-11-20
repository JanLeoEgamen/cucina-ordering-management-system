<?php

namespace Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Seeder;

// The companies the first customers belong to. Without one the register form's Company
// dropdown is empty and nobody can sign up. Repeatable: a company that exists (by name) is
// left as it is, so a name edited or switched off on the Companies page is not put back.
class CompanySeeder extends Seeder
{
    public const COMPANIES = [
        'Bounty Fresh Inc.',
        'Bounty Plus Inc.',
        // Locked in the app (Company::PROTECTED): for people who belong to none of the others.
        Company::GUEST,
    ];

    public function run(): void
    {
        foreach (self::COMPANIES as $name) {
            Company::firstOrCreate(['company_name' => $name], ['is_active' => true]);
        }
    }
}
