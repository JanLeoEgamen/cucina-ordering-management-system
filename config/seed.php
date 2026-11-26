<?php

// What `php artisan db:seed` creates (database/seeders/UserSeeder.php).
//
// Read through config(), never env() in a seeder: once `config:cache` / `optimize` has run,
// Laravel no longer loads .env and env() returns null outside the config files. After
// changing .env on a server with cached config, run `php artisan config:cache` again.

return [

    // The first Super Admin. No email = no account is created. No password = a random one is
    // generated and shown once. Never write either one in the repository.
    'owner' => [
        'email' => env('SEED_OWNER_EMAIL'),
        'password' => env('SEED_OWNER_PASSWORD'),
        'first_name' => env('SEED_OWNER_FIRST_NAME', 'Owner'),
        'last_name' => env('SEED_OWNER_LAST_NAME', 'Account'),
    ],

];
