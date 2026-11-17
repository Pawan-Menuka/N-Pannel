@extends('documents.layout')

@section('title', 'RECEIPT '.$document->number)

@section('content')
    <header>
        @include('documents.partials.seller', ['seller' => $document->seller])
        <h2>RECEIPT</h2>
        <p>{{ $document->number }}</p>
        <p>Date: {{ $document->date }}</p>
    </header>
    <section class="customer">
        <h2>Received from {{ $document->customerName }}</h2>
        <p>Order: {{ $document->orderReference }}</p>
        @if ($document->invoiceNumber)<p>Invoice: {{ $document->invoiceNumber }}</p>@endif
        <p>Method: {{ $document->method }}</p>
        @if (filled($document->reference))<p>Reference: {{ $document->reference }}</p>@endif
        <p><strong>Amount received: {{ $document->amount }}</strong></p>
    </section>
    <table class="totals">
        <tr><th>Order total</th><td class="number">{{ $document->total }}</td></tr>
        <tr><th>Amount paid</th><td class="number">{{ $document->paid }}</td></tr>
        <tr><th>Balance due</th><td class="number">{{ $document->balance }}</td></tr>
    </table>
    <footer class="multiline">{{ $document->footer }}</footer>
@endsection
