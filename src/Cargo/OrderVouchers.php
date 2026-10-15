<?php

namespace NineteenNinetyFour\GiftVouchers\Cargo;

use DuncanMcClean\Cargo\Contracts\Orders\Order;
use DuncanMcClean\Cargo\Orders\LineItem;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use NineteenNinetyFour\GiftVouchers\Delivery;
use NineteenNinetyFour\GiftVouchers\Details;
use NineteenNinetyFour\GiftVouchers\Events\VoucherUpdated;
use NineteenNinetyFour\GiftVouchers\Ledger;
use NineteenNinetyFour\GiftVouchers\Money;
use NineteenNinetyFour\GiftVouchers\Timeline\GiftVoucherEvent;
use NineteenNinetyFour\GiftVouchers\Transaction;
use NineteenNinetyFour\GiftVouchers\TransactionType;
use NineteenNinetyFour\GiftVouchers\Voucher;
use NineteenNinetyFour\GiftVouchers\VoucherException;

/**
 * What happens to vouchers as Cargo orders move along: spending the
 * vouchers used on an order, making the vouchers bought on it, and putting
 * balances back when an order fails, is cancelled or is refunded.
 */
class OrderVouchers
{
    public function __construct(
        protected Ledger $ledger,
        protected Products $products,
        protected Delivery $delivery,
    ) {}

    /**
     * Spend the vouchers used on an order, all or nothing.
     *
     * @throws VoucherException if a voucher no longer covers its part.
     */
    public function redeem(Order $order): void
    {
        $breakdown = collect((array) $order->get(CartVouchers::BREAKDOWN, []))
            ->filter(fn ($line) => is_array($line) && (int) ($line['amount'] ?? 0) > 0)
            ->map(fn ($line) => ['id' => (string) $line['id'], 'amount' => (int) $line['amount']])
            ->values()
            ->all();

        if ($breakdown === []) {
            return;
        }

        $transactions = $this->ledger->redeemForOrder($order->id(), $breakdown);

        foreach ($transactions as $transaction) {
            $this->timeline($order, 'redeemed', $transaction);
        }
    }

    /**
     * Make the vouchers bought on an order, one per unit of each voucher
     * line item, and email them (or keep them for their send date). Making
     * them again for the same order changes nothing.
     *
     * @return Collection<int, Voucher> The vouchers made this time.
     */
    public function issue(Order $order): Collection
    {
        $currency = Money::currency($order->site());
        $made = collect();

        $order->lineItems()
            ->filter(fn (LineItem $lineItem) => $this->products->isVoucherLine($lineItem))
            ->each(function (LineItem $lineItem) use ($order, $currency, $made) {
                $amount = (int) $lineItem->unitPrice();

                if ($amount <= 0) {
                    return;
                }

                $details = Details::from([
                    'recipient_name' => $lineItem->get(Products::RECIPIENT_NAME),
                    'recipient_email' => $lineItem->get(Products::RECIPIENT_EMAIL),
                    'sender_name' => $lineItem->get(Products::SENDER_NAME),
                    'message' => $lineItem->get(Products::MESSAGE),
                    'send_on' => $lineItem->get(Products::SEND_ON),
                ]);

                $months = $this->products->expiryMonths($lineItem->product());

                for ($sequence = 1; $sequence <= max(1, (int) $lineItem->quantity()); $sequence++) {
                    $attributes = [
                        'order_id' => $order->id(),
                        'line_item_id' => $lineItem->id(),
                        'sequence' => $sequence,
                    ];

                    if (Voucher::query()->where($attributes)->exists()) {
                        continue;
                    }

                    try {
                        $made->push($this->ledger->issue($amount, $currency, array_merge($attributes, $details->toAttributes(), [
                            'site' => $order->site()?->handle(),
                            'product_id' => $lineItem->product()?->id(),
                            'expires_at' => $months ? now()->addMonths($months)->endOfDay() : null,
                        ])));
                    } catch (UniqueConstraintViolationException) {
                        // Made at the same moment by another request.
                    }
                }
            });

        if ($made->isNotEmpty()) {
            $this->timeline($order, 'issued', null, ['Count' => $made->count()]);
        }

        $made->reject(fn (Voucher $voucher) => $voucher->isScheduled())
            ->each(fn (Voucher $voucher) => $this->delivery->sendSafely($voucher));

        return $made;
    }

    /**
     * The vouchers bought on an order.
     *
     * @return Collection<int, Voucher>
     */
    public function issuedFor(Order $order): Collection
    {
        return Voucher::query()->where('order_id', $order->id())->orderBy('line_item_id')->orderBy('sequence')->get();
    }

    /**
     * Vouchers spent on an order, with what each paid.
     *
     * @return Collection<int, array{voucher: Voucher, transactions: Collection<int, Transaction>}>
     */
    public function usedOn(Order $order): Collection
    {
        $transactions = Transaction::query()
            ->where('order_id', $order->id())
            ->whereIn('type', [TransactionType::Redeemed, TransactionType::Restored, TransactionType::Refunded])
            ->with('voucher')
            ->orderBy('id')
            ->get();

        return $transactions->groupBy('voucher_id')->map(fn (Collection $group) => [
            'voucher' => $group->first()->voucher,
            'transactions' => $group->values(),
        ])->values();
    }

    /**
     * Put back what an order spent from vouchers (it failed, or was cancelled).
     */
    public function restore(Order $order, string $reason): void
    {
        foreach ($this->ledger->restoreForOrder($order->id(), $reason) as $transaction) {
            $this->timeline($order, 'restored', $transaction);
        }
    }

    /**
     * Refund part or all of what an order paid by voucher back onto the vouchers.
     *
     * @return Collection<int, Transaction>
     */
    public function refund(Order $order, int $amount, ?string $userId = null, ?string $note = null): Collection
    {
        $transactions = $this->ledger->refundForOrder($order->id(), $amount, $userId, $note);

        foreach ($transactions as $transaction) {
            $this->timeline($order, 'refunded', $transaction);
        }

        return $transactions;
    }

    /**
     * Disable the vouchers bought on an order (refunded or cancelled).
     */
    public function disableIssued(Order $order, string $reason): int
    {
        $vouchers = Voucher::query()->where('order_id', $order->id())->whereNull('disabled_at')->get();

        foreach ($vouchers as $voucher) {
            $voucher->disabled_at = now();
            $voucher->notes = trim(($voucher->notes ? $voucher->notes."\n" : '').$reason);
            $voucher->save();

            VoucherUpdated::dispatch($voucher);
        }

        if ($vouchers->isNotEmpty()) {
            $this->timeline($order, 'disabled', null, ['Count' => $vouchers->count()]);
        }

        return $vouchers->count();
    }

    /**
     * Note something on the order's timeline (Cargo's history of the order).
     */
    public function timeline(Order $order, string $action, ?Transaction $transaction = null, array $metadata = []): void
    {
        if ($transaction) {
            $voucher = $transaction->voucher ?? Voucher::query()->find($transaction->voucher_id);

            $metadata = array_merge([
                'Amount' => $voucher ? $voucher->formatted(abs($transaction->amount)) : (string) abs($transaction->amount),
                'Voucher' => $voucher?->maskedCode(),
            ], $metadata);
        }

        $order->appendTimelineEvent(GiftVoucherEvent::class, array_merge(['Action' => $action], $metadata))->saveQuietly();
    }
}
