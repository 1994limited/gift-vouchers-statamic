<?php

namespace NineteenNinetyFour\GiftVouchers;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Who a voucher is for and what it says: cleaned up from whatever was sent.
 *
 * Shoppers type these, and Cargo lets a line item's data be edited, so
 * they're read the same careful way when added to the cart and again when
 * the voucher is made: plain text, limited in length, a real email address,
 * a send date from today to a year ahead.
 */
class Details
{
    public const NAME_LIMIT = 100;

    public const MESSAGE_LIMIT = 500;

    public function __construct(
        public readonly ?string $recipientName = null,
        public readonly ?string $recipientEmail = null,
        public readonly ?string $senderName = null,
        public readonly ?string $message = null,
        public readonly ?CarbonImmutable $sendOn = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input  recipient_name, recipient_email, sender_name, message, send_on
     */
    public static function from(array $input): self
    {
        return new self(
            recipientName: self::line($input['recipient_name'] ?? null, self::NAME_LIMIT),
            recipientEmail: self::email($input['recipient_email'] ?? null),
            senderName: self::line($input['sender_name'] ?? null, self::NAME_LIMIT),
            message: self::text($input['message'] ?? null, self::MESSAGE_LIMIT),
            sendOn: self::date($input['send_on'] ?? null),
        );
    }

    /**
     * Plain text on one line, with no control characters.
     */
    public static function line(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim((string) preg_replace('/\s+/u', ' ', self::plain($value)));

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    /**
     * Plain text that keeps its line breaks (at most two in a row).
     */
    public static function text(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = str_replace(["\r\n", "\r"], "\n", self::plain($value));
        $value = (string) preg_replace('/[^\S\n]+/u', ' ', $value);
        $value = trim((string) preg_replace('/\n{3,}/', "\n\n", $value));

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    public static function email(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' && strlen($value) <= 254 && filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }

    /**
     * A send date: today or later, up to a year ahead. Today (or nothing)
     * means "send when the order's paid", kept as null.
     */
    public static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }

        if (! $date || $date->format('Y-m-d') !== $value) {
            return null;
        }

        $today = CarbonImmutable::today();

        return $date->greaterThan($today) && $date->lessThanOrEqualTo($today->addYear()) ? $date : null;
    }

    /**
     * Whether a typed email address was given but isn't one.
     */
    public static function isBadEmail(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '' && self::email($value) === null;
    }

    /**
     * Whether a typed date was given but can't be used.
     */
    public static function isBadDate(mixed $value): bool
    {
        if (! is_string($value) || trim($value) === '') {
            return false;
        }

        return self::date($value) === null && $value !== CarbonImmutable::today()->format('Y-m-d');
    }

    /**
     * As voucher columns.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'recipient_name' => $this->recipientName,
            'recipient_email' => $this->recipientEmail,
            'sender_name' => $this->senderName,
            'message' => $this->message,
            'send_on' => $this->sendOn?->toDateString(),
        ];
    }

    protected static function plain(string $value): string
    {
        $value = mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'UTF-8');

        // Control characters out (but not tabs or line breaks). Markup is
        // left as typed ("love you <3") and escaped wherever it's shown.
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
    }
}
