<?php

namespace App\Actions\Orders;

use App\Enums\OrderEventType;
use App\Models\Order;
use App\Models\User;

/** The caller holds the order lock. Every note is retained in the event history. */
final class AppendAttentionNote
{
    public function __construct(private RecordOrderEvent $events) {}

    public function handle(Order $locked, string $note, ?User $by): void
    {
        $note = mb_substr($note, 0, 255);
        $existing = $locked->attention_note;
        $combined = $existing === null || $existing === '' ? $note : $existing.' '.$note;
        if (mb_strlen($combined) > 255) {
            $remaining = 255 - mb_strlen($note) - 3;
            $combined = $remaining > 0 ? '… '.mb_substr($existing ?? '', -$remaining).' '.$note : $note;
        }
        $locked->update([
            'needs_attention' => true,
            'attention_note' => $combined,
        ]);
        $this->events->handle($locked, OrderEventType::Attention, note: $note, by: $by);
    }
}
