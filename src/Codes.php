<?php

namespace NineteenNinetyFour\GiftVouchers;

/**
 * Voucher codes: making them, and reading what a customer typed.
 *
 * Codes use letters and numbers that can't be mistaken for each other
 * (no 0/O, 1/I/L or U), so a code read out over the phone or copied from
 * a printed card is typed correctly. Spaces, dashes and case don't matter.
 */
class Codes
{
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTVWXYZ23456789';

    /** Codes typed in the Control Panel: letters and numbers only. */
    public const MIN_CUSTOM = 6;

    public const MAX_CUSTOM = 32;

    /**
     * A new random code, formatted for people: "GIFT-7KQ2-…".
     */
    public static function generate(): string
    {
        $length = max(8, min(32, (int) config('gift-vouchers.codes.length', 16)));
        $alphabet = self::ALPHABET;
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return self::format(self::prefix().$code);
    }

    /**
     * What's left of a typed code once spaces, dashes and case are taken out.
     */
    public static function normalize(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    /**
     * How a code is found: a hash of its normalised form. Codes have far too
     * many possibilities to be worked out from the hash.
     */
    public static function hash(string $code): string
    {
        return hash('sha256', self::normalize($code));
    }

    /**
     * The hashes a typed code could match: as typed, and with the prefix in
     * front, for customers who leave the prefix off.
     *
     * @return array<int, string>
     */
    public static function candidates(string $code): array
    {
        $normalized = self::normalize($code);

        if ($normalized === '' || strlen($normalized) > 64) {
            return [];
        }

        $prefix = self::prefix();

        return array_values(array_unique(array_filter([
            self::hash($normalized),
            $prefix !== '' && ! str_starts_with($normalized, $prefix) ? self::hash($prefix.$normalized) : null,
        ])));
    }

    /**
     * The last four characters, to tell vouchers apart without showing the code.
     */
    public static function hint(string $code): string
    {
        return substr(self::normalize($code), -4);
    }

    /**
     * A normalised code in groups: ABCD-EFGH-…, with the prefix on its own.
     */
    public static function format(string $code): string
    {
        $code = self::normalize($code);
        $prefix = self::prefix();
        $group = max(0, (int) config('gift-vouchers.codes.group', 4));

        $head = '';

        if ($prefix !== '' && str_starts_with($code, $prefix) && strlen($code) > strlen($prefix)) {
            $head = $prefix.'-';
            $code = substr($code, strlen($prefix));
        }

        return $head.($group > 0 ? implode('-', str_split($code, $group)) : $code);
    }

    /**
     * Whether a code typed in the Control Panel can be used.
     */
    public static function isValidCustom(string $code): bool
    {
        $normalized = self::normalize($code);

        return strlen($normalized) >= self::MIN_CUSTOM
            && strlen($normalized) <= self::MAX_CUSTOM
            && preg_match('/^[A-Za-z0-9 \-]+$/', $code) === 1;
    }

    protected static function prefix(): string
    {
        return self::normalize((string) config('gift-vouchers.codes.prefix', ''));
    }
}
