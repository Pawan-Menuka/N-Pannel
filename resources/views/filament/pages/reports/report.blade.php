<x-filament-panels::page>
    @if ($this->usesDateRange())
        <form wire:submit="applyFilters">
            {{ $this->form }}
            <x-filament::actions>
                <x-filament::button type="submit" wire:loading.attr="disabled">Apply range</x-filament::button>
            </x-filament::actions>
        </form>
        <p>{{ $appliedRange['from'] }} to {{ $appliedRange['to'] }}</p>
    @endif

    @if ($this->reportNote() !== null)
        <p>{{ $this->reportNote() }}</p>
    @endif

    {{ $this->table }}

    <x-filament::section heading="Totals">
        <dl>
            @foreach ($this->getTotalsSummary() as $label => $value)
                <dt>{{ $label }}</dt>
                <dd>{{ $value }}</dd>
            @endforeach
        </dl>
    </x-filament::section>
</x-filament-panels::page>
