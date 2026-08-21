<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the platform operator plus one worked example workspace.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@tasku.test'],
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
