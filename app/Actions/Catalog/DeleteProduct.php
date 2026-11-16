<?php

namespace App\Actions\Catalog;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DeleteProduct
{
    public function handle(Product $product, User $actor): bool
    {
        Gate::forUser($actor)->authorize('delete', $product);

        return DB::transaction(function () use ($product): bool {
            $product = Product::query()->lockForUpdate()->findOrFail($product->getKey());
            $variants = $product->variants()->lockForUpdate()->get();
            foreach ($variants as $variant) {
                app(DeleteVariant::class)->ensureNoHistory($variant);
            }
            foreach ($variants as $variant) {
                $variant->delete();
            }

            return (bool) $product->delete();
        });
    }
}
