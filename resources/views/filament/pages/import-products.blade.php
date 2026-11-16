<x-filament-panels::page>
    {{ $this->form }}

    <x-filament::actions>
        <x-filament::button wire:click="checkFile" wire:loading.attr="disabled">Check file</x-filament::button>
        <x-filament::button wire:click="import" wire:loading.attr="disabled" :disabled="$checkedFingerprint === null">Import</x-filament::button>
    </x-filament::actions>

    @if ($report !== null)
        <x-filament::section heading="Import report">
            <p>{{ $report['committed'] ? 'Imported' : 'Validation report' }} — {{ $report['rowsRead'] }} rows read</p>
            <p>Products: {{ $report['productsToCreate'] }} create, {{ $report['productsToUpdate'] }} update, {{ $report['productsUnchanged'] }} unchanged</p>
            <p>Variants: {{ $report['variantsToCreate'] }} create, {{ $report['variantsToUpdate'] }} update, {{ $report['variantsUnchanged'] }} unchanged</p>
        </x-filament::section>

        <x-filament::section heading="Errors">
            @if ($report['errors'] === [])
                <p>No errors.</p>
            @else
                <table class="fi-ta-table">
                    <thead><tr><th>Row</th><th>Column</th><th>Message</th></tr></thead>
                    <tbody>
                        @foreach ($report['errors'] as $error)
                            <tr><td>{{ $error['row'] }}</td><td>{{ $error['column'] }}</td><td>{{ $error['message'] }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>

        <x-filament::section heading="Warnings">
            @forelse ($report['warnings'] as $warning)
                <p>Row {{ $warning['row'] }}: {{ $warning['message'] }}</p>
            @empty
                <p>No warnings.</p>
            @endforelse
        </x-filament::section>
    @endif
</x-filament-panels::page>
