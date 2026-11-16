<?php

use App\Filament\Auth\EditProfile;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Auth\MultiFactor\Pages\SetUpRequiredMultiFactorAuthentication;
use Filament\Facades\Filament;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function adminPanelLivewireSnapshot(TestResponse $response, string $class): string
{
    preg_match_all('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);

    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5);
        $decoded = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR);

        if ($decoded['memo']['name'] === $class) {
            return $snapshot;
        }
    }

    throw new RuntimeException('Panel component snapshot not found: '.$class);
}

it('enforces MFA on the real Livewire endpoint before a table action runs', function (bool $hasMfa) {
    $admin = User::factory()->admin()->withAppAuthentication()->create();
    $staff = User::factory()->withAppAuthentication()->create();
    $secret = $staff->getAppAuthenticationSecret();
    $this->actingAs($admin);
    $page = $this->get(UserResource::getUrl())->assertOk();
    $snapshot = adminPanelLivewireSnapshot($page, ListUsers::class);

    if (! $hasMfa) {
        $admin->saveAppAuthenticationSecret(null);
    }

    $response = $this->withHeader('X-Livewire', 'true')->postJson(app('livewire')->getUpdateUri(), [
        'components' => [[
            'snapshot' => $snapshot, 'updates' => [],
            'calls' => [
                ['path' => '', 'method' => 'mountAction', 'params' => ['resetTwoFactor', [], ['table' => true, 'recordKey' => (string) $staff->id]]],
                ['path' => '', 'method' => 'callMountedAction', 'params' => []],
            ],
        ]],
    ]);

    if ($hasMfa) {
        $response->assertOk();
        expect($staff->fresh()->getAppAuthenticationSecret())->toBeNull();
    } else {
        $response->assertRedirect(Filament::getSetUpRequiredMultiFactorAuthenticationUrl());
        expect($staff->fresh()->getAppAuthenticationSecret())->toBe($secret);
    }
})->with([true, false]);

it('enforces MFA on an already open profile during a Livewire update', function () {
    $user = User::factory()->withAppAuthentication()->create(['name' => 'Original profile']);
    $this->actingAs($user);
    $page = $this->get(Filament::getProfileUrl())->assertOk();
    $snapshot = adminPanelLivewireSnapshot($page, EditProfile::class);
    $user->saveAppAuthenticationSecret(null);

    $this->withHeader('X-Livewire', 'true')->postJson(app('livewire')->getUpdateUri(), [
        'components' => [[
            'snapshot' => $snapshot, 'updates' => ['data.name' => 'Unauthorized profile'],
            'calls' => [['path' => '', 'method' => 'save', 'params' => []]],
        ]],
    ])->assertRedirect(Filament::getSetUpRequiredMultiFactorAuthenticationUrl());

    expect($user->fresh()->name)->toBe('Original profile');
});

it('allows the setup page and its Livewire updates and logout without enrolled MFA', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $page = $this->get(Filament::getSetUpRequiredMultiFactorAuthenticationUrl())->assertOk();
    $snapshot = adminPanelLivewireSnapshot($page, SetUpRequiredMultiFactorAuthentication::class);

    $this->withHeader('X-Livewire', 'true')->postJson(app('livewire')->getUpdateUri(), [
        'components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => []]],
    ])->assertOk();

    $this->post(Filament::getLogoutUrl())->assertRedirect();
    $this->assertGuest();
});
