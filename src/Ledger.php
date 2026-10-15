<?php

namespace NineteenNinetyFour\GiftVouchers;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use NineteenNinetyFour\GiftVouchers\Events\VoucherAdjusted;
use NineteenNinetyFour\GiftVouchers\Events\VoucherCredited;
use NineteenNinetyFour\GiftVouchers\Events\VoucherIssued;
use NineteenNinetyFour\GiftVouchers\Events\VoucherRedeemed;

/**
 * Every change to a voucher's balance goes through here.
 *
 * - Spending is one conditional UPDATE ("take £x off where the balance is
 *   at least £x"), so a balance can't go below zero and two checkouts
 *   can't both spend the same money, on any database.
 * - Each change is written to the voucher's history in the same database
 *   transaction, with the balance after it.
 * - Events are dispatched once the transaction has committed.
 */
class Ledger
{
    /**
     * Make a voucher with a balance.
     *
     * @param  array<string, mixed>  $attributes  Recipient, expiry, order and so on.
     * @param  string|null  $code  A code chosen in the Control Panel, or null for a random one.
     */
    public function issue(int $amount, string $currency, array $attributes = [], ?string $code = null, ?string $userId = null, ?string $note = null): Voucher
    {
        if ($amount <= 0 || $amount > Money::MAX) {
            throw VoucherException::because('invalid_amount');
        }

        if ($code !== null) {
            if (! Codes::isValidCustom($code)) {
                throw VoucherException::because('invalid_custom_code', ['min' => Codes::MIN_CUSTOM, 'max' => Codes::MAX_CUSTOM]);
            }

            if (Voucher::query()->where('code_hash', Codes::hash($code))->exists()) {
                throw VoucherException::because('duplicate_code');
            }
        }

        // A random code is all but certain to be new; if one ever isn't,
        // the unique index catches it and another is made.
        for ($attempt = 1; ; $attempt++) {
            $plain = $code !== null ? Codes::format($code) : Codes::generate();

            try {
                $voucher = $this->transaction(function () use ($amount, $currency, $attributes, $plain, $userId, $note) {
                    $voucher = new Voucher(array_merge($attributes, [
                        'code_hash' => Codes::hash($plain),
                        'code_encrypted' => Crypt::encryptString(Codes::normalize($plain)),
                        'code_hint' => Codes::hint($plain),
                        'currency' => strtoupper($currency),
                        'initial_amount' => $amount,
                    ]));
                    $voucher->balance = $amount;
                    $voucher->save();

                    $this->record($voucher, TransactionType::Issued, $amount, $voucher->order_id, $userId, $note);

                    return $voucher;
                });

                break;
            } catch (UniqueConstraintViolationException $e) {
                if ($code !== null || $attempt >= 5 || $this->isOrderDuplicate($attributes)) {
                    throw $e;
                }
            }
        }

        VoucherIssued::dispatch($voucher);

        return $voucher;
    }

    /**
     * Spend from a voucher. Throws if it can't be spent or doesn't have enough.
     */
    public function debit(Voucher $voucher, int $amount, ?string $orderId = null, TransactionType $type = TransactionType::Redeemed, ?string $userId = null, ?string $note = null): Transaction
    {
        $transaction = $this->transaction(fn () => $this->take($voucher, $amount, $orderId, $type, $userId, $note, requireUsable: $type === TransactionType::Redeemed));

        $this->dispatchFor($voucher, $transaction);

        return $transaction;
    }

    /**
     * Put money back on a voucher: a refund, a restored order, or an adjustment.
     */
    public function credit(Voucher $voucher, int $amount, TransactionType $type, ?string $orderId = null, ?string $userId = null, ?string $note = null): Transaction
    {
        $transaction = $this->transaction(fn () => $this->give($voucher, $amount, $type, $orderId, $userId, $note));

        $this->dispatchFor($voucher, $transaction);

        return $transaction;
    }

    /**
     * Change a balance by hand: up with a positive amount, down with a
     * negative one (but never below zero).
     */
    public function adjust(Voucher $voucher, int $change, ?string $userId = null, ?string $note = null): Transaction
    {
        if ($change === 0) {
            throw VoucherException::because('invalid_amount');
        }

        return $change > 0
            ? $this->credit($voucher, $change, TransactionType::Adjusted, null, $userId, $note)
            : $this->debit($voucher, -$change, null, TransactionType::Adjusted, $userId, $note);
    }

    /**
     * Spend from vouchers for an order, all or nothing: if any voucher can't
     * cover its part, nothing is spent. Running it again for the same order
     * does nothing.
     *
     * @param  array<int, array{id: string, amount: int}>  $breakdown
     * @return Collection<int, Transaction>
     */
    public function redeemForOrder(string $orderId, array $breakdown): Collection
    {
        $done = $this->transaction(function () use ($orderId, $breakdown) {
            if (Transaction::query()->where('order_id', $orderId)->where('type', TransactionType::Redeemed)->exists()) {
                return collect();
            }

            return collect($breakdown)
                ->filter(fn ($line) => (int) ($line['amount'] ?? 0) > 0)
                ->map(function ($line) use ($orderId) {
                    $voucher = Voucher::query()->find($line['id'] ?? null);

                    if (! $voucher) {
                        throw VoucherException::because('not_found');
                    }

                    return [$voucher, $this->take($voucher, (int) $line['amount'], $orderId, TransactionType::Redeemed, null, null, requireUsable: true)];
                })
                ->values();
        });

        return $done->map(function ($pair) {
            $this->dispatchFor(...$pair);

            return $pair[1];
        });
    }

    /**
     * What's been spent on an order from each voucher and not yet put back.
     *
     * @return Collection<string, int> Voucher ID => amount, in the order the vouchers were spent.
     */
    public function outstandingForOrder(string $orderId): Collection
    {
        return Transaction::query()
            ->where('order_id', $orderId)
            ->whereIn('type', [TransactionType::Redeemed, TransactionType::Restored, TransactionType::Refunded])
            ->orderBy('id')
            ->get()
            ->groupBy('voucher_id')
            ->map(fn (Collection $transactions) => -$transactions->sum('amount'))
            ->filter(fn (int $amount) => $amount > 0);
    }

    /**
     * Put back everything spent from vouchers on an order: when it fails at
     * checkout, or is cancelled. Running it again puts nothing more back.
     *
     * @return Collection<int, Transaction>
     */
    public function restoreForOrder(string $orderId, ?string $note = null): Collection
    {
        return $this->returnToVouchers($orderId, null, TransactionType::Restored, null, $note);
    }

    /**
     * Refund part or all of what was paid by voucher on an order, back onto
     * the vouchers, the most recently spent first.
     *
     * @return Collection<int, Transaction>
     */
    public function refundForOrder(string $orderId, int $amount, ?string $userId = null, ?string $note = null): Collection
    {
        if ($amount <= 0) {
            throw VoucherException::because('invalid_amount');
        }

        return $this->returnToVouchers($orderId, $amount, TransactionType::Refunded, $userId, $note);
    }

    /**
     * @return Collection<int, Transaction>
     */
    protected function returnToVouchers(string $orderId, ?int $amount, TransactionType $type, ?string $userId, ?string $note): Collection
    {
        $done = $this->transaction(function () use ($orderId, $amount, $type, $userId, $note) {
            // Lock the vouchers first, so two refunds of the same order
            // can't both see the same amount still to put back.
            $ids = Transaction::query()->where('order_id', $orderId)->distinct()->pluck('voucher_id');
            $vouchers = Voucher::query()->whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');

            $outstanding = $this->outstandingForOrder($orderId);

            if ($amount !== null && $amount > $outstanding->sum()) {
                throw VoucherException::because('refund_too_much', ['amount' => Money::format($outstanding->sum(), $vouchers->first()?->currency ?? 'GBP')]);
            }

            $left = $amount ?? PHP_INT_MAX;

            return $outstanding->reverse()->map(function (int $owed, string $voucherId) use (&$left, $vouchers, $type, $orderId, $userId, $note) {
                $give = min($owed, $left);

                if ($give <= 0) {
                    return null;
                }

                $left -= $give;
                $voucher = $vouchers[$voucherId];

                return [$voucher, $this->give($voucher, $give, $type, $orderId, $userId, $note)];
            })->filter()->values();
        });

        return $done->map(function ($pair) {
            $this->dispatchFor(...$pair);

            return $pair[1];
        });
    }

    /**
     * The conditional UPDATE that makes spending safe. Must run inside a
     * database transaction.
     */
    protected function take(Voucher $voucher, int $amount, ?string $orderId, TransactionType $type, ?string $userId, ?string $note, bool $requireUsable): Transaction
    {
        if ($amount <= 0 || $amount > Money::MAX) {
            throw VoucherException::because('invalid_amount');
        }

        $query = Voucher::query()->whereKey($voucher->getKey())->where('balance', '>=', $amount);

        if ($requireUsable) {
            $query->whereNull('disabled_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
        }

        if ($query->toBase()->update(['balance' => DB::raw('balance - '.(int) $amount), 'updated_at' => now()]) !== 1) {
            $fresh = $voucher->fresh();

            throw match (true) {
                $fresh === null => VoucherException::because('not_found'),
                $requireUsable && $fresh->disabled_at !== null => VoucherException::because('disabled'),
                $requireUsable && $fresh->isExpired() => VoucherException::because('expired', ['date' => $fresh->expires_at->toFormattedDayDateString()]),
                default => VoucherException::because('insufficient', ['balance' => $fresh->formatted($fresh->balance)]),
            };
        }

        return $this->record($voucher, $type, -$amount, $orderId, $userId, $note);
    }

    protected function give(Voucher $voucher, int $amount, TransactionType $type, ?string $orderId, ?string $userId, ?string $note): Transaction
    {
        if ($amount <= 0 || $amount > Money::MAX) {
            throw VoucherException::because('invalid_amount');
        }

        Voucher::query()->whereKey($voucher->getKey())->toBase()->update(['balance' => DB::raw('balance + '.(int) $amount), 'updated_at' => now()]);

        return $this->record($voucher, $type, $amount, $orderId, $userId, $note);
    }

    /**
     * Write a change to the history, with the balance as it now stands, and
     * bring the model up to date.
     */
    protected function record(Voucher $voucher, TransactionType $type, int $amount, ?string $orderId, ?string $userId, ?string $note): Transaction
    {
        $balance = (int) Voucher::query()->whereKey($voucher->getKey())->value('balance');

        $voucher->forceFill(['balance' => $balance])->syncOriginalAttribute('balance');

        return Transaction::query()->create([
            'voucher_id' => $voucher->getKey(),
            'type' => $type,
            'amount' => $amount,
            'balance_after' => $balance,
            'order_id' => $orderId,
            'user_id' => $userId,
            'note' => $note !== null ? mb_substr($note, 0, 500) : null,
        ]);
    }

    protected function dispatchFor(Voucher $voucher, Transaction $transaction): void
    {
        match ($transaction->type) {
            TransactionType::Redeemed => VoucherRedeemed::dispatch($voucher, $transaction),
            TransactionType::Adjusted => VoucherAdjusted::dispatch($voucher, $transaction),
            TransactionType::Restored, TransactionType::Refunded => VoucherCredited::dispatch($voucher, $transaction),
            default => null,
        };
    }

    protected function isOrderDuplicate(array $attributes): bool
    {
        return isset($attributes['order_id'], $attributes['line_item_id'], $attributes['sequence'])
            && Voucher::query()
                ->where('order_id', $attributes['order_id'])
                ->where('line_item_id', $attributes['line_item_id'])
                ->where('sequence', $attributes['sequence'])
                ->exists();
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function transaction(Closure $callback): mixed
    {
        return $this->connection()->transaction($callback, 3);
    }

    protected function connection(): ConnectionInterface
    {
        return DB::connection(config('gift-vouchers.database.connection'));
    }
}
