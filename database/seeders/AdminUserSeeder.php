<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $isDevelopment = app()->environment('local', 'testing');
        $email = config('seed.admin_email');
        $password = config('seed.admin_password');

        if (! $isDevelopment && (! is_string($email) || trim($email) === '')) {
            throw new RuntimeException('SEED_ADMIN_EMAIL must be set outside local/testing.');
        }

        $user = User::firstOrNew(['email' => $email]);

        if (! $user->exists) {
            if ($isDevelopment) {
                if (! is_string($password) || $password === '') {
                    $password = Str::password(20);
                    $this->command?->warn('Generated admin password: '.$password);
                }
            } elseif (! is_string($password) || trim($password) === '') {
                throw new RuntimeException('SEED_ADMIN_PASSWORD must be set when creating an admin outside local/testing.');
            }

            $user->password = $password;
            $user->forceFill([
                'name' => 'Admin',
                'role' => UserRole::Admin,
                'is_active' => true,
            ])->save();
        }
    }
}
