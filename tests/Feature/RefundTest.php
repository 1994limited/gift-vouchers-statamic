<?php

namespace NineteenNinetyFour\GiftVouchers\Tests\Feature;

use DuncanMcClean\Cargo\Actions\Refund;
use DuncanMcClean\Cargo\Facades\Order;
use Illuminate\Support\Facades\Mail;
use NineteenNinetyFour\GiftVouchers\Actions\RefundToGiftVoucher;
use NineteenNinetyFour\GiftVouchers\Cargo\CartVouchers;
use NineteenNinetyFour\GiftVouchers\Ledger;
use NineteenNinetyFour\GiftVouchers\Tests\TestCase;
use NineteenNinetyFour\GiftVouchers\Voucher;
use PHPUnit\Framework\Attributes\Test;

class RefundTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    /**
     * An order for a £30 book, part paid with vouchers.
     *
     * @param  array<int, int>  $balances
     * @return array{0: \DuncanMcClean\Cargo\Contracts\Orders\Order, 1: array<int, Voucher>}
     */
    protected function paidWithVouchers(array $balances): array
    {
        $this->product(3000);
        $cart = $this->cart([['product' => 'book']]);

        $vouchers = array_map(fn (int $balance) => $this->voucher($balance), $balances);

        foreach ($vouchers as $voucher) {
            app(CartVouchers::class)->add($cart, $voucher->code());
        }

        return [$this->checkout($cart), $vouchers];
    }

    #[Test]
    public function the_action_refunds_what_was_paid_by_voucher_back_onto_the_vouchers(): void
    {
        [$order, [$a, $b]] = $this->paidWithVouchers([1000, 1500]);

        $action = new RefundToGiftVoucher;
        $this->assertTrue($action->visibleTo($order));
        $this->assertFalse($action->visibleToBulk(collect([$order])));

        $message = $action->run(collect([$order]), ['amount' => 2000, 'note' => 'Damaged']);

        $this->assertSame('£20.00 refunded to 2 gift vouchers.', $message);
        $this->assertSame(1500, $b->fresh()->balance);
        $this->assertSame(500, $a->fresh()->balance);
        $this->assertSame('Damaged', $b->transactions()->get()->last()->note);

        $this->assertTrue($action->visibleTo(Order::find($order->id())));
        $action->run(collect([$order]), ['amount' => 500]);
        $this->assertFalse($action->visibleTo(Order::find($order->id())));
    }

    #[Test]
    public function the_action_will_not_refund_more_than_was_paid_by_voucher(): void
    {
        [$order, [$voucher]] = $this->paidWithVouchers([1000]);

        $this->expectExceptionMessage('Only £10.00 was paid by gift voucher');

        try {
            (new RefundToGiftVoucher)->run(collect([$order]), ['amount' => 1001]);
        } finally {
            $this->assertSame(0, $voucher->fresh()->balance);
        }
    }

    #[Test]
    public function the_action_is_only_offered_on_orders_paid_with_vouchers(): void
    {
        $this->product(3000);
        $order = $this->checkout($this->cart([['product' => 'book']]));

        $this->assertFalse((new RefundToGiftVoucher)->visibleTo($order));
        $this->assertFalse((new RefundToGiftVoucher)->visibleTo(new \stdClass));
    }

    #[Test]
    public function the_action_needs_permission_to_refund_orders_and_edit_vouchers(): void
    {
        [$order] = $this->paidWithVouchers([1000]);
        $action = new RefundToGiftVoucher;

        $this->assertFalse($action->authorize($this->user('a@example.com', ['view orders', 'refund orders']), $order));
        $this->assertFalse($action->authorize($this->user('b@example.com', ['view gift vouchers', 'edit gift vouchers']), $order));
        $this->assertTrue($action->authorize($this->user('c@example.com', ['view orders', 'refund orders', 'view gift vouchers', 'edit gift vouchers']), $order));
    }

    #[Test]
    public function an_order_paid_entirely_by_voucher_can_be_refunded_in_full(): void
    {
        [$order, [$voucher]] = $this->paidWithVouchers([5000]);

        $this->assertSame(0, $order->grandTotal());
        $this->assertFalse((new Refund)->visibleTo($order)); // Cargo's needs a gateway

        (new RefundToGiftVoucher)->run(collect([$order]), ['amount' => 3000]);

        $this->assertSame(5000, $voucher->fresh()->balance);
    }

    #[Test]
    public function refunds_appear_on_the_order_timeline(): void
    {
        [$order, [$voucher]] = $this->paidWithVouchers([1000]);

        (new RefundToGiftVoucher)->run(collect([$order]), ['amount' => 400]);

        $this->assertContains('£4.00 refunded to gift voucher •••• '.$voucher->code_hint, Order::find($order->id())->timelineEvents()->map->message()->all());
    }

    #[Test]
    public function a_full_card_refund_disables_the_vouchers_the_order_bought(): void
    {
        $this->fixedVoucher(2500);
        $order = $this->checkout($this->cart([['product' => 'voucher-25', 'quantity' => 2]]));

        (new Refund)->run(collect([$order]), ['amount' => 5000]);

        $this->assertSame(2, Voucher::whereNotNull('disabled_at')->count());
        $this->assertContains('2 gift vouchers bought on this order disabled', Order::find($order->id())->timelineEvents()->map->message()->all());
    }

    #[Test]
    public function a_part_refund_leaves_bought_vouchers_alone_with_a_note(): void
    {
        $this->fixedVoucher(2500);
        $order = $this->checkout($this->cart([['product' => 'voucher-25', 'quantity' => 2]]));

        (new Refund)->run(collect([$order]), ['amount' => 2500]);

        $this->assertSame(0, Voucher::whereNotNull('disabled_at')->count());
        $this->assertStringContainsString('Part refunded', implode(' ', Order::find($order->id())->timelineEvents()->map->message()->all()));
    }

    #[Test]
    public function a_full_refund_leaves_bought_vouchers_alone_if_the_site_says_so(): void
    {
        config(['gift-vouchers.disable_on_full_refund' => false]);

        $this->fixedVoucher(2500);
        $order = $this->checkout($this->cart([['product' => 'voucher-25']]));

        (new Refund)->run(collect([$order]), ['amount' => 2500]);

        $this->assertNull(Voucher::sole()->disabled_at);
    }

    #[Test]
    public function a_card_refund_on_an_order_that_bought_no_vouchers_changes_nothing(): void
    {
        [$order, [$voucher]] = $this->paidWithVouchers([1000]);

        (new Refund)->run(collect([$order]), ['amount' => 2000]);

        // The voucher part is refunded separately, with the action.
        $this->assertSame(0, $voucher->fresh()->balance);
        $this->assertSame(1000, app(Ledger::class)->outstandingForOrder($order->id())->sum());
    }
}
