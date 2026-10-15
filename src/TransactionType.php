<?php

namespace NineteenNinetyFour\GiftVouchers;

/**
 * Every change to a balance is one of these, kept in the voucher's history.
 */
enum TransactionType: string
{
    /** The voucher was made, from an order or in the Control Panel. */
    case Issued = 'issued';

    /** Spent on an order. */
    case Redeemed = 'redeemed';

    /** Put back because the order it was spent on failed or was cancelled. */
    case Restored = 'restored';

    /** Put back as a refund, from an order's "Refund to gift voucher". */
    case Refunded = 'refunded';

    /** Changed by hand in the Control Panel. */
    case Adjusted = 'adjusted';

    public function label(): string
    {
        return __('gift-vouchers::messages.transaction.'.$this->value);
    }
}
