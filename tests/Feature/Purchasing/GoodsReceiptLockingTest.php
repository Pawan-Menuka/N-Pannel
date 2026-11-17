<?php

use App\Actions\Purchasing\PostGoodsReceipt;
use App\Actions\Purchasing\SaveGoodsReceiptLine;
use App\Actions\Purchasing\VoidGoodsReceipt;
use App\Models\GoodsReceipt;
use App\Models\Location;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('allows concurrent stock movement foreign key checks while serializing receipt costs', function (bool $void) {
    $primary = DB::getDefaultConnection();
    config(['database.connections.purchasing_peer' => config('database.connections.'.$primary)]);
    DB::connection('purchasing_peer')->statement("SET lock_timeout = '5s'");
    DB::setDefaultConnection('purchasing_peer');
    try {
        [$variant, $location, $productId, $categoryId] = DB::transaction(fn (): array => Model::withoutEvents(function (): array {
            $variant = Variant::factory()->create();
            $location = Location::factory()->create();

            return [$variant, $location, $variant->product_id, $variant->product->category_id];
        }));
    } finally {
        DB::setDefaultConnection($primary);
    }
    /**
     * These fixtures must be committed for the peer connection to check foreign keys.
     * RefreshDatabase rolls back the main transaction before this cleanup callback runs.
     */
    $this->beforeApplicationDestroyed(function () use ($variant, $location, $productId, $categoryId): void {
        $peer = DB::connection('purchasing_peer');
        $peer->transaction(function () use ($peer, $variant, $location, $productId, $categoryId): void {
            $peer->table('stock_movements')->where('variant_id', $variant->id)->delete();
            $peer->table('stock_levels')->where('variant_id', $variant->id)->delete();
            $peer->table('variants')->where('id', $variant->id)->delete();
            $peer->table('products')->where('id', $productId)->delete();
            $peer->table('categories')->where('id', $categoryId)->delete();
            $peer->table('locations')->where('id', $location->id)->delete();
        });
        DB::purge('purchasing_peer');
    });
    $actor = User::factory()->stock()->create();
    $receipt = GoodsReceipt::factory()->create(['location_id' => $location->id]);
    app(SaveGoodsReceiptLine::class)->handle($receipt, null, $variant->id, 2, 100, $actor);
    if ($void) {
        app(PostGoodsReceipt::class)->handle($receipt, $actor);
    }
    $checked = false;
    DB::listen(function (QueryExecuted $query) use (&$checked, $primary, $variant, $location): void {
        if ($checked || $query->connectionName !== $primary || ! str_contains($query->sql, 'from "variants"') || ! str_contains($query->sql, 'for ')) {
            return;
        }
        $checked = true;
        DB::setDefaultConnection('purchasing_peer');
        try {
            DB::transaction(function () use ($variant, $location): void {
                DB::statement("SET LOCAL lock_timeout = '250ms'");
                Model::withoutEvents(fn () => StockMovement::factory()->create([
                    'variant_id' => $variant->id, 'location_id' => $location->id,
                    'user_id' => null, 'source_type' => null, 'source_id' => null,
                ]));
            });
        } finally {
            DB::setDefaultConnection($primary);
        }
    });

    if ($void) {
        app(VoidGoodsReceipt::class)->handle($receipt, $actor, 'Correction');
    } else {
        app(PostGoodsReceipt::class)->handle($receipt, $actor);
    }

    expect($checked)->toBeTrue();
    expect($receipt->fresh()->status->value)->toBe($void ? 'void' : 'posted');
})->with([false, true]);

it('holds a shared variant lock until a draft line is saved so a concurrent delete cannot win', function () {
    $primary = DB::getDefaultConnection();
    config(['database.connections.purchasing_peer' => config('database.connections.'.$primary)]);
    DB::connection('purchasing_peer')->statement("SET lock_timeout = '5s'");
    DB::setDefaultConnection('purchasing_peer');
    try {
        [$variant, $productId, $categoryId] = DB::transaction(fn (): array => Model::withoutEvents(function (): array {
            $variant = Variant::factory()->create();

            return [$variant, $variant->product_id, $variant->product->category_id];
        }));
    } finally {
        DB::setDefaultConnection($primary);
    }
    $this->beforeApplicationDestroyed(function () use ($variant, $productId, $categoryId): void {
        $peer = DB::connection('purchasing_peer');
        $peer->transaction(function () use ($peer, $variant, $productId, $categoryId): void {
            $peer->table('variants')->where('id', $variant->id)->delete();
            $peer->table('products')->where('id', $productId)->delete();
            $peer->table('categories')->where('id', $categoryId)->delete();
        });
        DB::purge('purchasing_peer');
    });
    $actor = User::factory()->stock()->create();
    $receipt = GoodsReceipt::factory()->create();
    $checked = false;
    DB::listen(function (QueryExecuted $query) use (&$checked, $primary, $variant): void {
        if ($checked || $query->connectionName !== $primary || ! str_contains($query->sql, 'from "variants"')) {
            return;
        }
        $checked = true;
        DB::setDefaultConnection('purchasing_peer');
        try {
            expect(fn () => DB::transaction(function () use ($variant): void {
                DB::statement("SET LOCAL lock_timeout = '250ms'");
                DB::table('variants')->where('id', $variant->id)->delete();
            }))->toThrow(QueryException::class, 'lock timeout');
        } finally {
            DB::setDefaultConnection($primary);
        }
    });

    $line = app(SaveGoodsReceiptLine::class)->handle($receipt, null, $variant->id, 2, 100, $actor);

    expect($checked)->toBeTrue();
    expect($line->variant_id)->toBe($variant->id);
    $this->assertModelExists($line);
    $this->assertModelExists($variant);
});
