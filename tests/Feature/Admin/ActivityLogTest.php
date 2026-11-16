<?php

use App\Enums\UserRole;
use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\Activities\Pages\ListActivities;
use App\Models\Product;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('shows product updates and their old and new values to an admin', function () {
    $admin = User::factory()->admin()->withAppAuthentication()->create();
    $product = Product::factory()->create(['slug' => 'old-product']);
    $this->actingAs($admin);
    $product->update(['slug' => 'new-product']);
    $entry = Activity::forSubject($product)->where('event', 'updated')->sole();

    expect($entry->attribute_changes->all())->toEqual([
        'attributes' => ['slug' => 'new-product'],
        'old' => ['slug' => 'old-product'],
    ]);

    $this->get(ActivityResource::getUrl())->assertOk();
    Livewire::test(ListActivities::class)
        ->assertCanSeeTableRecords([$entry])
        ->assertTableColumnStateSet('causer_name', $admin->name, $entry)
        ->assertSee($admin->name)
        ->mountAction(TestAction::make('view')->table($entry))
        ->assertActionMounted(TestAction::make('view')->table($entry))
        ->assertSchemaComponentVisible('changes')
        ->assertSchemaComponentStateSet('changes', [
            ['attribute' => 'slug', 'old' => '"old-product"', 'new' => '"new-product"'],
        ])
        ->assertSchemaComponentStateSet('changes.0.attribute', 'slug')
        ->assertSchemaComponentVisible('changes.0.old')
        ->assertSchemaComponentStateSet('changes.0.old', '"old-product"')
        ->assertSchemaComponentVisible('changes.0.new')
        ->assertSchemaComponentStateSet('changes.0.new', '"new-product"');
});

it('forbids other roles from the audit log', function (UserRole $role) {
    $this->actingAs(User::factory()->withAppAuthentication()->create(['role' => $role]))
        ->get(ActivityResource::getUrl())->assertForbidden();
})->with([UserRole::Sales, UserRole::Stock]);

it('filters audit entries by subject type, causer and inclusive date range', function () {
    $admin = User::factory()->admin()->withAppAuthentication()->create();
    $other = User::factory()->sales()->create();
    $product = Product::factory()->create();
    $this->actingAs($admin);

    $matching = activity()->causedBy($admin)->performedOn($product)
        ->createdAt(now()->startOfDay())->log('Matching entry');
    $wrongDate = activity()->causedBy($admin)->performedOn($product)
        ->createdAt(now()->subDay())->log('Outside range');
    $wrongCauser = activity()->causedBy($other)->performedOn($product)->log('Other staff');
    $wrongSubject = activity()->causedBy($admin)->performedOn($other)->log('Staff subject');

    Livewire::test(ListActivities::class)
        ->filterTable('subject_type', Product::class)
        ->filterTable('causer_id', $admin->id)
        ->filterTable('created_at', ['from' => now()->toDateString(), 'until' => now()->toDateString()])
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$wrongDate, $wrongCauser, $wrongSubject]);
});
