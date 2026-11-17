<?php

namespace App\Filament\Pages\Reports;

use App\Actions\Reports\ExportReportCsv;
use App\Domain\Money\Lkr;
use App\Domain\Reports\ReportResult;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/** @property Schema $form */
abstract class ReportPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected string $view = 'filament.pages.reports.report';

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array{from: string, to: string} */
    #[Locked]
    public array $appliedRange = ['from' => '', 'to' => ''];

    protected ?ReportResult $cachedReport = null;

    public static function canAccess(): bool
    {
        return Filament::auth()->user()?->can('viewReports') ?? false;
    }

    public function boot(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $today = CarbonImmutable::now('Asia/Colombo');
        $this->appliedRange = ['from' => $today->startOfMonth()->toDateString(), 'to' => $today->endOfMonth()->toDateString()];
        $this->form->fill($this->appliedRange);
    }

    public function usesDateRange(): bool
    {
        return true;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components($this->usesDateRange() ? [
            DatePicker::make('from')->label('From')->required()->rules(['date_format:Y-m-d']),
            DatePicker::make('to')->label('To')->required()->rules(['date_format:Y-m-d'])->afterOrEqual('from'),
        ] : []);
    }

    public function applyFilters(): void
    {
        abort_unless(static::canAccess(), 403);
        if ($this->usesDateRange()) {
            $state = $this->form->getState();
            $from = CarbonImmutable::parse((string) $state['from'], 'Asia/Colombo')->startOfDay();
            $to = CarbonImmutable::parse((string) $state['to'], 'Asia/Colombo')->startOfDay();
            if ($from->greaterThan($to)) {
                throw ValidationException::withMessages(['data.to' => 'The end date must be on or after the start date.']);
            }
            if ($from->addDays(365)->lessThan($to)) {
                throw ValidationException::withMessages(['data.to' => 'Choose a range of no more than 366 days.']);
            }
            $this->appliedRange = ['from' => $from->toDateString(), 'to' => $to->toDateString()];
        }
        $this->cachedReport = null;
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        $report = $this->getReport();
        $columns = [];
        foreach ($report->columns as $key => $label) {
            $columns[] = TextColumn::make($key)->label($label)->sortable()
                ->formatStateUsing(fn (mixed $state): string => $this->formatValue($key, $state))
                ->placeholder(in_array($key, $report->moneyColumns, true) ? 'Unknown' : '-');
        }

        return $table->columns($columns)->paginated(false)
            ->records(function (?string $sortColumn, ?string $sortDirection): array {
                $report = $this->getReport();
                $rows = $report->rows;
                if ($sortColumn !== null && array_key_exists($sortColumn, $report->columns)) {
                    uasort($rows, fn (array $left, array $right): int => ($sortDirection === 'desc' ? -1 : 1)
                        * (($left[$sortColumn] ?? null) <=> ($right[$sortColumn] ?? null)));
                }

                return $rows;
            });
    }

    public function getReport(): ReportResult
    {
        abort_unless(static::canAccess(), 403);

        return $this->cachedReport ??= $this->buildReport();
    }

    /** @return array<string, string> */
    public function getTotalsSummary(): array
    {
        $report = $this->getReport();
        $summary = [];
        foreach ($report->totals as $key => $value) {
            $summary[$report->columns[$key] ?? str_replace('_', ' ', ucfirst($key))] = $this->formatValue($key, $value);
        }

        return $summary;
    }

    public function exportCsv(): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        return app(ExportReportCsv::class)->download($this->getReport(), $this->exportSlug(),
            $this->usesDateRange() ? $this->rangeFrom() : null, $this->usesDateRange() ? $this->rangeTo() : null);
    }

    public function reportNote(): ?string
    {
        return null;
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('exportCsv')->label('Export CSV')->action(fn (): StreamedResponse => $this->exportCsv())];
    }

    protected function rangeFrom(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->appliedRange['from'], 'Asia/Colombo')->startOfDay();
    }

    protected function rangeTo(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->appliedRange['to'], 'Asia/Colombo')->endOfDay();
    }

    protected function formatValue(string $key, mixed $value): string
    {
        if ($value === null) {
            return 'Unknown';
        }
        $report = $this->cachedReport ?? $this->getReport();
        if (in_array($key, $report->moneyColumns, true)) {
            return Lkr::formatWithCurrency((int) $value);
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        return (string) $value;
    }

    abstract protected function buildReport(): ReportResult;

    abstract protected function exportSlug(): string;
}
