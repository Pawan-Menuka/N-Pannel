<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrNew(['email' => config('seed.admin_email')]);
        $generatedPassword = null;

        if (! $user->exists) {
            $password = config('seed.admin_password');

            if (! is_string($password) || $password === '') {
                $password = $generatedPassword = Str::password(20);
            }

            $user->password = $password;
            $user->forceFill([
                'name' => 'Admin',
                'role' => UserRole::Admin,
                'is_active' => true,
            ])->save();
        }

        if ($generatedPassword !== null && ! app()->environment('production')) {
            $this->command?->warn('Generated admin password: '.$generatedPassword);
        }
    }
}
