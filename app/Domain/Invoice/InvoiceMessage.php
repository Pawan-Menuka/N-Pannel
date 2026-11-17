<?php

namespace App\Domain\Invoice;

use App\Domain\Order\QuoteMessage;

final class InvoiceMessage
{
    public static function whatsApp(string $customerName, string $businessName, string $number, string $totalFormatted, string $link, ?string $balanceFormatted = null): string
    {
        return 'Hello '.$customerName.', here is your invoice '.$number.' from '.$businessName.': total '.$totalFormatted
            .($balanceFormatted === null ? '' : ', balance due '.$balanceFormatted).'. View or print it here: '.$link;
    }

    public static function whatsAppUrl(?string $phoneE164, string $text): ?string
    {
        return QuoteMessage::whatsAppUrl($phoneE164, $text);
    }
}
