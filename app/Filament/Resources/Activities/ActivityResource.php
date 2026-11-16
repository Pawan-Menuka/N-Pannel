<?php

namespace App\Filament\Resources\Activities;

use App\Filament\Resources\Activities\Pages\ListActivities;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;
use UnitEnum;

/** @extends resource<Activity> */
class ActivityResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $pluralModelLabel = 'Activity log';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('causer'))
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('created_at')->orderByDesc('id'))
            ->columns([
                TextColumn::make('created_at')->label('Date/time')->dateTime()->sortable(),
                TextColumn::make('causer_name')->label('Who')
                    ->state(fn (Activity $record): string => $record->causer?->getAttribute('name') ?? 'System'),
                TextColumn::make('event')->badge(),
                TextColumn::make('subject_type')->label('Subject')
                    ->formatStateUsing(fn (Activity $record): string => class_basename($record->subject_type ?? '').' #'.$record->subject_id),
                TextColumn::make('description')->wrap(),
            ])
            ->filters([
                SelectFilter::make('subject_type')->label('Subject type')
                    ->options(fn (): array => Activity::query()->whereNotNull('subject_type')
                        ->distinct()->pluck('subject_type')->mapWithKeys(fn (string $type): array => [$type => class_basename($type)])->all()),
                SelectFilter::make('causer_id')->label('Causer')
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, mixed $id): Builder => $query
                            ->where('causer_type', (new User)->getMorphClass())->where('causer_id', $id),
                    )),
                Filter::make('created_at')->label('Date range')
                    ->form([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until')->afterOrEqual('from'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make()->schema([
                    RepeatableEntry::make('changes')->label('Attribute changes')
                        ->state(fn (Activity $record): array => self::attributeChanges($record))
                        ->schema([
                            TextEntry::make('attribute'),
                            TextEntry::make('old')->label('Old'),
                            TextEntry::make('new')->label('New'),
                        ])->columns(3)->columnSpanFull(),
                ]),
            ]);
    }

    /** @return array<int, array{attribute: string, old: string, new: string}> */
    private static function attributeChanges(Activity $record): array
    {
        $changes = $record->attribute_changes?->all() ?? [];
        $old = $changes['old'] ?? [];
        $new = $changes['attributes'] ?? [];
        $rows = [];

        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $attribute) {
            $rows[] = [
                'attribute' => (string) $attribute,
                'old' => json_encode($old[$attribute] ?? null, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'new' => json_encode($new[$attribute] ?? null, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ];
        }

        return $rows;
    }

    public static function getPages(): array
    {
        return ['index' => ListActivities::route('/')];
    }
}
