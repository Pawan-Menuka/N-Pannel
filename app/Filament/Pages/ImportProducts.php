<?php

namespace App\Filament\Pages;

use App\Actions\Catalog\ExportProducts;
use App\Actions\Catalog\ImportProducts as ImportCatalog;
use App\Models\Product;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use UnitEnum;

/** @property Schema $form */
class ImportProducts extends Page
{
    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $title = 'Import products';

    protected string $view = 'filament.pages.import-products';

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $report = null;

    #[Locked]
    public ?string $checkedFingerprint = null;

    #[Locked]
    public ?string $checkedPath = null;

    public static function canAccess(): bool
    {
        return Filament::auth()->user()?->can('create', Product::class) ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            FileUpload::make('file')->label('CSV file')->required()->disk('local')->directory('catalog-imports')
                ->visibility('private')->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                ->rules(['extensions:csv,txt'])->maxSize(2048)
                ->afterStateUpdated(function (FileUpload $component): void {
                    $files = array_values($component->getRawState() ?? []);
                    if (count($files) === 1 && is_string($files[0]) && $this->checkedPath !== null
                        && realpath(Storage::disk('local')->path($files[0])) === $this->checkedPath) {
                        return;
                    }
                    $this->checkedFingerprint = null;
                    $this->checkedPath = null;
                    $this->report = null;
                }),
        ]);
    }

    public function checkFile(): void
    {
        $actor = $this->actor();
        $this->checkedFingerprint = null;
        $this->checkedPath = null;
        $path = $this->uploadedPath();
        $report = app(ImportCatalog::class)->run($path, false, $actor);
        $this->report = $report->toArray();
        if ($report->isClean()) {
            $this->checkedPath = $path;
            $this->checkedFingerprint = hash_file('sha256', $path) ?: null;
        }
    }

    public function import(): void
    {
        $actor = $this->actor();
        $path = $this->uploadedPath();
        if (($this->report['errors'] ?? null) !== [] || $path !== $this->checkedPath || $this->checkedFingerprint === null
            || ! hash_equals($this->checkedFingerprint, hash_file('sha256', $path) ?: '')) {
            throw ValidationException::withMessages(['data.file' => 'Check this file successfully before importing it']);
        }
        $report = app(ImportCatalog::class)->run($path, true, $actor);
        $this->report = $report->toArray();
        $this->checkedFingerprint = null;
        $this->checkedPath = null;
        if ($report->committed) {
            $base = realpath(Storage::disk('local')->path('catalog-imports'));
            if ($base !== false && str_starts_with($path, $base.DIRECTORY_SEPARATOR)) {
                Storage::disk('local')->delete('catalog-imports/'.str_replace('\\', '/', substr($path, strlen($base) + 1)));
            }
            $this->form->fill();
            $this->report = $report->toArray();
            Notification::make()->success()->title('Catalog imported')->send();
        }
    }

    public function downloadTemplate(): BinaryFileResponse
    {
        $this->actor();
        $path = $this->downloadPath();
        app(ExportProducts::class)->template($path);

        return response()->download($path, 'products-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8'])->deleteFileAfterSend();
    }

    public function exportCatalog(): BinaryFileResponse
    {
        $this->actor();
        $path = $this->downloadPath();
        app(ExportProducts::class)->run($path);

        return response()->download($path, 'catalog.csv', ['Content-Type' => 'text/csv; charset=UTF-8'])->deleteFileAfterSend();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadTemplate')->label('Download template')->action(fn (): BinaryFileResponse => $this->downloadTemplate()),
            Action::make('exportCatalog')->label('Export catalog')->action(fn (): BinaryFileResponse => $this->exportCatalog()),
        ];
    }

    private function actor(): User
    {
        /** @var User $actor */
        $actor = Filament::auth()->user();
        Gate::forUser($actor)->authorize('create', Product::class);

        return $actor;
    }

    private function uploadedPath(): string
    {
        $state = $this->form->getState();
        $file = $state['file'] ?? null;
        $base = realpath(Storage::disk('local')->path('catalog-imports'));
        $path = is_string($file) ? realpath(Storage::disk('local')->path($file)) : false;
        if ($base === false || $path === false || ! str_starts_with($path, $base.DIRECTORY_SEPARATOR) || ! is_file($path)
            || filesize($path) > 2097152 || ! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['csv', 'txt'], true)) {
            throw ValidationException::withMessages(['data.file' => 'Upload a CSV or text file no larger than 2 MB']);
        }

        return $path;
    }

    private function downloadPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'catalog-');
        if ($path === false) {
            throw new \RuntimeException('Cannot create download');
        }

        return $path;
    }
}
