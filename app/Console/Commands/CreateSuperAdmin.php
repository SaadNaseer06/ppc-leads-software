<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;

/** First sign-in on a fresh production install (no demo data) */
class CreateSuperAdmin extends Command
{
    protected $signature = 'ppc:create-admin
        {email : Sign-in email}
        {name : Full name}
        {--password= : Password (min 8 characters); asked for interactively if left out}';

    protected $description = 'Create a Super Admin account, or reset its password if the email already exists';

    public function handle(): int
    {
        $password = $this->option('password') ?? $this->secret('Password (min 8 characters)');
        if (! is_string($password) || strlen($password) < 8) {
            $this->error('The password must be at least 8 characters.');

            return self::FAILURE;
        }

        $user = User::updateOrCreate(
            ['email' => strtolower(trim($this->argument('email')))],
            ['name' => $this->argument('name'), 'password' => $password, 'role' => Role::SuperAdmin, 'active' => true],
        );
        $this->info(($user->wasRecentlyCreated ? 'Created' : 'Updated')." Super Admin {$user->email}.");

        return self::SUCCESS;
    }
}
