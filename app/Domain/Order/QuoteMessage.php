<?php

namespace App\Domain\Order;

final class QuoteMessage
{
    public static function whatsApp(string $customerName, string $businessName, string $reference, int $revision, string $totalFormatted, string $validUntilFormatted, string $link): string
    {
        return 'Hello '.$customerName.', here is your quote '.$reference.' (revision '.$revision.') from '.$businessName.': total '.$totalFormatted.', valid until '.$validUntilFormatted.'. View or print it here: '.$link;
    }

    public static function whatsAppUrl(?string $phoneE164, string $text): ?string
    {
        if ($phoneE164 === null || $phoneE164 === '') {
            return null;
        }

        return 'https://wa.me/'.ltrim($phoneE164, '+').'?text='.rawurlencode($text);
    }
}
