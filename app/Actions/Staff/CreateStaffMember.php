<?php

namespace App\Actions\Staff;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CreateStaffMember
{
    public function __construct(private SendStaffPasswordResetLink $sendPasswordResetLink) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, array $data): User
    {
        Gate::forUser($actor)->authorize('create', User::class);

        return DB::transaction(function () use ($actor, $data): User {
            $staff = new User;
            $staff->forceFill([
                ...Arr::only($data, ['name', 'email', 'role', 'is_active']),
                'password' => Str::random(64),
            ])->save();

            if ($staff->is_active) {
                $this->sendPasswordResetLink->handle($actor, $staff);
            }

            return $staff;
        });
    }
}
