<?php

namespace NineteenNinetyFour\GiftVouchers;

/**
 * Vouchers as the email, the printable page, the PDF, the tags and the
 * Control Panel show them.
 *
 * Text from shoppers (names, messages) is passed as it was typed; the
 * Blade views escape it with {{ }}, and arrays for Antlers are escaped
 * here, as Antlers doesn't escape.
 */
class Presenter
{
    /**
     * The :placeholders in the settings' wording.
     *
     * @return array<string, string|null>
     */
    public function replacements(Voucher $voucher): array
    {
        $site = $voucher->siteObject();

        return [
            'amount' => $voucher->formatted($voucher->initial_amount),
            'balance' => $voucher->formatted($voucher->balance),
            'recipient_name' => $voucher->recipient_name ?? '',
            'sender_name' => $voucher->sender_name ?? ($site?->name() ?? config('app.name')),
            'expires' => $voucher->expires_at?->toFormattedDayDateString() ?? '',
            'site' => $site?->name() ?? config('app.name'),
        ];
    }

    /**
     * What the voucher views (emails, print, PDF) are drawn with.
     *
     * @return array<string, mixed>
     */
    public function forDocument(Voucher $voucher, bool $forEmail = false): array
    {
        $replace = $this->replacements($voucher);
        $site = $voucher->siteObject();
        $logo = Settings::logo();
        $design = app(Designs::class)->find($voucher->design);

        return [
            'voucher' => $voucher,
            'title' => $design->title ?? Settings::text('voucher_title', $replace),
            'heading' => Settings::text('email_heading', $replace),
            'intro' => Settings::text('email_intro', $replace),
            'howTo' => Settings::text('how_to_redeem', $replace),
            'terms' => Settings::get('terms') ? Settings::text('terms', $replace) : null,
            'amount' => $replace['amount'],
            'balance' => $replace['balance'],
            'code' => $voucher->code() ?? $voucher->maskedCode(),
            'recipientName' => $voucher->recipient_name,
            'senderName' => $voucher->sender_name,
            'giftMessage' => $voucher->message,
            'expires' => $replace['expires'] ?: null,
            'design' => $design,
            'artwork' => $forEmail ? null : $design->artwork($voucher->id),
            'artworkKind' => $design->kind(),
            'headerImage' => $forEmail && $design->kind() === Design::IMAGE ? $design->image->absoluteUrl() : null,
            'emblemPng' => $forEmail ? $design->emblemPng() : null,
            'designTitle' => $design->title,
            'voucherTitle' => Settings::text('voucher_title', $replace),
            'colour' => $design->colour,
            'onColour' => $design->onColour(),
            'logo' => $forEmail ? $logo?->absoluteUrl() : Settings::logoDataUri(),
            'logoAlt' => $replace['site'],
            'siteName' => $replace['site'],
            'siteUrl' => $site?->absoluteUrl() ?? url('/'),
            'printUrl' => $voucher->printUrl(),
            'pdfUrl' => $voucher->pdfUrl(),
            'forPdf' => false,
            'forEmail' => $forEmail,
        ];
    }

    /**
     * A voucher for Antlers (the balance checker): escaped, as Antlers
     * prints values as they are.
     *
     * @return array<string, mixed>
     */
    public function forTag(Voucher $voucher, bool $escape = true): array
    {
        $status = $voucher->status();
        $e = fn (?string $value) => $value === null ? null : ($escape ? e($value) : $value);

        return [
            'code' => $e($voucher->maskedCode()),
            'code_hint' => $e($voucher->code_hint),
            'currency' => $voucher->currency,
            'balance' => $voucher->balance,
            'balance_formatted' => $e($voucher->formatted($voucher->balance)),
            'initial_amount' => $voucher->initial_amount,
            'initial_amount_formatted' => $e($voucher->formatted($voucher->initial_amount)),
            'status' => $status->value,
            'status_label' => $e($status->label()),
            'is_usable' => $voucher->isUsable(),
            'is_expired' => $status === VoucherStatus::Expired,
            'expires_at' => $voucher->expires_at?->toIso8601String(),
            'expires_formatted' => $e($voucher->expires_at?->toFormattedDayDateString()),
        ];
    }

    /**
     * A voucher as a Control Panel table row.
     *
     * @return array<string, mixed>
     */
    public function row(Voucher $voucher): array
    {
        $status = $voucher->status();

        return [
            'id' => $voucher->id,
            'code' => $voucher->maskedCode(),
            'recipient_name' => $voucher->recipient_name,
            'recipient_email' => $voucher->recipient_email,
            'currency' => $voucher->currency,
            'initial_amount' => $voucher->formatted($voucher->initial_amount),
            'balance' => $voucher->formatted($voucher->balance),
            'balance_minor' => $voucher->balance,
            'status' => $status->value,
            'status_label' => $status->label(),
            'scheduled' => $voucher->isScheduled(),
            'source' => $voucher->order_id ? 'order' : 'manual',
            'created_at' => $voucher->created_at?->toIso8601String(),
            'created_ago' => $voucher->created_at?->diffForHumans(),
            'expires_at' => $voucher->expires_at?->toFormattedDayDateString(),
            'url' => $voucher->editUrl(),
        ];
    }
}
