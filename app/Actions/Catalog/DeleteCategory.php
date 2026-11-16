<?php

namespace App\Actions\Catalog;

use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DeleteCategory
{
    public function handle(Category $category, User $actor): bool
    {
        Gate::forUser($actor)->authorize('delete', $category);

        return DB::transaction(function () use ($category): bool {
            $category = Category::query()->lockForUpdate()->findOrFail($category->getKey());
            if ($category->products()->exists() || $category->children()->exists()) {
                throw ValidationException::withMessages(['category' => 'Move or remove its products first']);
            }

            return (bool) $category->delete();
        });
    }
}
