<?php

use App\Domain\Order\QuoteMessage;

it('builds a short message containing the quote details', function () {
    expect(QuoteMessage::whatsApp('Nimal', 'N Panels', 'Q-234567', 2, 'Rs 1,000.00', '18 Nov 2026', 'https://example.test/q'))
        ->toBe('Hello Nimal, here is your quote Q-234567 (revision 2) from N Panels: total Rs 1,000.00, valid until 18 Nov 2026. View or print it here: https://example.test/q');
});

it('encodes the message and removes the E164 plus for WhatsApp', function () {
    expect(QuoteMessage::whatsAppUrl('+94771234567', 'Hello & quote: https://example.test/?a=1'))
        ->toBe('https://wa.me/94771234567?text=Hello%20%26%20quote%3A%20https%3A%2F%2Fexample.test%2F%3Fa%3D1');
    expect(QuoteMessage::whatsAppUrl(null, 'Hello'))->toBeNull();
    expect(QuoteMessage::whatsAppUrl('', 'Hello'))->toBeNull();
});
