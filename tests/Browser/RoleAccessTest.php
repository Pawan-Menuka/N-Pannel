<?php

use App\Filament\Pages\Reports\SalesByDay;
use App\Models\User;
use Tests\Support\BrowserJourney;

it('hides sales and reports navigation from a stock user after real MFA sign in', function () {
    $user = User::factory()->stock()->withAppAuthentication()->create();

    $page = BrowserJourney::signIn($user);

    $page->assertDontSeeIn('[aria-label="Sidebar navigation"]', 'Sales')->assertDontSeeIn('[aria-label="Sidebar navigation"]', 'Reports')
        ->assertSeeIn('[aria-label="Sidebar navigation"]', 'Inventory');
    $this->assertDatabaseHas('users', ['id' => $user->id, 'role' => 'stock']);
});

it('forbids a sales user from opening reports after real MFA sign in', function () {
    $user = User::factory()->sales()->withAppAuthentication()->create();

    $page = BrowserJourney::signIn($user);
    $page->navigate(SalesByDay::getUrl());

    $page->assertSee('403')->assertSee('Forbidden');
    $this->assertDatabaseHas('users', ['id' => $user->id, 'role' => 'sales']);
});
