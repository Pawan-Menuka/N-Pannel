<?php

namespace App\Filament\Resources\Stocktakes\RelationManagers;

use App\Actions\Stock\DeleteStocktakeLine;
use App\Actions\Stock\SaveStocktakeLine;
use App\Enums\StocktakeStatus;
use App\Models\Stocktake;
use App\Models\StocktakeLine;
use App\Models\User;
use App\Models\Variant;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Validation\ValidationException;

class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('variant_id')->label('Item')->required()->searchable()->disabled(fn (?StocktakeLine $record): bool => $record !== null)
                ->getSearchResultsUsing(fn (string $search): array => Variant::query()->with('product')->where('is_active', true)->where('track_stock', true)
                    ->where(fn (Builder $query): Builder => $query->where('sku', 'ilike', '%'.$search.'%')
                        ->orWhereHas('product', fn (Builder $query): Builder => $query->where('title->en', 'ilike', '%'.$search.'%')))
                    ->orderBy('sku')->limit(50)->get()->mapWithKeys(fn (Variant $variant): array => [$variant->id => $this->variantLabel($variant)])->all())
                ->getOptionLabelUsing(function (mixed $value): ?string {
                    $variant = Variant::query()->with('product')->find($value);

                    return $variant instanceof Variant ? $this->variantLabel($variant) : null;
                }),
            TextInput::make('counted_qty')->label('Counted qty')->integer()->required()->minValue(0)->maxValue(1000000),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
            'variant.product',
            'variant.stockLevels' => /** @param Relation<*, *, *> $query */ function (Relation $query): void {
                $query->where('location_id', $this->stocktake()->location_id);
            },
        ]))->recordTitleAttribute('id')->defaultSort('variant_id')->columns([
            TextColumn::make('variant.sku')->label('SKU'),
            TextColumn::make('product')->state(fn (StocktakeLine $record): string => $record->variant->product->getTranslation('title', 'en')),
            TextColumn::make('counted_qty')->label('Counted qty')->placeholder('Not counted'),
            TextColumn::make('expected_qty')->label(fn (): string => $this->isDraft() ? 'In stock now' : 'Expected qty')
                ->state(fn (StocktakeLine $record): ?int => $this->comparisonQty($record)),
            TextColumn::make('difference')->state(fn (StocktakeLine $record): ?int => $record->counted_qty === null || ($qty = $this->comparisonQty($record)) === null ? null : $record->counted_qty - $qty)
                ->formatStateUsing(fn (int $state): string => $state > 0 ? '+'.$state : (string) $state)
                ->color(fn (?int $state): string => $state === null || $state === 0 ? 'gray' : ($state > 0 ? 'success' : 'danger')),
        ])->filters([
            Filter::make('not_counted')->label('Not counted')->query(fn (Builder $query): Builder => $query->whereNull('counted_qty')),
        ])->headerActions([
            CreateAction::make()->authorize(fn (): bool => $this->canCreate())->using(fn (array $data): StocktakeLine => $this->saveLine($data)),
        ])->recordActions([
            EditAction::make()->authorize(fn (StocktakeLine $record): bool => $this->canEdit($record))
                ->using(fn (array $data, StocktakeLine $record): StocktakeLine => $this->saveLine($data, $record)),
            DeleteAction::make()->authorize(fn (StocktakeLine $record): bool => $this->canDelete($record))
                ->using(function (StocktakeLine $record): bool {
                    try {
                        return app(DeleteStocktakeLine::class)->handle($record, $this->actor());
                    } catch (ValidationException $exception) {
                        $this->notifyValidationErrors($exception);

                        return false;
                    }
                }),
        ]);
    }

    protected function canCreate(): bool
    {
        return ! $this->isReadOnly() && $this->canUpdateOwner();
    }

    protected function canEdit(Model $record): bool
    {
        return ! $this->isReadOnly() && $this->canUpdateOwner() && parent::canEdit($record);
    }

    protected function canDelete(Model $record): bool
    {
        return ! $this->isReadOnly() && $this->canUpdateOwner() && parent::canDelete($record);
    }

    /** @param array<string, mixed> $data */
    private function saveLine(array $data, ?StocktakeLine $record = null): StocktakeLine
    {
        $variant = $record->variant ?? Variant::query()->findOrFail($data['variant_id']);
        try {
            return app(SaveStocktakeLine::class)->handle($this->stocktake(), $variant, (int) $data['counted_qty'], $this->actor());
        } catch (ValidationException $exception) {
            $this->notifyValidationErrors($exception);
            $errors = [];
            foreach ($exception->errors() as $key => $messages) {
                $errors[($this->getMountedActionSchema()?->getStatePath() ?? 'mountedActions.0.data').'.'.$key] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
    }

    private function notifyValidationErrors(ValidationException $exception): void
    {
        $messages = [];
        foreach ($exception->errors() as $errors) {
            foreach ($errors as $message) {
                $messages[] = $message;
            }
        }
        Notification::make()->danger()->title($exception->validator->errors()->first())
            ->body(implode("\n", array_map(fn (string $message): string => e($message), array_slice($messages, 0, 10))))->send();
    }

    private function comparisonQty(StocktakeLine $line): ?int
    {
        if (! $this->isDraft()) {
            return $line->expected_qty;
        }

        $level = $line->variant->stockLevels->first();

        return $level === null ? 0 : $level->qty;
    }

    private function isDraft(): bool
    {
        return $this->stocktake()->status === StocktakeStatus::Draft;
    }

    private function canUpdateOwner(): bool
    {
        return Filament::auth()->user()?->can('update', $this->stocktake()->fresh()) ?? false;
    }

    private function stocktake(): Stocktake
    {
        /** @var Stocktake $stocktake */
        $stocktake = $this->getOwnerRecord();

        return $stocktake;
    }

    private function variantLabel(Variant $variant): string
    {
        return $variant->sku.' — '.$variant->product->getTranslation('title', 'en').' — '.($variant->label ?? '');
    }

    private function actor(): User
    {
        /** @var User $actor */
        $actor = Filament::auth()->user();

        return $actor;
    }
}
