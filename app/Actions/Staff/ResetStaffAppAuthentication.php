<?php

namespace App\Actions\Staff;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ResetStaffAppAuthentication
{
    public function handle(User $actor, User $staff): void
    {
        Gate::forUser($actor)->authorize('update', $staff);

        DB::transaction(function () use ($staff): void {
            $staff->forceFill([
                'app_authentication_secret' => null,
                'app_authentication_recovery_codes' => null,
                'remember_token' => Str::random(60),
            ])->save();

            DB::connection(config('session.connection'))
                ->table(config('session.table', 'sessions'))
                ->where('user_id', $staff->getKey())->delete();
        });
    }
}
