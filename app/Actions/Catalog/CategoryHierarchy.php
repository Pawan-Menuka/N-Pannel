<?php

namespace App\Actions\Catalog;

use App\Models\Category;
use Illuminate\Validation\ValidationException;

final class CategoryHierarchy
{
    /** @return list<int> */
    public function excludedIds(?Category $category): array
    {
        if ($category === null || ! $category->exists) {
            return [];
        }
        $seen = [(int) $category->getKey() => true];
        $frontier = array_keys($seen);
        while ($frontier !== []) {
            $next = Category::query()->whereIn('parent_id', $frontier)->pluck('id')->all();
            $frontier = [];
            foreach ($next as $id) {
                if (! isset($seen[$id])) {
                    $seen[$id] = true;
                    $frontier[] = (int) $id;
                }
            }
        }

        return array_keys($seen);
    }

    public function ensureParent(?Category $category, ?int $parentId): void
    {
        $seen = [];
        while ($parentId !== null) {
            if (($category !== null && $category->exists && $parentId === (int) $category->getKey()) || isset($seen[$parentId])) {
                throw ValidationException::withMessages(['parent_id' => 'A category cannot have itself or a descendant as its parent']);
            }
            $seen[$parentId] = true;
            $parent = Category::query()->find($parentId);
            if ($parent === null) {
                throw ValidationException::withMessages(['parent_id' => 'Choose an existing parent category']);
            }
            $parentId = $parent->parent_id === null ? null : (int) $parent->parent_id;
        }
    }
}
