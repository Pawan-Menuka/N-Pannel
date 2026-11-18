<?php

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Support\Facades\Hash;

it('always requires an explicit admin email outside development', function (string $environment, bool $existing) {
    $this->app->instance('env', $environment);
    config(['seed.admin_email' => null, 'seed.admin_password' => 'explicit-secret']);
    if ($existing) {
        User::factory()->admin()->create(['email' => 'owner@example.com']);
    }

    expect(fn () => $this->artisan('db:seed', ['--class' => AdminUserSeeder::class, '--force' => true])->assertSuccessful())
        ->toThrow(RuntimeException::class, 'SEED_ADMIN_EMAIL');
    expect(User::count())->toBe($existing ? 1 : 0);
})->with(['staging', 'production', 'preview'])->with(['first seed' => false, 'existing admin' => true]);

it('requires an explicit password to create a non-local admin', function (string $environment, ?string $password) {
    $this->app->instance('env', $environment);
    config(['seed.admin_email' => 'owner@example.com', 'seed.admin_password' => $password]);

    expect(fn () => $this->artisan('db:seed', ['--class' => AdminUserSeeder::class, '--force' => true])->assertSuccessful())
        ->toThrow(RuntimeException::class, 'SEED_ADMIN_PASSWORD');
    expect(User::count())->toBe(0);
})->with(['staging', 'production', 'preview'])->with([null, '', '   ']);

it('leaves an existing non-local admin unchanged after removing the seed password', function (string $environment) {
    $this->app->instance('env', $environment);
    config(['seed.admin_email' => 'owner@example.com', 'seed.admin_password' => null]);
    $user = User::factory()->admin()->inactive()->create(['email' => 'owner@example.com', 'name' => 'Existing owner']);
    $attributes = $user->refresh()->getAttributes();

    $this->artisan('db:seed', ['--class' => AdminUserSeeder::class, '--force' => true])
        ->doesntExpectOutputToContain('Generated admin password:')
        ->assertSuccessful();

    expect($user->refresh()->getAttributes())->toBe($attributes);
    expect(User::count())->toBe(1);
})->with(['staging', 'production', 'preview']);

it('seeds a non-local admin without printing credentials or resetting its password', function (string $environment) {
    $this->app->instance('env', $environment);
    config(['seed.admin_email' => 'owner@example.com', 'seed.admin_password' => 'explicit-secret']);

    $this->artisan('db:seed', ['--class' => AdminUserSeeder::class, '--force' => true])
        ->doesntExpectOutputToContain('explicit-secret')
        ->doesntExpectOutputToContain('Generated admin password:')
        ->assertSuccessful();
    $user = User::where('email', 'owner@example.com')->sole();
    expect(Hash::check('explicit-secret', $user->password))->toBeTrue();
    config(['seed.admin_password' => 'replacement-secret']);
    $this->artisan('db:seed', ['--class' => AdminUserSeeder::class, '--force' => true])
        ->doesntExpectOutputToContain('replacement-secret')
        ->doesntExpectOutputToContain('Generated admin password:')
        ->assertSuccessful();
    $user->refresh();
    expect(Hash::check('explicit-secret', $user->password))->toBeTrue();
})->with(['staging', 'production', 'preview']);

it('can generate a password for local and testing administrators', function (string $environment) {
    $this->app->instance('env', $environment);
    config(['seed.admin_email' => 'developer@example.com', 'seed.admin_password' => null]);

    $this->artisan('db:seed', ['--class' => AdminUserSeeder::class, '--force' => true])
        ->expectsOutputToContain('Generated admin password:')
        ->assertSuccessful();

    $user = User::where('email', 'developer@example.com')->sole();
    expect($user->password)->not->toBeEmpty();
    expect($user->role->value)->toBe('admin');
})->with(['local', 'testing']);
