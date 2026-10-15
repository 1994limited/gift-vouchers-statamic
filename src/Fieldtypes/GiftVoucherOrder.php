<?php

namespace NineteenNinetyFour\GiftVouchers\Fieldtypes;

use DuncanMcClean\Cargo\Contracts\Orders\Order;
use NineteenNinetyFour\GiftVouchers\Cargo\OrderVouchers;
use NineteenNinetyFour\GiftVouchers\Ledger;
use NineteenNinetyFour\GiftVouchers\Money;
use NineteenNinetyFour\GiftVouchers\TransactionType;
use NineteenNinetyFour\GiftVouchers\Voucher;
use Statamic\Facades\User;
use Statamic\Fields\Fieldtype;

/**
 * The "Gift Vouchers" panel on a Cargo order: the vouchers it was paid
 * with, and the vouchers bought on it. Read only; it keeps nothing.
 */
class GiftVoucherOrder extends Fieldtype
{
    protected static $handle = 'gift_voucher_order';

    protected $selectable = false;

    public function preProcess($data)
    {
        $order = $this->field?->parent();

        if (! $order instanceof Order) {
            return ['used' => [], 'issued' => []];
        }

        $orders = app(OrderVouchers::class);
        $canView = User::current()?->can('view gift vouchers') ?? false;

        $used = $orders->usedOn($order)->map(function (array $row) use ($canView) {
            /** @var Voucher $voucher */
            $voucher = $row['voucher'];
            $spent = -$row['transactions']->where('type', TransactionType::Redeemed)->sum('amount');
            $returned = $row['transactions']->whereIn('type', [TransactionType::Restored, TransactionType::Refunded])->sum('amount');

            return [
                'code' => $voucher->maskedCode(),
                'paid' => $voucher->formatted($spent),
                'returned' => $returned > 0 ? $voucher->formatted($returned) : null,
                'balance' => $voucher->formatted($voucher->balance),
                'url' => $canView ? $voucher->editUrl() : null,
            ];
        })->all();

        $outstanding = app(Ledger::class)->outstandingForOrder($order->id())->sum();

        $issued = $orders->issuedFor($order)->map(fn (Voucher $voucher) => [
            'code' => $voucher->maskedCode(),
            'amount' => $voucher->formatted($voucher->initial_amount),
            'balance' => $voucher->formatted($voucher->balance),
            'recipient' => $voucher->recipient_name ?: $voucher->recipient_email,
            'status' => $voucher->status()->value,
            'status_label' => $voucher->status()->label(),
            'scheduled' => $voucher->isScheduled() ? $voucher->send_on?->toFormattedDayDateString() : null,
            'url' => $canView ? $voucher->editUrl() : null,
        ])->all();

        $currency = $order->site()?->attribute('currency') ?? 'GBP';

        return [
            'used' => $used,
            'issued' => $issued,
            'refundable' => $outstanding > 0 ? Money::format($outstanding, $currency, $order->site()?->locale()) : null,
        ];
    }

    public function process($data)
    {
        return null;
    }

    public function augment($value)
    {
        return null;
    }
}
