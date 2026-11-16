<?php

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

it('logs only changed product attributes and skips unchanged saves', function () {
    $product = Product::factory()->create();
    $count = Activity::forSubject($product)->count();

    $product->save();

    expect(Activity::forSubject($product)->count())->toBe($count);

    $product->update(['brand' => 'Lanka Panels']);

    $changes = Activity::forSubject($product)->where('event', 'updated')->sole()->attribute_changes->all();
    expect($changes)->toEqual([
        'attributes' => ['brand' => 'Lanka Panels'],
        'old' => ['brand' => 'N-Pannel'],
    ]);
});

it('keeps user credentials out of activity logs and serialization', function () {
    $user = User::factory()->create([
        'app_authentication_secret' => 'private-secret',
        'app_authentication_recovery_codes' => ['private-recovery'],
    ]);
    $count = Activity::forSubject($user)->count();
    $excluded = ['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'];
    $changes = Activity::forSubject($user)->sole()->attribute_changes->get('attributes');

    foreach ($excluded as $attribute) {
        expect($changes)->not->toHaveKey($attribute);
        expect($user->toArray())->not->toHaveKey($attribute);
    }

    $user->forceFill([
        'password' => 'replacement-password',
        'app_authentication_secret' => 'replacement-secret',
        'app_authentication_recovery_codes' => ['replacement-recovery'],
    ])->save();

    expect(Activity::forSubject($user)->count())->toBe($count);
});

it('keeps document tokens hidden and out of activity logs', function (string $modelClass) {
    $model = $modelClass::factory()->create(['token_hash' => str_repeat('a', 64)]);
    $count = Activity::forSubject($model)->count();
    $changes = Activity::forSubject($model)->sole()->attribute_changes->get('attributes');

    expect($model->toArray())->not->toHaveKey('token_hash');
    expect($changes)->not->toHaveKey('token_hash');

    $model->update(['token_hash' => str_repeat('b', 64)]);

    expect(Activity::forSubject($model)->count())->toBe($count);
})->with([
    'order' => [Order::class],
    'invoice' => [Invoice::class],
]);

it('logs translation changes outside the current locale', function () {
    $product = Product::factory()->create();
    $product->setTranslation('title', 'si', 'Reviewed Sinhala title')->save();

    $changes = Activity::forSubject($product)->where('event', 'updated')->sole()->attribute_changes;
    expect($changes->get('attributes')['title']['si'])->toBe('Reviewed Sinhala title');
});
