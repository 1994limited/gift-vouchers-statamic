<?php

namespace NineteenNinetyFour\GiftVouchers\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use NineteenNinetyFour\GiftVouchers\Pdf;
use NineteenNinetyFour\GiftVouchers\Presenter;
use NineteenNinetyFour\GiftVouchers\Settings;
use NineteenNinetyFour\GiftVouchers\Voucher;

/**
 * The voucher, emailed to its recipient, with the PDF attached. The wording
 * comes from the addon's settings; the views can be published and edited
 * (php artisan vendor:publish --tag=gift-vouchers-views).
 */
class VoucherMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Voucher $voucher) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: Settings::text('email_subject', $this->replacements()),
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'gift-vouchers::emails.voucher',
            text: 'gift-vouchers::emails.voucher-text',
            with: app(Presenter::class)->forDocument($this->voucher, forEmail: true),
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        if (! config('gift-vouchers.email.attach_pdf', true)) {
            return [];
        }

        return [
            Attachment::fromData(fn () => app(Pdf::class)->render($this->voucher), app(Pdf::class)->filename($this->voucher))
                ->withMime('application/pdf'),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    protected function replacements(): array
    {
        return app(Presenter::class)->replacements($this->voucher);
    }
}
