<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/** Demo accounts for development and staging. Every account's password is "password123". */
class DatabaseSeeder extends Seeder
{
    /*
    |--------------------------------------------------------------------------
    | Demo leads / websites / chats / invoices (disabled — users only)
    |--------------------------------------------------------------------------
    |
    | private const FIRST = ['Olivia', 'Liam', 'Emma', 'Noah', 'Ava', 'Mason', 'Sophia', 'Ethan', 'Mia', 'Lucas', 'Amelia', 'Aiden', 'Harper', 'Elijah', 'Ella', 'James', 'Aria', 'Daniel', 'Zara', 'Omar', 'Priya', 'Hassan', 'Grace', 'Mateo'];
    | private const LAST = ['Smith', 'Johnson', 'Brown', 'Garcia', 'Miller', 'Davis', 'Martinez', 'Lopez', 'Wilson', 'Anderson', 'Thomas', 'Moore', 'Clark', 'Lewis', 'Walker', 'Hall', 'Young', 'King', 'Wright', 'Scott', 'Ahmed', 'Chen', 'Nguyen', 'Kaur'];
    | private const SITES = [ ... brands, websites, campaigns, sample chats ... ];
    |
    */

    public function run(): void
    {
        $password = Hash::make('password123');

        $user = function (string $email, string $name, Role $role, array $brandIds = [], ?int $adminId = null) use ($password) {
            $u = User::create([
                'email' => $email,
                'name' => $name,
                'password' => $password,
                'role' => $role,
                'admin_id' => $adminId,
                'active' => true,
            ]);
            if ($brandIds !== []) {
                $u->brands()->sync($brandIds);
            }

            return $u;
        };

        $user('superadmin@ppc.lead', 'Alex Morgan', Role::SuperAdmin);
        $user('admin@ppc.lead', 'Jordan Lee', Role::Admin);
        $user('front1@ppc.lead', 'Sam Rivera', Role::FrontSales);
        $user('front2@ppc.lead', 'Taylor Brooks', Role::FrontSales);
        $user('front3@ppc.lead', 'Riley Chen', Role::FrontSales);
        $user('upsales@ppc.lead', 'Casey Patel', Role::UpSales);
        $user('finance@ppc.lead', 'Morgan Ellis', Role::Finance);
        $user('dev@ppc.lead', 'Dev Account', Role::Developer);

        /*
        |--------------------------------------------------------------------------
        | Brands, websites, leads, chats, invoices — commented out
        |--------------------------------------------------------------------------
        |
        | $apex = Brand::create(['name' => 'Apex Home Services', 'slug' => 'apex-home', 'auto_assign_enabled' => true]);
        | $lumen = Brand::create(['name' => 'Lumen Dental', 'slug' => 'lumen-dental', 'auto_assign_enabled' => false]);
        | // attach brand_ids on Admin / Front Sales / Up Sales via $user(..., [$apex->id, $lumen->id])
        | // then create Website rows, ~400 leads, Tawk conversations, and sample invoices
        | // (full script was in git history / previous version of this seeder)
        |
        */

        $this->command?->info('Seeded '.User::count().' users.');
        $this->command?->info('Demo password for every account: password123');
    }
}
