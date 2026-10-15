<?php

namespace NineteenNinetyFour\GiftVouchers;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use NineteenNinetyFour\GiftVouchers\Events\VoucherSent;
use NineteenNinetyFour\GiftVouchers\Mail\VoucherMail;
use Throwable;

/**
 * Emailing vouchers: to their recipient, or to the customer if there's no
 * recipient. Straight away, or on the date the customer chose.
 */
class Delivery
{
    /**
     * Email a voucher now.
     *
     * @throws VoucherException if there's nobody to send it to.
     */
    public function send(Voucher $voucher): void
    {
        $address = $voucher->emailAddress() ?? throw VoucherException::because('no_email');

        $mail = Mail::to($address, $voucher->recipient_email ? $voucher->recipient_name : null);

        if ($bcc = config('gift-vouchers.email.bcc')) {
            $mail->bcc($bcc);
        }

        $message = new VoucherMail($voucher);

        if ($queue = config('gift-vouchers.email.queue')) {
            $mail->queue($message->onQueue(is_string($queue) ? $queue : null));
        } else {
            $mail->send($message);
        }

        $voucher->forceFill(['sent_at' => now()])->save();

        VoucherSent::dispatch($voucher);
    }

    /**
     * Email a voucher if emails are on, without letting a mail problem stop
     * the order going through. Problems are logged and reported.
     */
    public function sendSafely(Voucher $voucher): bool
    {
        if (! config('gift-vouchers.email.enabled', true)) {
            return false;
        }

        try {
            $this->send($voucher);

            return true;
        } catch (Throwable $e) {
            Log::warning("Gift voucher {$voucher->id} couldn't be emailed: {$e->getMessage()}");
            report($e);

            return false;
        }
    }

    /**
     * Vouchers whose send date has come and haven't been sent.
     *
     * @return Collection<int, Voucher>
     */
    public function due(): Collection
    {
        return Voucher::query()
            ->whereNull('sent_at')
            ->whereNotNull('send_on')
            ->whereDate('send_on', '<=', now()->toDateString())
            ->whereNull('disabled_at')
            ->orderBy('send_on')
            ->get();
    }

    /**
     * Email every voucher that's due. Returns how many were sent.
     */
    public function sendDue(): int
    {
        return $this->due()->filter(fn (Voucher $voucher) => $this->sendSafely($voucher))->count();
    }
}
