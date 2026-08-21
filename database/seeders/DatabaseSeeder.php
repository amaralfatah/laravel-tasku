<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the platform operator plus one worked example workspace.
     *
     * The operator is deliberately not a member of any workspace: SA-4 says a
     * super admin must not be able to read project or task content.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@perkebunan.test'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'is_super_admin' => true,
            ],
        );

        $this->call(DemoWorkspaceSeeder::class);
    }
}
