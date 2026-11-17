<?php

use App\Domain\Invoice\InvoiceMessage;

it('builds an invoice message with an optional balance', function (?string $balance, string $suffix) {
    expect(InvoiceMessage::whatsApp('Nimal', 'N Panel', 'INV-2026-00001', 'Rs 118.00', 'https://example.test/i', $balance))
        ->toBe('Hello Nimal, here is your invoice INV-2026-00001 from N Panel: total Rs 118.00'.$suffix.'. View or print it here: https://example.test/i');
})->with([[null, ''], ['Rs 18.00', ', balance due Rs 18.00']]);

it('encodes WhatsApp text and omits links without a phone', function () {
    expect(InvoiceMessage::whatsAppUrl('+94771234567', 'Hello & thanks'))->toBe('https://wa.me/94771234567?text=Hello%20%26%20thanks');
    expect(InvoiceMessage::whatsAppUrl(null, 'Hello'))->toBeNull();
    expect(InvoiceMessage::whatsAppUrl('', 'Hello'))->toBeNull();
});
