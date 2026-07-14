<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Create (or update the password of) an admin user for the Filament panel.
 *
 * Production-safe: does not rely on factories/faker (which are dev-only), so it
 * works on a --no-dev install. Prompts for anything not passed as an option.
 */
class CreateAdminUser extends Command
{
    protected $signature = 'app:create-admin
        {--name= : Full name}
        {--email= : Login email}
        {--password= : Password (min 8 chars); prompted securely if omitted}';

    protected $description = 'Create or update an admin user for the /admin panel';

    public function handle(): int
    {
        $name = $this->option('name') ?: text('Name', default: 'Admin', required: true);
        $email = $this->option('email') ?: text('Email', required: true);
        $plain = $this->option('password') ?: password('Password (min 8 chars)', required: true);

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $plain],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email'],
                'password' => ['required', 'string', 'min:8'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make($plain), 'role' => UserRole::Admin],
        );

        $this->info($user->wasRecentlyCreated
            ? "Admin user created: {$email}"
            : "Existing user updated: {$email}");

        return self::SUCCESS;
    }
}
