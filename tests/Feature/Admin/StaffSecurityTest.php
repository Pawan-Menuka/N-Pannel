<?php

use App\Actions\Staff\ResetStaffAppAuthentication;
use App\Actions\Staff\SendStaffPasswordResetLink;
use App\Actions\Staff\UpdateStaffMember;
use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('revokes target sessions and remember-me tokens when resetting app authentication', function () {
    $admin = User::factory()->admin()->create();
    $staff = User::factory()->withAppAuthentication()->create();
    $rememberToken = $staff->remember_token;
    $connection = DB::connection(config('session.connection'));
    $table = config('session.table');

    foreach ([$staff, $staff, $admin] as $user) {
        $connection->table($table)->insert([
            'id' => Str::random(40), 'user_id' => $user->id,
            'payload' => base64_encode('{}'), 'last_activity' => time(),
        ]);
    }

    app(ResetStaffAppAuthentication::class)->handle($admin, $staff);

    expect($connection->table($table)->where('user_id', $staff->id)->count())->toBe(0);
    expect($connection->table($table)->where('user_id', $admin->id)->count())->toBe(1);
    expect($staff->fresh()->remember_token)->not->toBe($rememberToken)->not->toBeNull();
    expect($staff->fresh())->app_authentication_secret->toBeNull()->app_authentication_recovery_codes->toBeNull();
});

it('rolls back credential changes if deleting from the configured session table fails', function () {
    $admin = User::factory()->admin()->create();
    $staff = User::factory()->withAppAuthentication()->create();
    $secret = $staff->getAppAuthenticationSecret();
    $recoveryCodes = $staff->getAppAuthenticationRecoveryCodes();
    $rememberToken = $staff->remember_token;
    config(['session.table' => 'missing_staff_sessions']);

    expect(fn () => app(ResetStaffAppAuthentication::class)->handle($admin, $staff))
        ->toThrow(QueryException::class);

    expect($staff->fresh())->app_authentication_secret->toBe($secret)
        ->app_authentication_recovery_codes->toBe($recoveryCodes)->remember_token->toBe($rememberToken);
});

it('forbids sales and stock from creating or editing staff', function (UserRole $role) {
    $staff = User::factory()->create();
    $this->actingAs(User::factory()->withAppAuthentication()->create(['role' => $role]));

    $this->get(UserResource::getUrl('create'))->assertForbidden();
    $this->get(UserResource::getUrl('edit', ['record' => $staff]))->assertForbidden();
})->with([UserRole::Sales, UserRole::Stock]);

it('refuses non-admin calls directly to the staff update action', function (UserRole $role) {
    $actor = User::factory()->create(['role' => $role]);
    $staff = User::factory()->create(['name' => 'Original name']);

    expect(fn () => app(UpdateStaffMember::class)->handle($actor, $staff, ['name' => 'Unauthorized']))
        ->toThrow(AuthorizationException::class);

    expect($staff->fresh()->name)->toBe('Original name');
})->with([UserRole::Sales, UserRole::Stock]);

it('rechecks an actor whose role or active status changed before the lock', function (array $change) {
    $actor = User::factory()->admin()->create();
    $staleActor = $actor->fresh();
    $staff = User::factory()->create(['name' => 'Original name']);
    $actor->forceFill($change)->save();

    expect(fn () => app(UpdateStaffMember::class)->handle($staleActor, $staff, ['name' => 'Unauthorized']))
        ->toThrow(AuthorizationException::class);

    expect($staff->fresh()->name)->toBe('Original name');
})->with([[['role' => UserRole::Sales]], [['is_active' => false]]]);

it('refuses reset links for inactive staff and hides the row action', function () {
    Notification::fake();
    $admin = User::factory()->admin()->withAppAuthentication()->create();
    $staff = User::factory()->inactive()->create();
    $this->actingAs($admin);

    expect(fn () => app(SendStaffPasswordResetLink::class)->handle($admin, $staff))
        ->toThrow(ValidationException::class, 'Password reset links cannot be sent to inactive staff.');

    Livewire::test(ListUsers::class)
        ->assertActionHidden(TestAction::make('sendPasswordResetLink')->table($staff));
    Notification::assertNothingSent();
});

it('creates inactive staff without claiming or sending an invitation', function () {
    Notification::fake();
    $this->actingAs(User::factory()->admin()->withAppAuthentication()->create());

    Livewire::test(CreateUser::class)
        ->fillForm(['name' => 'Inactive staff', 'email' => 'inactive@example.test', 'role' => 'stock', 'is_active' => false])
        ->call('create')->assertHasNoFormErrors()
        ->assertNotified('Staff member created (inactive; no invitation sent)');

    expect(User::where('email', 'inactive@example.test')->sole())->is_active->toBeFalse()->role->toBe(UserRole::Stock);
    Notification::assertNothingSent();
});

it('keeps credentials out of all activity properties and changes when enrolled staff are edited', function () {
    $admin = User::factory()->admin()->create();
    $staff = User::factory()->withAppAuthentication()->create();

    app(UpdateStaffMember::class)->handle($admin, $staff, ['name' => 'Edited enrolled staff']);

    $entries = Activity::forSubject($staff)->get();
    expect($entries)->toHaveCount(2);

    foreach ($entries as $entry) {
        $logged = json_encode([$entry->properties, $entry->attribute_changes], JSON_THROW_ON_ERROR);

        foreach (['app_authentication_secret', 'app_authentication_recovery_codes', 'password', 'remember_token'] as $attribute) {
            expect($logged)->not->toContain('"'.$attribute.'"');
        }
    }
});

it('does not mass assign staff privileges or app authentication credentials', function () {
    $staff = User::factory()->sales()->create();
    $staff->fill([
        'name' => 'Allowed name', 'role' => UserRole::Admin, 'is_active' => false,
        'app_authentication_secret' => 'UNTRUSTEDSECRET',
        'app_authentication_recovery_codes' => ['untrusted-code'],
    ])->save();

    expect($staff->fresh())->name->toBe('Allowed name')->role->toBe(UserRole::Sales)
        ->is_active->toBeTrue()->app_authentication_secret->toBeNull()->app_authentication_recovery_codes->toBeNull();
});
