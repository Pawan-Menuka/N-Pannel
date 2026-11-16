<?php

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Customer;
use App\Models\District;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Variant;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SampleCatalogSeeder;

it('seeds stable reference data without resetting the admin password', function () {
    $this->seed(DatabaseSeeder::class);
    $admin = User::where('role', UserRole::Admin)->sole();
    $passwordHash = $admin->password;
    $counts = [Location::count(), District::count(), Category::count(), Customer::count(), User::count()];

    $this->seed(DatabaseSeeder::class);

    expect([Location::count(), District::count(), Category::count(), Customer::count(), User::count()])
        ->toBe($counts)->toBe([1, 25, 5, 1, 1]);
    expect(Location::where('is_default', true)->count())->toBe(1);
    expect(Category::where('is_active', true)->count())->toBe(2);
    expect(Customer::walkIn()->count())->toBe(1);
    expect(User::where('role', UserRole::Admin)->sole()->password)->toBe($passwordHash);
});

it('seeds local sample catalog data without resetting existing stock', function () {
    $this->app->instance('env', 'local');
    $this->seed(DatabaseSeeder::class);
    $level = StockLevel::firstOrFail();

    expect(Product::count())->toBe(4);
    expect(Variant::count())->toBe(8);
    expect(StockLevel::where('qty', 0)->count())->toBe(8);
    expect(StockMovement::count())->toBe(0);

    $level->update(['qty' => 12]);
    $this->seed(DatabaseSeeder::class);

    expect(Product::count())->toBe(4);
    expect(Variant::count())->toBe(8);
    expect(StockLevel::count())->toBe(8);
    expect($level->fresh()->qty)->toBe(12);
    expect(StockMovement::count())->toBe(0);
});

it('does not seed sample catalog data outside local', function (string $environment) {
    $this->app->instance('env', $environment);

    $this->app->make(SampleCatalogSeeder::class)->run();

    expect(Product::count())->toBe(0);
    expect(Variant::count())->toBe(0);
    expect(StockLevel::count())->toBe(0);
})->with(['testing', 'staging', 'production']);

it('preserves staff edits when reference and admin seeders run again', function () {
    $this->seed(DatabaseSeeder::class);
    $location = Location::where('code', 'main')->sole();
    $category = Category::where('slug', 'ceilings')->sole();
    $walkIn = Customer::walkIn()->sole();
    $admin = User::where('email', config('seed.admin_email'))->sole();
    $passwordHash = $admin->password;
    $translations = ['en' => 'Edited ceilings', 'si' => 'Reviewed Sinhala name', 'ta' => 'Reviewed Tamil name'];

    $location->update(['name' => 'Edited main location', 'is_default' => false, 'is_active' => false]);
    $category->update(['name' => $translations, 'is_active' => false]);
    $walkIn->update(['name' => 'Edited walk-in customer']);
    $admin->forceFill(['name' => 'Edited admin', 'role' => UserRole::Sales, 'is_active' => false])->save();

    $this->seed(DatabaseSeeder::class);

    expect($location->fresh()->name)->toBe('Edited main location');
    expect($location->fresh()->is_default)->toBeFalse();
    expect($location->fresh()->is_active)->toBeFalse();
    expect($category->fresh()->getTranslations('name'))->toEqual($translations);
    expect($category->fresh()->is_active)->toBeFalse();
    expect($walkIn->fresh()->name)->toBe('Edited walk-in customer');
    expect($admin->fresh()->name)->toBe('Edited admin');
    expect($admin->fresh()->role)->toBe(UserRole::Sales);
    expect($admin->fresh()->is_active)->toBeFalse();
    expect($admin->fresh()->password)->toBe($passwordHash);
});
