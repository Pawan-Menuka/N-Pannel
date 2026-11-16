<?php

use App\Enums\UserRole;
use App\Filament\Auth\EditProfile;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('does not let staff change their own email through the profile', function (UserRole $role) {
    $user = User::factory()->withAppAuthentication()->create(['role' => $role]);
    $email = $user->email;
    $this->actingAs($user);

    Livewire::test(EditProfile::class)->assertFormFieldIsDisabled('email')
        ->fillForm(['name' => 'Updated profile', 'email' => 'changed@example.test', 'currentPassword' => 'password'])
        ->call('save')->assertHasNoFormErrors();

    expect($user->fresh())->email->toBe($email)->name->toBe('Updated profile');
})->with([UserRole::Admin, UserRole::Sales, UserRole::Stock]);

it('does not expose an action to disable required app authentication', function () {
    $user = User::factory()->withAppAuthentication()->create();
    $this->actingAs($user);
    $provider = Filament::getMultiFactorAuthenticationProviders()['app'];

    expect(array_map(fn ($action): string => $action->getName(), $provider->getActions()))
        ->not->toContain('disableAppAuthentication');

    Livewire::test(EditProfile::class)->assertDontSee('Disable');
    expect($user->fresh()->getAppAuthenticationSecret())->not->toBeNull();
});
