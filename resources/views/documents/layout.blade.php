<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="referrer" content="no-referrer">
    <title>@yield('title')</title>
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
    <div class="controls"><button type="button" onclick="window.print()">Print</button></div>
    @yield('content')
</main>
</body>
</html>
