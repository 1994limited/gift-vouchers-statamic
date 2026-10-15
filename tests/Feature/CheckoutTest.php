<?php

namespace NineteenNinetyFour\GiftVouchers\Tests\Feature;

use DuncanMcClean\Cargo\Cart\Calculator\CalculateLineItems;
use DuncanMcClean\Cargo\Exceptions\PreventCheckout;
use DuncanMcClean\Cargo\Facades\Cart;
use DuncanMcClean\Cargo\Facades\Order;
use DuncanMcClean\Cargo\Orders\OrderStatus;
use Illuminate\Support\Facades\Mail;
use NineteenNinetyFour\GiftVouchers\Cargo\CartVouchers;
use NineteenNinetyFour\GiftVouchers\Cargo\PriceHook;
use NineteenNinetyFour\GiftVouchers\Cargo\Products;
use NineteenNinetyFour\GiftVouchers\Ledger;
use NineteenNinetyFour\GiftVouchers\Mail\VoucherMail;
use NineteenNinetyFour\GiftVouchers\Tests\TestCase;
use NineteenNinetyFour\GiftVouchers\Transaction;
use NineteenNinetyFour\GiftVouchers\Voucher;
use NineteenNinetyFour\GiftVouchers\VoucherException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class CheckoutTest extends TestCase
{
    protected function carts(): CartVouchers
    {
        return app(CartVouchers::class);
    }

    #[Test]
    public function a_fixed_voucher_costs_its_price(): void
    {
        $this->fixedVoucher(2500);

        $cart = $this->cart([['product' => 'voucher-25', 'quantity' => 2]]);

        $this->assertSame(2500, $cart->lineItems()->first()->unitPrice());
        $this->assertSame(5000, $cart->grandTotal());
    }

    #[Test]
    public function an_open_voucher_costs_the_amount_chosen(): void
    {
        $this->openVoucher(1000, 20000);

        $cart = $this->cart([['product' => 'voucher-open', Products::AMOUNT => 4250]]);

        $this->assertSame(4250, $cart->grandTotal());
    }

    #[Test]
    public function an_edited_amount_is_held_to_the_products_limits(): void
    {
        $this->openVoucher(1000, 20000);

        // Cargo lets line item data be edited through its own routes, so
        // whatever ends up there is checked whenever the cart is worked out.
        foreach ([[999999999, 20000], [1, 1000], [-500, 1000], ['abc', 1000], ['12.50', 1000], [null, 1000], [[5000], 1000]] as [$amount, $price]) {
            Cart::forgetCurrentCart();
            $cart = $this->cart([['product' => 'voucher-open', Products::AMOUNT => $amount]]);

            $this->assertSame($price, $cart->grandTotal(), 'For '.json_encode($amount));
        }
    }

    #[Test]
    public function the_voucher_made_is_worth_what_was_paid_for_it(): void
    {
        $this->openVoucher(1000, 20000);
        $cart = $this->cart([['product' => 'voucher-open', Products::AMOUNT => 999999999]]);

        $order = $this->checkout($cart);

        $this->assertSame(20000, $order->grandTotal());
        $this->assertSame(20000, Voucher::sole()->initial_amount);
    }

    #[Test]
    public function other_products_keep_cargos_pricing(): void
    {
        $this->product(1500);
        $this->fixedVoucher(2500);

        $cart = $this->cart([['product' => 'book'], ['product' => 'voucher-25']]);

        $this->assertSame(4000, $cart->grandTotal());
    }

    #[Test]
    public function a_price_hook_the_site_set_is_kept_for_everything_else(): void
    {
        PriceHook::uninstall();
        CalculateLineItems::priceHook(fn ($cart, $lineItem) => 999);
        PriceHook::install();

        $this->product(1500);
        $this->fixedVoucher(2500);
        $cart = $this->cart([['product' => 'book'], ['product' => 'voucher-25']]);

        $this->assertSame(999 + 2500, $cart->grandTotal());
        $this->assertTrue(PriceHook::isInstalled());

        // Something replacing the hook afterwards is spotted (and shown in the CP).
        CalculateLineItems::priceHook(fn () => 1);
        $this->assertFalse(PriceHook::isInstalled());
    }

    #[Test]
    public function installing_the_hook_twice_does_not_wrap_it_in_itself(): void
    {
        PriceHook::install();
        PriceHook::install();

        $this->product(1500);
        $cart = $this->cart([['product' => 'book']]);

        $this->assertSame(1500, $cart->grandTotal());
    }

    #[Test]
    public function voucher_products_are_saved_as_digital_so_they_skip_shipping(): void
    {
        $product = $this->fixedVoucher(2500);

        $this->assertSame('digital', $product->get('type'));
        $this->assertSame('physical', $this->product()->get('type', 'physical'));
    }

    #[Test]
    public function a_voucher_comes_off_the_grand_total(): void
    {
        $this->product(3000);
        $voucher = $this->voucher(1000);
        $cart = $this->cart([['product' => 'book']]);

        $this->carts()->add($cart, $voucher->code());

        $cart = Cart::find($cart->id());
        $this->assertSame(2000, $cart->grandTotal());
        $this->assertSame(1000, $cart->get(CartVouchers::TOTAL));
        $this->assertSame([['id' => $voucher->id, 'code' => $voucher->code_hint, 'amount' => 1000]], $cart->get(CartVouchers::BREAKDOWN));

        // Nothing is spent until the order is placed.
        $this->assertSame(1000, $voucher->fresh()->balance);
    }

    #[Test]
    public function a_voucher_bigger_than_the_order_covers_it_and_keeps_the_rest(): void
    {
        $this->product(3000);
        $voucher = $this->voucher(5000);
        $cart = $this->cart([['product' => 'book']]);

        $this->carts()->add($cart, $voucher->code());
        $order = $this->checkout($cart);

        $this->assertSame(0, $order->grandTotal());
        $this->assertSame(OrderStatus::PaymentReceived, $order->status());
        $this->assertNull($order->get('payment_gateway'));
        $this->assertSame(2000, $voucher->fresh()->balance);
    }

    #[Test]
    public function the_rest_of_the_order_is_paid_another_way(): void
    {
        $this->product(3000);
        $voucher = $this->voucher(1000);
        $cart = $this->cart([['product' => 'book']]);

        $this->carts()->add($cart, $voucher->code());
        $order = $this->checkout($cart);

        $this->assertSame(2000, $order->grandTotal());
        $this->assertSame('dummy', $order->get('payment_gateway'));
        $this->assertSame(OrderStatus::PaymentReceived, $order->status());
        $this->assertSame(0, $voucher->fresh()->balance);

        $transaction = Transaction::where('type', 'redeemed')->sole();
        $this->assertSame($order->id(), $transaction->order_id);
        $this->assertSame(-1000, $transaction->amount);
    }

    #[Test]
    public function several_vouchers_can_be_used_in_the_order_they_were_entered(): void
    {
        $this->product(3000);
        $a = $this->voucher(1000);
        $b = $this->voucher(1500);
        $c = $this->voucher(5000);
        $cart = $this->cart([['product' => 'book']]);

        $this->carts()->add($cart, $a->code());
        $this->carts()->add($cart, $b->code());
        $this->carts()->add($cart, $c->code());

        $order = $this->checkout($cart);

        $this->assertSame(0, $order->grandTotal());
        $this->assertSame(0, $a->fresh()->balance);
        $this->assertSame(0, $b->fresh()->balance);
        $this->assertSame(4500, $c->fresh()->balance);
    }

    #[Test]
    public function a_voucher_that_would_cover_nothing_is_not_added(): void
    {
        $this->product(1000);
        $big = $this->voucher(5000);
        $other = $this->voucher(5000);
        $cart = $this->cart([['product' => 'book']]);

        $this->carts()->add($cart, $big->code());

        try {
            $this->carts()->add($cart, $other->code());
            $this->fail('A voucher covering nothing was added.');
        } catch (VoucherException $e) {
            $this->assertSame('nothing_to_pay', $e->reason);
        }

        $this->assertSame([$big->id], Cart::find($cart->id())->get(CartVouchers::APPLIED));
    }

    #[Test]
    public function the_same_voucher_cannot_be_used_twice_on_one_order(): void
    {
        $this->product(5000);
        $voucher = $this->voucher(1000);
        $cart = $this->cart([['product' => 'book']]);

        $this->carts()->add($cart, $voucher->code());

        $this->expectExceptionObject(VoucherException::because('already_applied'));
        $this->carts()->add($cart, strtolower($voucher->code()));
    }

    #[Test]
    public function only_so_many_vouchers_can_be_used_on_one_order(): void
    {
        config(['gift-vouchers.max_per_cart' => 2]);
        $this->product(5000);
        $cart = $this->cart([['product' => 'book']]);

        $this->carts()->add($cart, $this->voucher(100)->code());
        $this->carts()->add($cart, $this->voucher(100)->code());

        $this->expectExceptionObject(VoucherException::because('too_many', ['max' => 2]));
        $this->carts()->add($cart, $this->voucher(100)->code());
    }

    #[Test]
    #[DataProvider('unusable')]
    public function unusable_vouchers_are_refused(array $attributes, string $reason, string $currency = 'GBP'): void
    {
        $this->product(5000);
        $voucher = $this->voucher(1000, $attributes, currency: $currency);
        $cart = $this->cart([['product' => 'book']]);

        if ($reason === 'spent') {
            app(Ledger::class)->debit($voucher, 1000);
        }

        try {
            $this->carts()->add($cart, $voucher->code());
            $this->fail('An unusable voucher was used.');
        } catch (VoucherException $e) {
            $this->assertSame($reason, $e->reason);
        }

        $this->assertSame(5000, Cart::find($cart->id())->grandTotal());
    }

    public static function unusable(): array
    {
        return [
            'disabled' => [['disabled_at' => now()], 'disabled'],
            'expired' => [['expires_at' => now()->subDay()], 'expired'],
            'spent' => [[], 'spent'],
            'another currency' => [[], 'currency', 'EUR'],
        ];
    }

    #[Test]
    public function an_unknown_code_is_refused(): void
    {
        $cart = $this->cart([['product' => $this->product()->id()]]);

        $this->expectExceptionObject(VoucherException::because('not_found'));
        $this->carts()->add($cart, 'NOPE-NOPE-NOPE-NOPE');
    }

    #[Test]
    public function vouchers_cannot_be_spent_on_more_vouchers(): void
    {
        $this->product(1000);
        $this->fixedVoucher(2500);
        $voucher = $this->voucher(10000);
        $cart = $this->cart([['product' => 'book'], ['product' => 'voucher-25']]);

        $this->carts()->add($cart, $voucher->code());

        $cart = Cart::find($cart->id());
        $this->assertSame(1000, $cart->get(CartVouchers::TOTAL));
        $this->assertSame(2500, $cart->grandTotal());
    }

    #[Test]
    public function an_order_of_only_vouchers_cannot_be_paid_with_a_voucher(): void
    {
        $this->fixedVoucher(2500);
        $cart = $this->cart([['product' => 'voucher-25']]);

        $this->expectExceptionObject(VoucherException::because('nothing_to_pay'));
        $this->carts()->add($cart, $this->voucher(10000)->code());
    }

    #[Test]
    public function vouchers_can_buy_vouchers_if_the_site_allows_it(): void
    {
        config(['gift-vouchers.pay_for_vouchers' => true]);
        $this->fixedVoucher(2500);
        $voucher = $this->voucher(10000);
        $cart = $this->cart([['product' => 'voucher-25']]);

        $this->carts()->add($cart, $voucher->code());

        $this->assertSame(0, Cart::find($cart->id())->grandTotal());
    }

    #[Test]
    public function the_voucher_follows_the_cart_as_it_changes(): void
    {
        $this->product(3000);
        $voucher = $this->voucher(5000);
        $cart = $this->cart([['product' => 'book']]);
        $this->carts()->add($cart, $voucher->code());

        $cart = Cart::find($cart->id());
        $cart->lineItems()->update($cart->lineItems()->first()->id(), ['product' => 'book', 'quantity' => 3]);
        $cart->save();

        $this->assertSame(5000, $cart->get(CartVouchers::TOTAL));
        $this->assertSame(4000, $cart->grandTotal());
    }

    #[Test]
    public function a_voucher_disabled_after_it_was_entered_drops_off_the_cart(): void
    {
        $this->product(3000);
        $voucher = $this->voucher(1000);
        $cart = $this->cart([['product' => 'book']]);
        $this->carts()->add($cart, $voucher->code());

        $voucher->forceFill(['disabled_at' => now()])->save();

        $cart = Cart::find($cart->id());
        $cart->recalculate();

        $this->assertSame([], $cart->get(CartVouchers::APPLIED));
        $this->assertSame(3000, $cart->grandTotal());
    }

    #[Test]
    public function a_voucher_can_be_taken_off_the_cart(): void
    {
        $this->product(3000);
        $voucher = $this->voucher(1000);
        $cart = $this->cart([['product' => 'book']]);
        $this->carts()->add($cart, $voucher->code());

        $this->assertTrue($this->carts()->remove(Cart::find($cart->id()), $voucher->id));
        $this->assertFalse($this->carts()->remove(Cart::find($cart->id()), $voucher->id));

        $cart = Cart::find($cart->id());
        $this->assertSame(3000, $cart->grandTotal());
        $this->assertNull($cart->get(CartVouchers::BREAKDOWN));
    }

    #[Test]
    public function checkout_stops_if_the_voucher_was_spent_elsewhere_in_the_meantime(): void
    {
        $this->product(3000);
        $voucher = $this->voucher(2000);
        $cart = $this->cart([['product' => 'book']]);
        $this->carts()->add($cart, $voucher->code());

        // Spent on another order between entering it and paying.
        app(Ledger::class)->debit($voucher, 1500, 'another-order');

        try {
            $this->checkout($cart);
            $this->fail('Checkout went through with money the voucher no longer had.');
        } catch (PreventCheckout $e) {
            $this->assertStringContainsString('only has £5.00 left', $e->getMessage());
        }

        $this->assertSame(500, $voucher->fresh()->balance);
        $this->assertSame(0, Transaction::where('order_id', '!=', 'another-order')->where('type', 'redeemed')->count());
    }

    #[Test]
    public function the_checkout_route_cancels_the_order_and_puts_nothing_back_twice(): void
    {
        $this->product(3000);
        $voucher = $this->voucher(2000);
        $cart = $this->cart([['product' => 'book']]);
        $this->carts()->add($cart, $voucher->code());
        app(Ledger::class)->debit($voucher, 1500, 'another-order');

        $this->get(route('statamic.cargo.payments.checkout', 'dummy'))->assertRedirect('/checkout')->assertSessionHasErrors('checkout');

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(500, $voucher->fresh()->balance);
    }

    #[Test]
    public function vouchers_bought_are_made_and_emailed_when_the_order_is_paid(): void
    {
        Mail::fake();

        $this->fixedVoucher(2500);
        $cart = $this->cart([[
            'product' => 'voucher-25',
            Products::RECIPIENT_NAME => 'Grace',
            Products::RECIPIENT_EMAIL => 'grace@example.com',
            Products::SENDER_NAME => 'Ada',
            Products::MESSAGE => 'Happy birthday!',
        ]]);

        $order = $this->checkout($cart);

        $voucher = Voucher::sole();
        $this->assertSame(2500, $voucher->balance);
        $this->assertSame('Grace', $voucher->recipient_name);
        $this->assertSame('grace@example.com', $voucher->recipient_email);
        $this->assertSame('Happy birthday!', $voucher->message);
        $this->assertSame($order->id(), $voucher->order_id);
        $this->assertNotNull($voucher->sent_at);

        Mail::assertSent(VoucherMail::class, fn (VoucherMail $mail) => $mail->hasTo('grace@example.com') && $mail->voucher->is($voucher));
    }

    #[Test]
    public function without_a_recipient_the_voucher_goes_to_the_customer(): void
    {
        Mail::fake();

        $this->fixedVoucher(2500);
        $this->checkout($this->cart([['product' => 'voucher-25']]));

        Mail::assertSent(VoucherMail::class, fn (VoucherMail $mail) => $mail->hasTo('ada@example.com'));
    }

    #[Test]
    public function one_voucher_is_made_for_each_one_bought(): void
    {
        Mail::fake();

        $this->fixedVoucher(2500);
        $this->openVoucher();
        $cart = $this->cart([
            ['product' => 'voucher-25', 'quantity' => 3],
            ['product' => 'voucher-open', Products::AMOUNT => 7500],
        ]);

        $this->checkout($cart);

        $this->assertSame([2500, 2500, 2500, 7500], Voucher::orderBy('initial_amount')->pluck('initial_amount')->all());
        $this->assertCount(4, Voucher::pluck('code_hash')->unique());
        Mail::assertSentCount(4);
    }

    #[Test]
    public function marking_an_order_paid_again_makes_no_more_vouchers(): void
    {
        Mail::fake();

        $this->fixedVoucher(2500);
        $order = $this->checkout($this->cart([['product' => 'voucher-25', 'quantity' => 2]]));

        $order->status(OrderStatus::Shipped)->save();
        $order->status(OrderStatus::PaymentReceived)->save();

        $this->assertSame(2, Voucher::count());
        Mail::assertSentCount(2);
    }

    #[Test]
    public function vouchers_are_not_made_until_the_order_is_paid(): void
    {
        $this->fixedVoucher(2500);
        $cart = $this->cart([['product' => 'voucher-25']]);

        $order = Order::makeFromCart(Cart::find($cart->id()));
        $order->save();

        $this->assertSame(OrderStatus::PaymentPending, $order->status());
        $this->assertSame(0, Voucher::count());
    }

    #[Test]
    public function a_send_date_holds_the_email_until_that_day(): void
    {
        Mail::fake();

        $this->fixedVoucher(2500);
        $this->checkout($this->cart([['product' => 'voucher-25', Products::SEND_ON => now()->addDays(3)->toDateString(), Products::RECIPIENT_EMAIL => 'grace@example.com']]));

        $voucher = Voucher::sole();
        $this->assertTrue($voucher->isScheduled());
        $this->assertNull($voucher->sent_at);
        Mail::assertNothingSent();

        $this->travel(3)->days();
        $this->artisan('gift-vouchers:send')->assertSuccessful();

        Mail::assertSent(VoucherMail::class, fn ($mail) => $mail->hasTo('grace@example.com'));
        $this->assertNotNull($voucher->fresh()->sent_at);

        // And only once.
        $this->artisan('gift-vouchers:send')->assertSuccessful();
        Mail::assertSentCount(1);
    }

    #[Test]
    public function a_bad_recipient_email_or_date_in_the_line_item_is_ignored(): void
    {
        Mail::fake();

        $this->fixedVoucher(2500);
        $this->checkout($this->cart([['product' => 'voucher-25', Products::RECIPIENT_EMAIL => 'not an email', Products::SEND_ON => '2001-01-01']]));

        $voucher = Voucher::sole();
        $this->assertNull($voucher->recipient_email);
        $this->assertNull($voucher->send_on);
        Mail::assertSent(VoucherMail::class, fn ($mail) => $mail->hasTo('ada@example.com'));
    }

    #[Test]
    public function vouchers_expire_after_the_products_months_or_the_default(): void
    {
        Mail::fake();
        config(['gift-vouchers.expiry_months' => 24]);

        $this->fixedVoucher(2500, 'twelve', ['voucher_expiry_months' => 12]);
        $this->fixedVoucher(2500, 'default');
        $this->checkout($this->cart([['product' => 'twelve'], ['product' => 'default']]));

        $vouchers = Voucher::get()->keyBy('product_id');
        $this->assertSame(now()->addMonths(12)->toDateString(), $vouchers['twelve']->expires_at->toDateString());
        $this->assertSame(now()->addMonths(24)->toDateString(), $vouchers['default']->expires_at->toDateString());
    }

    #[Test]
    public function by_default_vouchers_never_expire(): void
    {
        Mail::fake();

        $this->fixedVoucher(2500);
        $this->checkout($this->cart([['product' => 'voucher-25']]));

        $this->assertNull(Voucher::sole()->expires_at);
    }

    #[Test]
    public function a_mail_problem_does_not_stop_the_order(): void
    {
        config(['mail.default' => 'failing']);
        config(['mail.mailers.failing' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 1]]);

        $this->fixedVoucher(2500);
        $order = $this->checkout($this->cart([['product' => 'voucher-25']]));

        $this->assertSame(OrderStatus::PaymentReceived, $order->status());
        $this->assertSame(1, Voucher::count());
        $this->assertNull(Voucher::sole()->sent_at);
    }

    #[Test]
    public function cancelling_an_order_puts_back_what_it_spent_and_disables_what_it_bought(): void
    {
        Mail::fake();

        $this->product(3000);
        $this->fixedVoucher(2500);
        $voucher = $this->voucher(10000);
        $cart = $this->cart([['product' => 'book'], ['product' => 'voucher-25']]);
        $this->carts()->add($cart, $voucher->code());

        $order = $this->checkout($cart);
        $this->assertSame(7000, $voucher->fresh()->balance);
        $bought = Voucher::where('order_id', $order->id())->sole();

        $order->status(OrderStatus::Cancelled)->save();

        $this->assertSame(10000, $voucher->fresh()->balance);
        $this->assertNotNull($bought->fresh()->disabled_at);

        // Cancelling again changes nothing.
        $order->status(OrderStatus::PaymentReceived)->save();
        $order->status(OrderStatus::Cancelled)->save();
        $this->assertSame(10000, $voucher->fresh()->balance);
    }

    #[Test]
    public function cancelling_leaves_vouchers_alone_if_the_site_says_so(): void
    {
        config(['gift-vouchers.restore_on_cancel' => false]);

        $this->product(3000);
        $voucher = $this->voucher(1000);
        $cart = $this->cart([['product' => 'book']]);
        $this->carts()->add($cart, $voucher->code());
        $order = $this->checkout($cart);

        $order->status(OrderStatus::Cancelled)->save();

        $this->assertSame(0, $voucher->fresh()->balance);
    }

    #[Test]
    public function deleting_an_unpaid_order_puts_back_what_it_spent(): void
    {
        $this->product(3000);
        $voucher = $this->voucher(1000);
        $cart = $this->cart([['product' => 'book']]);
        $this->carts()->add($cart, $voucher->code());

        $order = Order::makeFromCart(Cart::find($cart->id()));
        $order->save();
        $this->assertSame(0, $voucher->fresh()->balance);

        $order->delete();

        $this->assertSame(1000, $voucher->fresh()->balance);
    }

    #[Test]
    public function deleting_a_paid_order_does_not_give_the_money_back(): void
    {
        $this->product(3000);
        $voucher = $this->voucher(1000);
        $cart = $this->cart([['product' => 'book']]);
        $this->carts()->add($cart, $voucher->code());

        $this->checkout($cart)->delete();

        $this->assertSame(0, $voucher->fresh()->balance);
    }

    #[Test]
    public function orders_cargo_saves_as_cancelled_spend_nothing(): void
    {
        $this->product(3000);
        $voucher = $this->voucher(1000);
        $cart = $this->cart([['product' => 'book']]);
        $this->carts()->add($cart, $voucher->code());

        Order::makeFromCart(Cart::find($cart->id()))->status(OrderStatus::Cancelled)->save();

        $this->assertSame(1000, $voucher->fresh()->balance);
    }

    #[Test]
    public function the_order_timeline_records_what_happened(): void
    {
        Mail::fake();

        $this->product(3000);
        $this->fixedVoucher(2500);
        $voucher = $this->voucher(1000);
        $cart = $this->cart([['product' => 'book'], ['product' => 'voucher-25']]);
        $this->carts()->add($cart, $voucher->code());

        $order = Order::find($this->checkout($cart)->id());
        $messages = $order->timelineEvents()->map->message()->all();

        $this->assertContains('£10.00 paid with gift voucher •••• '.$voucher->code_hint, $messages);
        $this->assertContains('1 gift voucher issued', $messages);
    }

    #[Test]
    public function a_cart_order_field_posted_by_a_visitor_is_not_kept(): void
    {
        $this->product(3000);
        $cart = $this->cart([['product' => 'book']]);
        $cart->set('gift_voucher_details', '<script>alert(1)</script>')->save();

        $order = $this->checkout($cart);

        $this->assertFalse(Order::find($order->id())->has('gift_voucher_details'));
    }
}
