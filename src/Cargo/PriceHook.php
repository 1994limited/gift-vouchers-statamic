<?php

namespace NineteenNinetyFour\GiftVouchers\Cargo;

use Closure;
use DuncanMcClean\Cargo\Cart\Calculator\CalculateLineItems;
use DuncanMcClean\Cargo\Contracts\Cart\Cart;
use DuncanMcClean\Cargo\Orders\LineItem;
use ReflectionProperty;

/**
 * Prices voucher line items, through Cargo's documented price hook
 * (CalculateLineItems::priceHook()).
 *
 * Cargo keeps one price hook. If the site has set its own, it's kept and
 * used for everything that isn't a voucher; if there isn't one, Cargo's
 * normal pricing is. Installed once the app has booted, so it works
 * whichever order the site and this addon set their hooks in.
 */
class PriceHook
{
    protected static ?Closure $hook = null;

    protected static ?Closure $previous = null;

    public static function install(): void
    {
        $current = static::current();

        if ($current !== null && $current === static::$hook) {
            return;
        }

        static::$previous = $current;
        static::$hook = function (Cart $cart, LineItem $lineItem) {
            return app(Products::class)->unitPrice($cart, $lineItem)
                ?? (static::$previous ? (static::$previous)($cart, $lineItem) : static::cargoPrice($lineItem));
        };

        CalculateLineItems::priceHook(static::$hook);
    }

    /**
     * Whether vouchers are being priced: false if something replaced the
     * hook after this addon set it. Shown in the Control Panel.
     */
    public static function isInstalled(): bool
    {
        return static::$hook !== null && static::current() === static::$hook;
    }

    /**
     * Take the hook out again (for tests), putting back the one it replaced.
     */
    public static function uninstall(): void
    {
        if (static::isInstalled()) {
            CalculateLineItems::priceHook(static::$previous);
        }

        static::$hook = null;
        static::$previous = null;
    }

    /**
     * The hook Cargo has now. Cargo doesn't offer a way to read it, so it's
     * read with reflection (read only; Cargo's class isn't changed).
     */
    protected static function current(): ?Closure
    {
        try {
            $value = (new ReflectionProperty(CalculateLineItems::class, 'priceHook'))->getValue();
        } catch (\Throwable) {
            // A Cargo release has changed how it keeps the hook. Vouchers are
            // still priced (install() sets the hook); a site's own hook just
            // can't be kept alongside it, or a replaced hook noticed.
            return static::$hook;
        }

        return $value instanceof Closure ? $value : null;
    }

    /**
     * What Cargo charges when there's no hook (CalculateLineItems).
     */
    protected static function cargoPrice(LineItem $lineItem): int
    {
        $product = $lineItem->product();

        return (int) ($product->isStandardProduct()
            ? $product->price()
            : $product->variant($lineItem->variant()?->key())?->price());
    }
}
