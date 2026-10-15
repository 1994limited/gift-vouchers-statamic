<?php

namespace NineteenNinetyFour\GiftVouchers\Timeline;

use DuncanMcClean\Cargo\Orders\TimelineEventType;

/**
 * Gift voucher activity on an order's timeline: spent, made, put back,
 * refunded, disabled.
 */
class GiftVoucherEvent extends TimelineEventType
{
    protected static $handle = 'gift_voucher';

    public function message(): string
    {
        $event = $this->timelineEvent;
        $action = (string) $event->metadata('Action');

        return __('gift-vouchers::messages.timeline.'.$action, [
            'amount' => (string) $event->metadata('Amount'),
            'voucher' => (string) $event->metadata('Voucher'),
            'count' => (int) $event->metadata('Count'),
        ]);
    }
}
