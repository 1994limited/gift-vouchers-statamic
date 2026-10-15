<?php

namespace NineteenNinetyFour\GiftVouchers;

use RuntimeException;

/**
 * Why a voucher couldn't be used, made or changed. The message is safe to
 * show to customers; the reason is a short key for code to check.
 */
class VoucherException extends RuntimeException
{
    public function __construct(public readonly string $reason, array $replace = [], public readonly int $status = 422)
    {
        parent::__construct(__('gift-vouchers::messages.errors.'.$reason, $replace));
    }

    public static function because(string $reason, array $replace = []): self
    {
        return new self($reason, $replace);
    }
}
