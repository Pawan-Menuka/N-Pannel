<?php

namespace App\Filament\Resources\Users;

use App\Actions\Staff\ResetStaffAppAuthentication;
use App\Actions\Staff\SendStaffPasswordResetLink;
use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Password;
use UnitEnum;

/** @extends resource<User> */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('email')->email()->required()->maxLength(255)->unique(ignoreRecord: true),
            Select::make('role')->options(UserRole::class)->required()->enum(UserRole::class)
                ->disabled(fn (?User $record): bool => $record?->getKey() === Filament::auth()->id()),
            Toggle::make('is_active')->label('Active')->default(true)
                ->disabled(fn (?User $record): bool => $record?->getKey() === Filament::auth()->id()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->sortable(),
                TextColumn::make('role')->badge()->sortable(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                IconColumn::make('app_authentication_enabled')->label('MFA set up')->boolean()
                    ->state(fn (User $record): bool => filled($record->getAppAuthenticationSecret())),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('role')->options(UserRole::class),
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                EditAction::make()->url(fn (User $record): string => static::getUrl('edit', ['record' => $record])),
                Action::make('sendPasswordResetLink')->label('Send password reset link')
                    ->authorize('update')
                    ->visible(fn (User $record): bool => $record->is_active)
                    ->action(function (User $record, SendStaffPasswordResetLink $sendPasswordResetLink): void {
                        /** @var User $actor */
                        $actor = Filament::auth()->user();
                        $status = $sendPasswordResetLink->handle($actor, $record);
                        $notification = Notification::make()->title(__($status));

                        if ($status === Password::RESET_LINK_SENT) {
                            $notification->success();
                        } else {
                            $notification->danger();
                        }

                        $notification->send();
                    }),
                Action::make('resetTwoFactor')->label('Reset two-factor')
                    ->authorize('update')->requiresConfirmation()
                    ->modalDescription('This staff member must set up their authenticator app again on their next sign-in.')
                    ->action(function (User $record, ResetStaffAppAuthentication $resetAppAuthentication): void {
                        /** @var User $actor */
                        $actor = Filament::auth()->user();
                        $resetAppAuthentication->handle($actor, $record);
                        Notification::make()->title('Two-factor authentication reset')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
