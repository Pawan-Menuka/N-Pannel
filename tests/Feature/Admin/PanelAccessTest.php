<?php

use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('redirects guests to the panel login', function () {
    $this->get('/admin')->assertRedirect(route('filament.admin.auth.login'));
});

it('allows active staff with app authentication into the panel', function () {
    $this->actingAs(User::factory()->admin()->withAppAuthentication()->create())
        ->get('/admin')->assertOk();
});

it('requires staff without app authentication to set it up', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin')
        ->assertRedirect(route('filament.admin.auth.multi-factor-authentication.set-up-required'));
});

it('forbids inactive staff from entering the panel', function () {
    $this->actingAs(User::factory()->admin()->inactive()->withAppAuthentication()->create())
        ->get('/admin')->assertForbidden();
});

it('refuses inactive staff at login before authentication', function () {
    $user = User::factory()->admin()->inactive()->withAppAuthentication()->create();

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    $this->assertGuest();
});

it('encrypts and hides app credentials while preserving the Filament contracts', function () {
    $user = User::factory()->admin()->create();
    $provider = AppAuthentication::make()->recoverable();
    $secret = $provider->generateSecret();
    $user->saveAppAuthenticationSecret($secret);
    $provider->saveRecoveryCodes($user, ['one-use-recovery-code']);
    $user->refresh();

    expect($user->getAppAuthenticationSecret())->toBe($secret);
    expect($user->getRawOriginal('app_authentication_secret'))->not->toBe($secret);
    expect($user->getAppAuthenticationHolderName())->toBe($user->email);
    expect(Hash::check('one-use-recovery-code', $user->getAppAuthenticationRecoveryCodes()[0]))->toBeTrue();
    expect($user->getRawOriginal('app_authentication_recovery_codes'))->not->toContain('one-use-recovery-code');
    expect($user->toArray())->not->toHaveKeys(['app_authentication_secret', 'app_authentication_recovery_codes']);
    expect(Activity::forSubject($user)->count())->toBe(1);

    $user->saveAppAuthenticationSecret(null);
    $user->saveAppAuthenticationRecoveryCodes(null);

    expect($user->fresh())->app_authentication_secret->toBeNull()->app_authentication_recovery_codes->toBeNull();
});
