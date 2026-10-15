<?php

namespace NineteenNinetyFour\GiftVouchers\Actions;

use DuncanMcClean\Cargo\Contracts\Orders\Order;
use NineteenNinetyFour\GiftVouchers\Cargo\CartVouchers;
use NineteenNinetyFour\GiftVouchers\Cargo\OrderVouchers;
use NineteenNinetyFour\GiftVouchers\Ledger;
use NineteenNinetyFour\GiftVouchers\Money;
use NineteenNinetyFour\GiftVouchers\VoucherException;
use Statamic\Actions\Action;
use Statamic\Facades\User;
use Statamic\Fields\Fields;

/**
 * Refund what an order paid by gift voucher back onto the vouchers.
 *
 * Cargo's own Refund refunds the card (or other gateway); this refunds the
 * voucher part. Orders paid entirely by voucher have no gateway, so this
 * is the only way to refund them.
 */
class RefundToGiftVoucher extends Action
{
    protected static $handle = 'refund_to_gift_voucher';

    protected $dangerous = true;

    public static function title()
    {
        return __('gift-vouchers::messages.cp.refund_action');
    }

    public function visibleTo($item)
    {
        // Only orders vouchers were used on are looked up.
        return $item instanceof Order
            && ! empty($item->get(CartVouchers::BREAKDOWN))
            && $this->outstanding($item) > 0;
    }

    public function visibleToBulk($items)
    {
        return false;
    }

    public function authorize($user, $item)
    {
        return $user->can('refund', $item) && $user->can('edit gift vouchers');
    }

    protected function fieldItems()
    {
        $order = $this->items->first();

        return [
            'amount' => [
                'display' => __('Amount'),
                'instructions' => __('gift-vouchers::messages.cp.refund_instructions'),
                'type' => 'money',
                'default' => $order ? $this->outstanding($order) : null,
                'validate' => 'required|numeric',
            ],
            'note' => [
                'display' => __('gift-vouchers::messages.cp.note'),
                'type' => 'text',
                'validate' => 'nullable|max:500',
            ],
        ];
    }

    /**
     * The order is the fields' parent, so the amount is in its currency.
     *
     * @return Fields
     */
    public function fields()
    {
        $fields = parent::fields();

        if ($order = $this->items->first()) {
            $fields->setParent($order);
        }

        return $fields;
    }

    public function buttonText()
    {
        /** @translation */
        return 'Refund';
    }

    public function confirmationText()
    {
        /** @translation */
        return 'Put this amount back on the gift vouchers used on this order?';
    }

    public function bypassesDirtyWarning(): bool
    {
        return true;
    }

    public function run($items, $values)
    {
        $order = collect($items)->first();
        $amount = (int) ($values['amount'] ?? 0);

        try {
            $transactions = app(OrderVouchers::class)->refund($order, $amount, User::current()?->id(), $values['note'] ?? null);
        } catch (VoucherException $e) {
            throw new \Exception($e->getMessage());
        }

        return __('gift-vouchers::messages.cp.refunded', [
            'amount' => Money::format($transactions->sum('amount'), Money::currency($order->site())),
            'count' => $transactions->count(),
        ]);
    }

    protected function outstanding(Order $order): int
    {
        return app(Ledger::class)->outstandingForOrder($order->id())->sum();
    }
}
