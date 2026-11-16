<?php

namespace App\Filament\Support;

use App\Actions\Stock\AdjustStock;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\Variant;
use Filament\Actions\Action;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Validation\ValidationException;

final class AdjustStockAction
{
    public static function make(): Action
    {
        return Action::make('adjust')->label('Adjust stock')
            ->visible(fn (): bool => Filament::auth()->user()?->can('adjust', StockLevel::class) ?? false)
            ->schema([
                TextInput::make('delta')->integer()->required()->minValue(-1000000)->maxValue(1000000)
                    ->rule('not_in:0')->helperText('Use a minus sign to remove stock'),
                TextInput::make('note')->label('Reason')->required()->maxLength(255),
            ])->action(function (Variant $record, array $data, HasActions&HasSchemas $livewire): void {
                /** @var User $actor */
                $actor = Filament::auth()->user();
                try {
                    $movement = app(AdjustStock::class)->handle($record, (int) $data['delta'], (string) $data['note'], $actor);
                } catch (ValidationException $exception) {
                    $schemaName = $livewire->getMountedActionSchemaName();
                    $schema = $schemaName === null ? null : $livewire->getSchema($schemaName);
                    if ($schema === null) {
                        throw $exception;
                    }
                    $errors = [];
                    foreach ($exception->errors() as $field => $messages) {
                        $errors[$schema->getStatePath().'.'.$field] = $messages;
                    }
                    throw ValidationException::withMessages($errors);
                }
                $record->setAttribute('qty', $movement->qty_after);
                Notification::make()->success()->title('Stock adjusted to '.$movement->qty_after)->send();
            });
    }
}
