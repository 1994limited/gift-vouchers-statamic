<?php

namespace NineteenNinetyFour\GiftVouchers\Events;

use Illuminate\Foundation\Events\Dispatchable;
use NineteenNinetyFour\GiftVouchers\Voucher;

class VoucherIssued
{
    use Dispatchable;

    public function __construct(public Voucher $voucher) {}
}
