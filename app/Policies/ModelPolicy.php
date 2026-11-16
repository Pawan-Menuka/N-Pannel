<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\RoleMatrix;
use Illuminate\Database\Eloquent\Model;

abstract class ModelPolicy
{
    /** @var class-string<Model> */
    protected const string Model = Model::class;

    public function viewAny(User $user): bool
    {
        return RoleMatrix::allows($user, static::Model, 'viewAny');
    }

    public function view(User $user, Model $record): bool
    {
        return RoleMatrix::allows($user, static::Model, 'view', $record);
    }

    public function create(User $user): bool
    {
        return RoleMatrix::allows($user, static::Model, 'create');
    }

    public function update(User $user, Model $record): bool
    {
        return RoleMatrix::allows($user, static::Model, 'update', $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return RoleMatrix::allows($user, static::Model, 'delete', $record);
    }

    public function restore(User $user, Model $record): bool
    {
        return RoleMatrix::allows($user, static::Model, 'restore', $record);
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return RoleMatrix::allows($user, static::Model, 'forceDelete', $record);
    }
}
