<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\Staff\CreateStaffMember;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/** @extends CreateRecord<User> */
class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $actor */
        $actor = Filament::auth()->user();

        return app(CreateStaffMember::class)->handle($actor, $data);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return $this->getRecord()?->getAttribute('is_active')
            ? 'Staff member created'
            : 'Staff member created (inactive; no invitation sent)';
    }
}
