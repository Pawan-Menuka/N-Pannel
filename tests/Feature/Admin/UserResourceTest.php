<?php

use App\Actions\Staff\UpdateStaffMember;
use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\Notifications\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('lets an admin list staff and invite a new staff member using a panel reset link', function () {
    Notification::fake();
    $admin = User::factory()->admin()->withAppAuthentication()->create();
    $this->actingAs($admin);

    Livewire::test(ListUsers::class)->assertCanSeeTableRecords([$admin]);
    Livewire::test(CreateUser::class)
        ->fillForm(['name' => 'New Staff', 'email' => 'staff@example.test', 'role' => 'sales', 'is_active' => true])
        ->call('create')->assertHasNoFormErrors();

    $staff = User::where('email', 'staff@example.test')->sole();
    expect(Hash::isHashed($staff->getRawOriginal('password')))->toBeTrue();
    expect(Hash::check('password', $staff->password))->toBeFalse();
    Notification::assertSentTo($staff, ResetPassword::class, fn (ResetPassword $notification): bool => str_starts_with($notification->url, url('/admin/password-reset/reset'))
        && str_contains($notification->url, 'signature=')
    );
});

it('lets an admin edit staff', function () {
    $this->actingAs(User::factory()->admin()->withAppAuthentication()->create());
    $staff = User::factory()->sales()->create();

    Livewire::test(EditUser::class, ['record' => $staff->getRouteKey()])
        ->fillForm(['name' => 'Updated Staff', 'role' => 'stock', 'is_active' => false])
        ->call('save')->assertHasNoFormErrors();

    expect($staff->fresh())->name->toBe('Updated Staff')->role->toBe(UserRole::Stock)->is_active->toBeFalse();
});

it('refuses duplicate staff email addresses', function () {
    Notification::fake();
    $admin = User::factory()->admin()->withAppAuthentication()->create();
    $this->actingAs($admin);

    Livewire::test(CreateUser::class)
        ->fillForm(['name' => 'Duplicate', 'email' => $admin->email, 'role' => 'sales', 'is_active' => true])
        ->call('create')->assertHasFormErrors(['email' => 'unique']);

    expect(User::count())->toBe(1);
    Notification::assertNothingSent();
});

it('filters staff by role and active status', function () {
    $admin = User::factory()->admin()->withAppAuthentication()->create();
    $sales = User::factory()->sales()->create();
    $inactiveSales = User::factory()->sales()->inactive()->create();
    $stock = User::factory()->stock()->create();
    $this->actingAs($admin);

    Livewire::test(ListUsers::class)
        ->filterTable('role', 'sales')
        ->filterTable('is_active', true)
        ->assertCanSeeTableRecords([$sales])
        ->assertCanNotSeeTableRecords([$admin, $stock, $inactiveSales]);
});

it('forbids non-admin staff from the users index', function (UserRole $role) {
    $this->actingAs(User::factory()->withAppAuthentication()->create(['role' => $role]))
        ->get(UserResource::getUrl())->assertForbidden();
})->with([UserRole::Sales, UserRole::Stock]);

it('disables an admins own role and active fields and refuses direct self changes', function (array $change, string $field) {
    $admin = User::factory()->admin()->withAppAuthentication()->create();
    User::factory()->admin()->create();
    $this->actingAs($admin);

    Livewire::test(EditUser::class, ['record' => $admin->getRouteKey()])
        ->assertFormFieldIsDisabled($field)
        ->fillForm($change)->call('save')->assertHasNoFormErrors();

    expect(fn () => app(UpdateStaffMember::class)->handle($admin, $admin, $change))
        ->toThrow(ValidationException::class);

    expect($admin->fresh())->is_active->toBeTrue()->role->toBe(UserRole::Admin);
})->with([
    'deactivate' => [['is_active' => false], 'is_active'],
    'demote' => [['role' => 'sales'], 'role'],
]);

it('refuses removal of the last active admin when only inactive admins remain', function (array $change) {
    User::factory()->admin()->inactive()->create();
    $lastAdmin = User::factory()->admin()->create();

    expect(fn () => app(UpdateStaffMember::class)->handle($lastAdmin, $lastAdmin, $change))
        ->toThrow(ValidationException::class, 'At least one active admin must remain.');

    expect($lastAdmin->fresh())->is_active->toBeTrue()->role->toBe(UserRole::Admin);
})->with([[['is_active' => false]], [['role' => 'sales']]]);

it('allows removal of an active admin when another remains', function (array $change) {
    $actor = User::factory()->admin()->create();
    $staff = User::factory()->admin()->create();

    app(UpdateStaffMember::class)->handle($actor, $staff, $change);

    expect($staff->fresh()->only(array_keys($change)))->toBe(
        isset($change['role']) ? ['role' => UserRole::Sales] : ['is_active' => false]
    );
})->with([[['is_active' => false]], [['role' => 'sales']]]);

it('clears both app authentication columns when an admin resets two-factor', function () {
    $this->actingAs(User::factory()->admin()->withAppAuthentication()->create());
    $staff = User::factory()->withAppAuthentication()->create();

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('resetTwoFactor')->table($staff));

    expect($staff->fresh())->app_authentication_secret->toBeNull()->app_authentication_recovery_codes->toBeNull();
});

it('sends a panel password reset notification from the row action', function () {
    Notification::fake();
    $this->actingAs(User::factory()->admin()->withAppAuthentication()->create());
    $staff = User::factory()->create();

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('sendPasswordResetLink')->table($staff));

    Notification::assertSentTo($staff, ResetPassword::class);
});
