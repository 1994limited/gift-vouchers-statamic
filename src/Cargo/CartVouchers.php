<?php

namespace NineteenNinetyFour\GiftVouchers\Cargo;

use DuncanMcClean\Cargo\Contracts\Cart\Cart;
use DuncanMcClean\Cargo\Orders\LineItem;
use NineteenNinetyFour\GiftVouchers\Money;
use NineteenNinetyFour\GiftVouchers\Voucher;
use NineteenNinetyFour\GiftVouchers\VoucherException;
use NineteenNinetyFour\GiftVouchers\VoucherStatus;

/**
 * Vouchers used on a cart.
 *
 * The cart keeps the IDs of the vouchers the customer entered. Each time
 * Cargo works out the cart (CartRecalculated, after tax and shipping) the
 * vouchers are taken off the grand total, like a payment, in the order
 * they were entered. Nothing is spent until the order is placed.
 *
 * Cart (and so order) data:
 * - gift_vouchers: the IDs of the vouchers entered
 * - gift_voucher_breakdown: [{id, code, amount}], what each covers
 * - gift_voucher_total: the total covered by vouchers
 */
class CartVouchers
{
    public const APPLIED = 'gift_vouchers';

    public const BREAKDOWN = 'gift_voucher_breakdown';

    public const TOTAL = 'gift_voucher_total';

    public function __construct(protected Products $products) {}

    /**
     * Use a voucher on a cart, by its code.
     *
     * @throws VoucherException
     */
    public function add(Cart $cart, string $code): Voucher
    {
        $voucher = Voucher::findByCode($code) ?? throw VoucherException::because('not_found');

        $this->ensureUsable($voucher, $cart);

        $applied = $this->applied($cart);

        if (in_array($voucher->id, $applied, true)) {
            throw VoucherException::because('already_applied');
        }

        if (count($applied) >= max(1, (int) config('gift-vouchers.max_per_cart', 5))) {
            throw VoucherException::because('too_many', ['max' => (int) config('gift-vouchers.max_per_cart', 5)]);
        }

        // Work the cart out with the voucher, and only keep it if it covers
        // something: there may be nothing left to pay, or only vouchers.
        $cart->set(self::APPLIED, [...$applied, $voucher->id]);
        $cart->recalculate();

        if ($this->amountFor($cart, $voucher->id) <= 0) {
            $cart->set(self::APPLIED, $applied);
            $cart->recalculate();

            throw VoucherException::because('nothing_to_pay');
        }

        $cart->saveWithoutRecalculating();

        return $voucher;
    }

    /**
     * Stop using a voucher on a cart.
     */
    public function remove(Cart $cart, string $voucherId): bool
    {
        $applied = $this->applied($cart);

        if (! in_array($voucherId, $applied, true)) {
            return false;
        }

        $cart->set(self::APPLIED, array_values(array_diff($applied, [$voucherId])));
        $cart->recalculate();
        $cart->saveWithoutRecalculating();

        return true;
    }

    /**
     * The IDs of the vouchers entered on a cart.
     *
     * @return array<int, string>
     */
    public function applied(Cart $cart): array
    {
        return collect((array) $cart->get(self::APPLIED, []))
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * What a voucher covers on a cart as it was last worked out.
     */
    public function amountFor(Cart $cart, string $voucherId): int
    {
        $line = collect((array) $cart->get(self::BREAKDOWN, []))->firstWhere('id', $voucherId);

        return is_array($line) ? (int) ($line['amount'] ?? 0) : 0;
    }

    /**
     * Take the vouchers off a cart's grand total. Runs whenever Cargo
     * works out a cart (the CartRecalculated event).
     */
    public function calculate(Cart $cart): void
    {
        $applied = $this->applied($cart);

        if ($applied === []) {
            $cart->remove(self::APPLIED)->remove(self::BREAKDOWN)->remove(self::TOTAL);

            return;
        }

        $currency = Money::currency($cart->site());
        $vouchers = Voucher::query()->whereIn('id', $applied)->get()->keyBy('id');

        $payable = max(0, $cart->grandTotal() - $this->notPayableByVoucher($cart));
        $covered = 0;
        $kept = [];
        $breakdown = [];

        foreach ($applied as $id) {
            $voucher = $vouchers->get($id);

            // A voucher that's been used up, disabled or expired elsewhere
            // since it was entered drops off the cart.
            if (! $voucher || ! $voucher->isUsable() || $voucher->currency !== $currency) {
                continue;
            }

            $amount = min($voucher->balance, $payable - $covered);
            $covered += $amount;
            $kept[] = $id;

            $breakdown[] = [
                'id' => $voucher->id,
                'code' => $voucher->code_hint,
                'amount' => $amount,
            ];
        }

        $cart->set(self::APPLIED, $kept);
        $cart->set(self::BREAKDOWN, $breakdown);
        $cart->set(self::TOTAL, $covered);
        $cart->grandTotal($cart->grandTotal() - $covered);
    }

    /**
     * Why a voucher can't be used on a cart, if it can't.
     *
     * @throws VoucherException
     */
    public function ensureUsable(Voucher $voucher, Cart $cart): void
    {
        match ($voucher->status()) {
            VoucherStatus::Disabled => throw VoucherException::because('disabled'),
            VoucherStatus::Expired => throw VoucherException::because('expired', ['date' => $voucher->expires_at->toFormattedDayDateString()]),
            VoucherStatus::Spent => throw VoucherException::because('spent'),
            VoucherStatus::Active => null,
        };

        if ($voucher->currency !== Money::currency($cart->site())) {
            throw VoucherException::because('currency', ['currency' => $voucher->currency]);
        }
    }

    /**
     * The part of the grand total vouchers can't pay for: other vouchers,
     * unless gift-vouchers.pay_for_vouchers is on. Worked out as Cargo
     * works out the grand total (CalculateTotals).
     */
    protected function notPayableByVoucher(Cart $cart): int
    {
        if (config('gift-vouchers.pay_for_vouchers', false)) {
            return 0;
        }

        $taxIncluded = config('statamic.cargo.taxes.price_includes_tax');

        return $cart->lineItems()
            ->filter(fn (LineItem $lineItem) => $this->products->isVoucherLine($lineItem))
            ->sum(fn (LineItem $lineItem) => $lineItem->subTotal() - $lineItem->discountTotal() + ($taxIncluded ? 0 : $lineItem->taxTotal()));
    }
}
