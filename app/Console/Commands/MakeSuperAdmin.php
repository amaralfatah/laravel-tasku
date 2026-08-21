<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Bootstraps the first platform operator.
 *
 * Registration is disabled, so the very first account has to be created here.
 */
class MakeSuperAdmin extends Command
{
    protected $signature = 'tasku:super-admin
                            {email : Email of the operator account}
                            {--name= : Display name when the account is created}
                            {--password= : Password when the account is created}';

    protected $description = 'Create or promote a super admin account';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = User::where('email', $email)->first();

        if ($user === null) {
            $password = (string) ($this->option('password') ?: $this->secret('Kata sandi akun baru'));

            if (trim($password) === '') {
                $this->error('Kata sandi wajib diisi untuk akun baru.');

                return self::FAILURE;
            }

            $user = new User([
                'name' => (string) ($this->option('name') ?: 'Super Admin'),
                'email' => $email,
            ]);

            $user->password = Hash::make($password);
            $user->email_verified_at = now();
        }

        $user->is_super_admin = true;
        $user->save();

        $this->info("{$user->email} sekarang super admin.");

        return self::SUCCESS;
    }
}
