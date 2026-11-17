@extends('documents.layout')

@section('title', 'CREDIT NOTE '.$document->number)

@section('content')
    <header>
        @include('documents.partials.seller', ['seller' => $document->seller])
        <h2>CREDIT NOTE</h2>
        <p>{{ $document->number }}</p>
        <p>Issued {{ $document->issuedDate }}</p>
        <p>Against invoice {{ $document->invoiceNumber }}</p>
        @if ($document->sellerVatNumber)<p>Seller VAT number: {{ $document->sellerVatNumber }}</p>@endif
    </header>
    <section class="customer">
        <h2>Credit to {{ $document->buyer['name'] }}</h2>
        @foreach (['company_name', 'address_line1', 'address_line2', 'city', 'phone_e164', 'email'] as $field)
            @if (filled($document->buyer[$field]))<p>{{ $document->buyer[$field] }}</p>@endif
        @endforeach
        @if (filled($document->buyer['vat_number']))<p>Buyer VAT number: {{ $document->buyer['vat_number'] }}</p>@endif
    </section>
    <table>
        <thead><tr><th>Description / SKU</th><th>Unit</th><th class="number">Qty</th><th class="number">Unit value</th><th class="number">Amount</th></tr></thead>
        <tbody>
        @foreach ($document->lines as $line)
            <tr>
                <td>{{ $line['description'] }} @if ($line['sku'])<br>{{ $line['sku'] }}@endif</td>
                <td>{{ $line['saleUnit'] }}</td><td class="number">{{ $line['qty'] }}</td>
                <td class="number">{{ $line['unitPrice'] }}</td><td class="number">{{ $line['lineTotal'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <table class="totals">
        <tr><th>Total credit</th><td class="number"><strong>{{ $document->total }}</strong></td></tr>
    </table>
    @if ($document->vatLine)<p>{{ $document->vatLine }}</p>@endif
    <p>Reason: {{ $document->reason }}</p>
    <footer class="multiline">{{ $document->footer }}</footer>
@endsection
