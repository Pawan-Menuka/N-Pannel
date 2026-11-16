<?php

namespace App\Actions\Staff;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UpdateStaffMember
{
    /** @param array<string, mixed> $data */
    public function handle(User $actor, User $staff, array $data): User
    {
        Gate::forUser($actor)->authorize('update', $staff);

        return DB::transaction(function () use ($actor, $staff, $data): User {
            // Lock the same administrator rows in order so concurrent demotions cannot remove all admins.
            User::query()->where('role', UserRole::Admin)
                ->orWhereKey($staff->getKey())->orWhereKey($actor->getKey())
                ->orderBy('id')->lockForUpdate()->get();

            $actor->refresh();
            $staff->refresh();
            Gate::forUser($actor)->authorize('update', $staff);
            $data = Arr::only($data, ['name', 'email', 'role', 'is_active']);
            $role = $data['role'] ?? $staff->role;
            $role = $role instanceof UserRole ? $role : UserRole::from($role);
            $isActive = (bool) ($data['is_active'] ?? $staff->is_active);

            if ($staff->is_active && $staff->isAdmin() && (! $isActive || $role !== UserRole::Admin)) {
                $hasAnotherAdmin = User::query()->where('role', UserRole::Admin)
                    ->where('is_active', true)->whereKeyNot($staff->getKey())->exists();

                if (! $hasAnotherAdmin) {
                    throw ValidationException::withMessages([
                        ! $isActive ? 'data.is_active' : 'data.role' => 'At least one active admin must remain.',
                    ]);
                }
            }

            if ($actor->is($staff)) {
                if (! $isActive) {
                    throw ValidationException::withMessages(['data.is_active' => 'You cannot deactivate your own account.']);
                }

                if ($role !== $staff->role) {
                    throw ValidationException::withMessages(['data.role' => 'You cannot change your own role.']);
                }
            }

            $staff->forceFill($data)->save();

            return $staff;
        });
    }
}
