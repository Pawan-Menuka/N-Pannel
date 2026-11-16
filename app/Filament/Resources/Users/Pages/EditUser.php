<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\Staff\UpdateStaffMember;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/** @extends EditRecord<User> */
class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $actor */
        $actor = Filament::auth()->user();
        /** @var User $record */

        return app(UpdateStaffMember::class)->handle($actor, $record, $data);
    }
}
