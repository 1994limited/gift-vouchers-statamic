<?php

namespace NineteenNinetyFour\GiftVouchers\Events;

use Illuminate\Foundation\Events\Dispatchable;
use NineteenNinetyFour\GiftVouchers\Transaction;
use NineteenNinetyFour\GiftVouchers\Voucher;

class VoucherCredited
{
    use Dispatchable;

    public function __construct(public Voucher $voucher, public Transaction $transaction) {}
}
