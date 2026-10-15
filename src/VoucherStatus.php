<?php

namespace NineteenNinetyFour\GiftVouchers;

enum VoucherStatus: string
{
    case Active = 'active';
    case Spent = 'spent';
    case Expired = 'expired';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('gift-vouchers::messages.status.active'),
            self::Spent => __('gift-vouchers::messages.status.spent'),
            self::Expired => __('gift-vouchers::messages.status.expired'),
            self::Disabled => __('gift-vouchers::messages.status.disabled'),
        };
    }
}
