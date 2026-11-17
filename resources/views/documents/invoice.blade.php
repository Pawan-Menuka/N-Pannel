@extends('documents.layout')

@section('title', $document->title.' '.$document->number)

@section('content')
    <header>
        @include('documents.partials.seller', ['seller' => $document->seller])
        <h2>{{ $document->title }}</h2>
        <p>{{ $document->number }}</p>
        <p>Issued {{ $document->issuedDate }}</p>
        @if ($document->dueDate)<p>Due {{ $document->dueDate }}</p>@endif
        @if ($document->sellerVatNumber)<p>Seller VAT number: {{ $document->sellerVatNumber }}</p>@endif
    </header>
    @if ($document->voidNotice)<p class="notice"><strong>{{ $document->voidNotice }}</strong></p>@endif
    <section class="customer">
        <h2>Bill to {{ $document->buyer['name'] }}</h2>
        @foreach (['company_name', 'address_line1', 'address_line2', 'city', 'phone_e164', 'email'] as $field)
            @if (filled($document->buyer[$field]))<p>{{ $document->buyer[$field] }}</p>@endif
        @endforeach
        @if (filled($document->buyer['vat_number']))<p>Buyer VAT number: {{ $document->buyer['vat_number'] }}</p>@endif
    </section>
    <table>
        <thead><tr><th>Description / SKU</th><th>Unit</th><th class="number">Qty</th><th class="number">Unit price</th><th class="number">Amount</th></tr></thead>
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
        <tr><th>Subtotal</th><td class="number">{{ $document->subtotal }}</td></tr>
        <tr><th>Delivery</th><td class="number">{{ $document->delivery }}</td></tr>
        <tr><th>Discount</th><td class="number">{{ $document->discount }}</td></tr>
        <tr><th>Total</th><td class="number"><strong>{{ $document->total }}</strong></td></tr>
        @if ($document->returned)<tr><th>Returned</th><td class="number">{{ $document->returned }}</td></tr>@endif
    </table>
    @if ($document->vatLine)<p>{{ $document->vatLine }}</p>@endif
    @if ($document->creditNotes !== [])
        <section>
            <h2>Credit notes</h2>
            <table>
                <thead><tr><th>Number</th><th>Date</th><th class="number">Amount</th></tr></thead>
                <tbody>
                @foreach ($document->creditNotes as $credit)
                    <tr><td>{{ $credit['number'] }}</td><td>{{ $credit['date'] }}</td><td class="number">{{ $credit['amount'] }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </section>
    @endif
    <section>
        <h2>Payments</h2>
        <table>
            <thead><tr><th>Receipt</th><th>Date</th><th>Method</th><th class="number">Amount</th></tr></thead>
            <tbody>
            @foreach ($document->payments as $payment)
                <tr><td>{{ $payment['receiptNumber'] ?? '—' }}</td><td>{{ $payment['date'] }}</td><td>{{ $payment['method'] }}</td><td class="number">{{ $payment['amount'] }}</td></tr>
            @endforeach
            </tbody>
        </table>
        <p>Amount paid: {{ $document->paid }}</p>
        <p><strong>Balance due: {{ $document->balance }}</strong></p>
    </section>
    @if ($document->bank)
        <section>
            <h2>Bank details</h2>
            @foreach ($document->bank as $part)
                @if (filled($part))<p>{{ $part }}</p>@endif
            @endforeach
        </section>
    @endif
    <footer class="multiline">{{ $document->footer }}</footer>
@endsection
