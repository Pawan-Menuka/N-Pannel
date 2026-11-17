<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="referrer" content="no-referrer">
    <title>Quote {{ $document->reference }} — revision {{ $document->revision }}</title>
    <style>
        @page { size: A4; margin: 15mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #000; background: #fff; font: 14px/1.45 Arial, sans-serif; }
        main { max-width: 900px; margin: 24px auto; padding: 20px; }
        h1, h2, p { margin: 0 0 10px; }
        h1 { font-size: 24px; }
        h2 { font-size: 18px; }
        header, .customer { margin-bottom: 24px; }
        .multiline { white-space: pre-line; overflow-wrap: anywhere; }
        table { width: 100%; border-collapse: collapse; margin: 18px 0; }
        th, td { border-bottom: 1px solid #aaa; padding: 8px 5px; text-align: left; vertical-align: top; }
        .number { text-align: right; white-space: nowrap; }
        .totals { margin-left: auto; width: auto; min-width: 260px; }
        .notice { border: 1px solid #000; padding: 10px; }
        footer { border-top: 1px solid #aaa; margin-top: 24px; padding-top: 12px; }
        .controls { display: flex; gap: 20px; align-items: center; margin-bottom: 20px; }
        @media print {
            body { font-size: 10pt; }
            main { max-width: none; margin: 0; padding: 0; }
            .controls { display: none; }
            thead { display: table-header-group; }
            tr, .totals, footer { break-inside: avoid; }
        }
    </style>
</head>
<body>
<main>
    <div class="controls">
        <button type="button" onclick="window.print()">Print</button>
        @if ($document->whatsAppUrl)
            <a href="{{ $document->whatsAppUrl }}" target="_blank" rel="noopener noreferrer">Reply on WhatsApp to accept</a>
        @endif
    </div>
    <header>
        <h1>{{ $document->businessName }}</h1>
        @if (filled($document->businessAddress))
            <p class="multiline">{{ $document->businessAddress }}</p>
        @endif
        @php
            $businessContacts = array_filter([$document->businessPhone, $document->businessEmail], fn (string $part): bool => filled($part));
        @endphp
        @if ($businessContacts !== [])
            <p>{{ implode(' · ', $businessContacts) }}</p>
        @endif
        <h2>Quote {{ $document->reference }}</h2>
        <p>Revision {{ $document->revision }} · Sent {{ $document->sentDate }} (Colombo)</p>
        @if ($document->validUntil)<p>Valid until {{ $document->validUntil }}</p>@endif
    </header>
    @if ($document->statusNotice)<p class="notice">{{ $document->statusNotice }}</p>@endif
    <section class="customer">
        <h2>Prepared for {{ $document->customerName }}</h2>
        @if ($document->customerPhone)<p>{{ $document->customerPhone }}</p>@endif
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
        @if ($document->requiredAdvance !== null)<tr><th>Required advance</th><td class="number">{{ $document->requiredAdvance }}</td></tr>@endif
    </table>
    @if ($document->exclusions)<section><h2>Exclusions</h2><p class="multiline">{{ $document->exclusions }}</p></section>@endif
    <footer class="multiline">{{ $document->footer }}</footer>
</main>
</body>
</html>
