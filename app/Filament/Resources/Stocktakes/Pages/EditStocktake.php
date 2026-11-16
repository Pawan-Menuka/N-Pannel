<?php

namespace App\Filament\Resources\Stocktakes\Pages;

use App\Actions\Stock\AddAllTrackedItems;
use App\Actions\Stock\CancelStocktake;
use App\Actions\Stock\ImportStocktakeCounts;
use App\Actions\Stock\PostStocktake;
use App\Actions\Stock\PreviewStocktake;
use App\Actions\Stock\UpdateStocktakeNote;
use App\Enums\StocktakeStatus;
use App\Filament\Resources\Stocktakes\StocktakeResource;
use App\Models\Stocktake;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Components\Attributes\Exposed;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** @extends EditRecord<Stocktake> */
class EditStocktake extends EditRecord
{
    protected static string $resource = StocktakeResource::class;

    #[Unexposed]
    public function hydrate(): void
    {
        Gate::forUser($this->actor())->authorize('create', Stocktake::class);
        Gate::forUser($this->actor())->authorize('view', $this->getRecord());
    }

    #[Exposed]
    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        $record = $this->getRecord()->fresh();
        abort_if($record === null, 404);
        Gate::forUser($this->actor())->authorize('create', Stocktake::class);
        Gate::forUser($this->actor())->authorize('view', $record);
        if ($record->status !== StocktakeStatus::Draft) {
            Notification::make()->danger()->title('This stocktake has been posted or cancelled and can no longer be edited.')->send();

            return;
        }

        parent::save($shouldRedirect, $shouldSendSavedNotification);
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(UpdateStocktakeNote::class)->handle($this->getRecord(), $data['note'] ?? null, $this->actor());
        } catch (ValidationException $exception) {
            $this->notifyValidationErrors($exception);
            $errors = [];
            foreach ($exception->errors() as $key => $messages) {
                $errors['data.'.$key] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addAllTrackedItems')->label('Add all tracked items')->authorize(fn (): bool => $this->canUpdate())
                ->action(function (): void {
                    try {
                        $count = app(AddAllTrackedItems::class)->handle($this->getRecord(), $this->actor());
                    } catch (ValidationException $exception) {
                        $this->notifyValidationErrors($exception);

                        return;
                    }
                    Notification::make()->success()->title($count.($count === 1 ? ' item added' : ' items added'))->send();
                    $this->redirect(StocktakeResource::getUrl('edit', ['record' => $this->getRecord()]));
                }),
            Action::make('importCounts')->label('Import counts')->authorize(fn (): bool => $this->canUpdate())
                ->modalSubmitActionLabel('Check and import')->schema([
                    FileUpload::make('file')->label('CSV file')->required()->disk('local')->directory('stocktake-imports')
                        ->visibility('private')->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                        ->rules(['extensions:csv,txt'])->maxSize(2048),
                ])->action(function (array $data, Action $action): void {
                    $actor = $this->actor();
                    $base = realpath(Storage::disk('local')->path('stocktake-imports'));
                    $file = $data['file'] ?? null;
                    $path = is_string($file) ? realpath(Storage::disk('local')->path($file)) : false;
                    $cleanupFile = $base !== false && $path !== false && str_starts_with($path, $base.DIRECTORY_SEPARATOR) && is_file($path) ? $file : null;
                    try {
                        if ($base === false || $path === false || ! str_starts_with($path, $base.DIRECTORY_SEPARATOR) || ! is_file($path)
                            || filesize($path) > 2097152 || ! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['csv', 'txt'], true)) {
                            throw ValidationException::withMessages([($this->getMountedActionSchema()?->getStatePath() ?? 'mountedActions.0.data').'.file' => 'Upload a CSV or text file no larger than 2 MB']);
                        }
                        $report = app(ImportStocktakeCounts::class)->handle($this->getRecord(), $path, $actor, false);
                        if ($report->isClean()) {
                            $report = app(ImportStocktakeCounts::class)->handle($this->getRecord(), $path, $actor, true);
                        }
                        if (! $report->isClean()) {
                            $errors = [];
                            foreach ($report->errors as $line => $messages) {
                                foreach ($messages as $message) {
                                    $errors[] = 'Line '.$line.': '.$message;
                                }
                            }
                            Notification::make()->danger()->title('Counts could not be imported')->body(implode("\n", array_map(fn (string $error): string => e($error), array_slice($errors, 0, 10))))->send();
                            $action->halt();

                            return;
                        }
                        Notification::make()->success()->title($report->rowsOk.($report->rowsOk === 1 ? ' count loaded' : ' counts loaded'))->send();
                        $this->redirect(StocktakeResource::getUrl('edit', ['record' => $this->getRecord()]));
                    } catch (ValidationException $exception) {
                        $this->notifyValidationErrors($exception);
                        $action->halt();
                    } finally {
                        if ($cleanupFile !== null) {
                            Storage::disk('local')->delete($cleanupFile);
                        }
                    }
                }),
            Action::make('post')->label('Post')->authorize(fn (): bool => $this->canUpdate())->requiresConfirmation()
                ->modalDescription(function (): string {
                    $preview = app(PreviewStocktake::class)->handle($this->getRecord());

                    return $preview['changes'].($preview['changes'] === 1 ? ' line will change stock, ' : ' lines will change stock, ').$preview['uncounted'].' not counted (skipped)';
                })
                ->action(function (): void {
                    try {
                        app(PostStocktake::class)->handle($this->getRecord(), $this->actor());
                    } catch (ValidationException $exception) {
                        $this->notifyValidationErrors($exception);

                        return;
                    }
                    Notification::make()->success()->title('Stocktake posted')->send();
                    $this->redirect(StocktakeResource::getUrl('view', ['record' => $this->getRecord()]));
                }),
            Action::make('cancelStocktake')->label('Cancel stocktake')->color('danger')->authorize(fn (): bool => $this->canUpdate())->requiresConfirmation()
                ->action(function (): void {
                    try {
                        app(CancelStocktake::class)->handle($this->getRecord(), $this->actor());
                    } catch (ValidationException $exception) {
                        $this->notifyValidationErrors($exception);

                        return;
                    }
                    Notification::make()->success()->title('Stocktake cancelled')->send();
                    $this->redirect(StocktakeResource::getUrl('view', ['record' => $this->getRecord()]));
                }),
        ];
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

    private function canUpdate(): bool
    {
        return Filament::auth()->user()?->can('create', Stocktake::class) ?? false;
    }

    private function actor(): User
    {
        /** @var User $actor */
        $actor = Filament::auth()->user();

        return $actor;
    }
}
