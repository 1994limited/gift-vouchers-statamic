<?php

namespace NineteenNinetyFour\GiftVouchers\Cargo;

use DuncanMcClean\Cargo\Contracts\Cart\Cart;
use DuncanMcClean\Cargo\Contracts\Products\Product;
use DuncanMcClean\Cargo\Orders\LineItem;
use NineteenNinetyFour\GiftVouchers\Money;
use Statamic\Contracts\Entries\Entry;

/**
 * Voucher products: Cargo products with a voucher blueprint (config
 * gift-vouchers.blueprints). A voucher is either a fixed amount (its
 * price, or its variants' prices) or an amount the shopper chooses
 * between a minimum and maximum.
 *
 * What a shopper pays for a voucher and what the voucher is worth are
 * always the same number: both come from unitPrice().
 */
class Products
{
    /** Line item data kept with each voucher in the cart. */
    public const AMOUNT = 'voucher_amount';

    public const RECIPIENT_NAME = 'voucher_recipient_name';

    public const RECIPIENT_EMAIL = 'voucher_recipient_email';

    public const SENDER_NAME = 'voucher_sender_name';

    public const MESSAGE = 'voucher_message';

    public const SEND_ON = 'voucher_send_on';

    public function isVoucher(Product|Entry|null $product): bool
    {
        if (! $product || ! in_array($product->collectionHandle(), (array) config('statamic.cargo.products.collections', ['products']), true)) {
            return false;
        }

        return in_array($product->blueprint()?->handle(), (array) config('gift-vouchers.blueprints', ['gift_voucher']), true);
    }

    public function isVoucherLine(LineItem $lineItem): bool
    {
        return $this->isVoucher($lineItem->product());
    }

    /**
     * Whether the shopper chooses the amount.
     */
    public function isOpen(Product|Entry $product): bool
    {
        return $product->get('voucher_amount_type') === 'open';
    }

    public function min(Product|Entry $product): int
    {
        return $this->amountField($product, 'voucher_min') ?? max(1, (int) config('gift-vouchers.amounts.min', 1000));
    }

    public function max(Product|Entry $product): int
    {
        $max = $this->amountField($product, 'voucher_max') ?? (int) config('gift-vouchers.amounts.max', 50000);

        return min(Money::MAX, max($this->min($product), $max));
    }

    /**
     * Amounts to offer as quick choices, within the product's limits.
     *
     * @return array<int, int>
     */
    public function suggested(Product|Entry $product, string $currency): array
    {
        return collect((array) $product->get('voucher_suggested_amounts', []))
            ->map(fn ($amount) => Money::parse(is_scalar($amount) ? (string) $amount : null, $currency))
            ->filter(fn (?int $amount) => $amount !== null && $amount >= $this->min($product) && $amount <= $this->max($product))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * How many months vouchers bought from this product last, or null for no expiry.
     */
    public function expiryMonths(Product|Entry|null $product): ?int
    {
        $months = $product?->get('voucher_expiry_months');

        if (is_numeric($months) && (int) $months > 0) {
            return (int) $months;
        }

        $default = config('gift-vouchers.expiry_months');

        return is_numeric($default) && (int) $default > 0 ? (int) $default : null;
    }

    /**
     * The price of one voucher in a line item, or null if it isn't a voucher.
     *
     * A chosen amount comes from the line item, which a visitor can edit
     * through Cargo's own routes, so it's held to the product's limits here,
     * every time the cart is worked out.
     */
    public function unitPrice(Cart $cart, LineItem $lineItem): ?int
    {
        $product = $lineItem->product();

        if (! $this->isVoucher($product)) {
            return null;
        }

        if ($this->isOpen($product)) {
            return $this->clamp($product, $lineItem->get(self::AMOUNT));
        }

        if ($product->isVariantProduct()) {
            return (int) $product->variant($lineItem->variant()?->key())?->price();
        }

        return (int) $product->price();
    }

    /**
     * A chosen amount held to the product's minimum and maximum. Anything
     * that isn't a whole number of pence counts as the minimum.
     */
    public function clamp(Product|Entry $product, mixed $amount): int
    {
        $amount = is_int($amount) || (is_string($amount) && ctype_digit($amount)) ? (int) $amount : null;

        if ($amount === null) {
            return $this->min($product);
        }

        return max($this->min($product), min($this->max($product), $amount));
    }

    protected function amountField(Product|Entry $product, string $field): ?int
    {
        $value = $product->get($field);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
