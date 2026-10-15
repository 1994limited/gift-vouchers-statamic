<?php

namespace NineteenNinetyFour\GiftVouchers\Tests\Feature;

use DuncanMcClean\Cargo\Facades\Cart;
use DuncanMcClean\Cargo\Facades\TaxClass;
use DuncanMcClean\Cargo\Facades\TaxZone;
use Illuminate\Support\Facades\Mail;
use NineteenNinetyFour\GiftVouchers\Cargo\CartVouchers;
use NineteenNinetyFour\GiftVouchers\Tests\TestCase;
use NineteenNinetyFour\GiftVouchers\Voucher;
use PHPUnit\Framework\Attributes\Test;

/**
 * Vouchers are a way of paying, not a discount: they come off the total
 * after tax, so the tax on what's bought doesn't change.
 */
class TaxTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        TaxClass::make()->handle('general')->set('title', 'General')->save();
        TaxClass::make()->handle('gift_vouchers')->set('title', 'Gift vouchers')->save();
        TaxZone::make()->handle('everywhere')->set('title', 'Everywhere')->set('type', 'everywhere')->set('rates', ['general' => 20])->save();

        $this->productEntry('book', 'product', ['title' => 'A book', 'price' => 3000, 'tax_class' => 'general']);
        $this->fixedVoucher(2500, 'voucher-25', ['tax_class' => 'gift_vouchers']);
    }

    #[Test]
    public function with_prices_including_tax_the_tax_stays_the_same(): void
    {
        config(['statamic.cargo.taxes.price_includes_tax' => true]);

        $cart = $this->cart([['product' => 'book']]);
        $this->assertSame(500, $cart->taxTotal());

        app(CartVouchers::class)->add($cart, $this->voucher(1000)->code());

        $cart = Cart::find($cart->id());
        $this->assertSame(2000, $cart->grandTotal());
        $this->assertSame(500, $cart->taxTotal());
    }

    #[Test]
    public function with_prices_excluding_tax_vouchers_cover_the_tax_too(): void
    {
        config(['statamic.cargo.taxes.price_includes_tax' => false]);

        $cart = $this->cart([['product' => 'book']]);
        $this->assertSame(3600, $cart->grandTotal());

        app(CartVouchers::class)->add($cart, $this->voucher(5000)->code());

        $cart = Cart::find($cart->id());
        $this->assertSame(0, $cart->grandTotal());
        $this->assertSame(600, $cart->taxTotal());
        $this->assertSame(3600, $cart->get(CartVouchers::TOTAL));
    }

    #[Test]
    public function vouchers_sold_with_a_tax_class_without_rates_have_no_tax(): void
    {
        config(['statamic.cargo.taxes.price_includes_tax' => false]);

        $cart = $this->cart([['product' => 'voucher-25']]);

        $this->assertSame(0, $cart->taxTotal());
        $this->assertSame(2500, $cart->grandTotal());

        $this->checkout($cart);
        $this->assertSame(2500, Voucher::sole()->initial_amount);
    }

    #[Test]
    public function with_prices_excluding_tax_voucher_lines_are_still_left_out_of_what_vouchers_pay(): void
    {
        config(['statamic.cargo.taxes.price_includes_tax' => false]);

        $cart = $this->cart([['product' => 'book'], ['product' => 'voucher-25']]);
        app(CartVouchers::class)->add($cart, $this->voucher(10000)->code());

        $cart = Cart::find($cart->id());
        $this->assertSame(3600, $cart->get(CartVouchers::TOTAL));
        $this->assertSame(2500, $cart->grandTotal());
    }
}
