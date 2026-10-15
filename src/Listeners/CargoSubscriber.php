<?php

namespace NineteenNinetyFour\GiftVouchers\Listeners;

use DuncanMcClean\Cargo\Events\CartRecalculated;
use DuncanMcClean\Cargo\Events\LineItemBlueprintFound;
use DuncanMcClean\Cargo\Events\OrderBlueprintFound;
use DuncanMcClean\Cargo\Events\OrderCreated;
use DuncanMcClean\Cargo\Events\OrderDeleted;
use DuncanMcClean\Cargo\Events\OrderPaymentReceived;
use DuncanMcClean\Cargo\Events\OrderRefunded;
use DuncanMcClean\Cargo\Events\OrderStatusUpdated;
use DuncanMcClean\Cargo\Exceptions\PreventCheckout;
use DuncanMcClean\Cargo\Orders\OrderStatus;
use Illuminate\Events\Dispatcher;
use NineteenNinetyFour\GiftVouchers\Cargo\CartVouchers;
use NineteenNinetyFour\GiftVouchers\Cargo\OrderVouchers;
use NineteenNinetyFour\GiftVouchers\Cargo\Products;
use NineteenNinetyFour\GiftVouchers\Ledger;
use NineteenNinetyFour\GiftVouchers\VoucherException;
use Statamic\Events\EntrySaving;

/**
 * Where the addon meets Cargo: only Cargo's and Statamic's own events.
 */
class CargoSubscriber
{
    /** The order blueprint field that shows vouchers in the Control Panel. */
    public const ORDER_FIELD = 'gift_voucher_details';

    public function __construct(
        protected CartVouchers $carts,
        protected OrderVouchers $orders,
        protected Ledger $ledger,
        protected Products $products,
    ) {}

    public function subscribe(Dispatcher $events): array
    {
        return [
            CartRecalculated::class => 'cartRecalculated',
            OrderCreated::class => 'orderCreated',
            OrderPaymentReceived::class => 'paymentReceived',
            OrderStatusUpdated::class => 'statusUpdated',
            OrderDeleted::class => 'orderDeleted',
            OrderRefunded::class => 'orderRefunded',
            OrderBlueprintFound::class => 'orderBlueprint',
            LineItemBlueprintFound::class => 'lineItemBlueprint',
            EntrySaving::class => 'entrySaving',
        ];
    }

    /**
     * Take vouchers off the grand total, after tax and shipping.
     */
    public function cartRecalculated(CartRecalculated $event): void
    {
        $this->carts->calculate($event->cart);
    }

    /**
     * Spend the vouchers used on the order as it's placed. If a voucher no
     * longer covers its part (spent elsewhere in the meantime), checkout
     * stops: Cargo cancels the payment and deletes the order.
     */
    public function orderCreated(OrderCreated $event): void
    {
        $order = $event->order;

        // Anything posted to the Control Panel field through Cargo's cart
        // route is nothing of ours.
        if ($order->has(self::ORDER_FIELD)) {
            $order->remove(self::ORDER_FIELD);
        }

        // Cargo saves orders it couldn't take payment for as cancelled.
        if ($order->status() === OrderStatus::Cancelled) {
            return;
        }

        try {
            $this->orders->redeem($order);
        } catch (VoucherException $e) {
            throw new PreventCheckout($e->getMessage());
        }
    }

    /**
     * Make (and send) the vouchers bought on an order once it's paid.
     */
    public function paymentReceived(OrderPaymentReceived $event): void
    {
        $this->orders->issue($event->order);
    }

    /**
     * A cancelled order gets back what it spent from vouchers, and the
     * vouchers it bought are disabled (gift-vouchers.restore_on_cancel).
     */
    public function statusUpdated(OrderStatusUpdated $event): void
    {
        if ($event->updatedStatus !== OrderStatus::Cancelled || $event->originalStatus === OrderStatus::Cancelled) {
            return;
        }

        if (! config('gift-vouchers.restore_on_cancel', true)) {
            return;
        }

        $reason = __('gift-vouchers::messages.notes.order_cancelled', ['order' => $event->order->orderNumber()]);

        $this->orders->restore($event->order, $reason);
        $this->orders->disableIssued($event->order, $reason);
    }

    /**
     * An order deleted before it was paid (Cargo deletes orders whose
     * payment failed at checkout) gets back what it spent from vouchers.
     * Deleting a paid order doesn't: that isn't a refund.
     */
    public function orderDeleted(OrderDeleted $event): void
    {
        if (! in_array($event->order->status(), [OrderStatus::PaymentPending, OrderStatus::Cancelled], true)) {
            return;
        }

        $this->ledger->restoreForOrder($event->order->id(), __('gift-vouchers::messages.notes.order_failed'));
    }

    /**
     * When an order is refunded in full, the vouchers bought on it are
     * disabled (gift-vouchers.disable_on_full_refund). After a part refund
     * they're left alone, with a note on the order's timeline.
     */
    public function orderRefunded(OrderRefunded $event): void
    {
        $order = $event->order;

        if ($this->orders->issuedFor($order)->isEmpty()) {
            return;
        }

        $full = $event->amount >= $order->grandTotal() || (int) $order->get('amount_refunded') >= $order->grandTotal();

        if ($full && config('gift-vouchers.disable_on_full_refund', true)) {
            $this->orders->disableIssued($order, __('gift-vouchers::messages.notes.order_refunded', ['order' => $order->orderNumber()]));

            return;
        }

        $this->orders->timeline($order, 'part_refund');
    }

    /**
     * Show the vouchers used on an order, and those bought on it, in the
     * order's Payment tab.
     */
    public function orderBlueprint(OrderBlueprintFound $event): void
    {
        $event->blueprint->ensureFieldInTab(self::ORDER_FIELD, [
            'type' => 'gift_voucher_order',
            'display' => __('gift-vouchers::messages.cp.gift_vouchers'),
            'listable' => false,
            'filterable' => false,
            'sortable' => false,
        ], 'payment');
    }

    /**
     * Show a voucher's recipient and message with its line item (in the
     * order's receipt, and on packing slips).
     */
    public function lineItemBlueprint(LineItemBlueprintFound $event): void
    {
        $fields = [
            Products::RECIPIENT_NAME => 'recipient_name',
            Products::RECIPIENT_EMAIL => 'recipient_email',
            Products::SENDER_NAME => 'sender_name',
            Products::MESSAGE => 'message',
            Products::SEND_ON => 'send_on',
        ];

        foreach ($fields as $handle => $label) {
            $event->blueprint->ensureField($handle, [
                'type' => $handle === Products::MESSAGE ? 'textarea' : 'text',
                'display' => __('gift-vouchers::messages.fields.'.$label),
                'visibility' => 'read_only',
            ]);
        }
    }

    /**
     * Vouchers are never shipped: they're saved as digital products, so a
     * cart of only vouchers skips Cargo's shipping steps.
     */
    public function entrySaving(EntrySaving $event): void
    {
        $entry = $event->entry;

        if ($this->products->isVoucher($entry) && $entry->get('type') !== 'digital') {
            $entry->set('type', 'digital');
        }
    }
}
