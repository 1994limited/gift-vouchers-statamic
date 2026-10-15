<?php

namespace NineteenNinetyFour\GiftVouchers;

use Carbon\CarbonInterface;
use DuncanMcClean\Cargo\Contracts\Cart\Cart;
use DuncanMcClean\Cargo\Contracts\Orders\Order;
use DuncanMcClean\Cargo\Facades\Cart as CartFacade;
use Illuminate\Support\Collection;
use NineteenNinetyFour\GiftVouchers\Cargo\CartVouchers;
use NineteenNinetyFour\GiftVouchers\Cargo\OrderVouchers;
use NineteenNinetyFour\GiftVouchers\Events\VoucherUpdated;
use Statamic\Facades\User;

/**
 * The PHP API: app(GiftVouchers::class)->create(2500, 'GBP', [...]).
 *
 * Amounts are whole numbers of the currency's minor unit (pence).
 */
class GiftVouchers
{
    public function __construct(
        protected Ledger $ledger,
        protected CartVouchers $carts,
        protected OrderVouchers $orders,
        protected Delivery $delivery,
    ) {}

    /**
     * Make a voucher, as the Control Panel's "Create voucher" does.
     *
     * @param  array{recipient_name?: string, recipient_email?: string, sender_name?: string, message?: string, send_on?: string}  $details
     */
    public function create(
        int $amount,
        ?string $currency = null,
        array $details = [],
        ?string $code = null,
        ?CarbonInterface $expiresAt = null,
        ?string $site = null,
        ?string $note = null,
        bool $send = false,
    ): Voucher {
        $voucher = $this->ledger->issue(
            amount: $amount,
            currency: $currency ?? Money::currency(),
            attributes: array_merge(Details::from($details)->toAttributes(), [
                'expires_at' => $expiresAt,
                'site' => $site,
                'created_by' => User::current()?->id(),
                'notes' => $note,
            ]),
            code: $code,
            userId: User::current()?->id(),
            note: $note,
        );

        if ($send && ! $voucher->isScheduled()) {
            $this->delivery->send($voucher);
        }

        return $voucher;
    }

    public function find(string $id): ?Voucher
    {
        return Voucher::query()->find($id);
    }

    public function findByCode(string $code): ?Voucher
    {
        return Voucher::findByCode($code);
    }

    /**
     * What's left on a voucher, or null if there's no voucher with that code.
     */
    public function balance(string $code): ?int
    {
        return $this->findByCode($code)?->balance;
    }

    /**
     * Raise (positive) or lower (negative) a balance by hand.
     */
    public function adjust(Voucher $voucher, int $change, ?string $note = null): Transaction
    {
        return $this->ledger->adjust($voucher, $change, User::current()?->id(), $note);
    }

    public function disable(Voucher $voucher): Voucher
    {
        $voucher->forceFill(['disabled_at' => now()])->save();

        VoucherUpdated::dispatch($voucher);

        return $voucher;
    }

    public function enable(Voucher $voucher): Voucher
    {
        $voucher->forceFill(['disabled_at' => null])->save();

        VoucherUpdated::dispatch($voucher);

        return $voucher;
    }

    /**
     * Email a voucher now (again, if it's been sent before).
     */
    public function send(Voucher $voucher): void
    {
        $this->delivery->send($voucher);
    }

    /**
     * Use a voucher on a cart (the current cart if none is given).
     *
     * @throws VoucherException
     */
    public function applyToCart(string $code, ?Cart $cart = null): Voucher
    {
        return $this->carts->add($cart ?? CartFacade::current(), $code);
    }

    public function removeFromCart(Voucher|string $voucher, ?Cart $cart = null): bool
    {
        return $this->carts->remove($cart ?? CartFacade::current(), $voucher instanceof Voucher ? $voucher->id : $voucher);
    }

    /**
     * Refund part or all of what was paid by voucher on an order, back onto the vouchers.
     *
     * @return Collection<int, Transaction>
     */
    public function refundOrder(Order $order, int $amount, ?string $note = null): Collection
    {
        return $this->orders->refund($order, $amount, User::current()?->id(), $note);
    }

    /**
     * The vouchers bought on an order.
     *
     * @return Collection<int, Voucher>
     */
    public function issuedFor(Order $order): Collection
    {
        return $this->orders->issuedFor($order);
    }

    public function ledger(): Ledger
    {
        return $this->ledger;
    }
}
