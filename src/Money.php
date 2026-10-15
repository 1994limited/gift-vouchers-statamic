<?php

namespace NineteenNinetyFour\GiftVouchers;

use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Formatter\IntlMoneyFormatter;
use Money\Money as MoneyPhp;
use NumberFormatter;
use Statamic\Facades\Site as SiteFacade;
use Statamic\Sites\Site;
use Throwable;

/**
 * Amounts are whole numbers of the currency's minor unit (pence), as Cargo
 * keeps prices. This reads amounts people type, and formats amounts to show.
 */
class Money
{
    /** The most a single amount can be: 10 million in major units. */
    public const MAX = 1_000_000_000;

    /**
     * A site's currency code, as Cargo reads it: the site's "currency" attribute.
     */
    public static function currency(?Site $site = null): string
    {
        $site ??= SiteFacade::current();

        return strtoupper((string) ($site?->attribute('currency') ?: 'GBP'));
    }

    /**
     * How many decimal places a currency has: 2 for GBP, 0 for JPY.
     */
    public static function decimals(string $currency): int
    {
        try {
            return (new ISOCurrencies)->subunitFor(new Currency(strtoupper($currency)));
        } catch (Throwable) {
            return 2;
        }
    }

    /**
     * An amount typed by a person ("25", "25.5", "£1,000.00", or "25,50" where
     * the comma is the decimal separator) in minor units, or null if it isn't
     * one. Works with strings, never floats, so nothing is lost to rounding.
     *
     * Separators are read for the locale: with both "." and ",", the last is
     * the decimal point; with one, it's the locale's decimal separator, or
     * thousands only in strict groups of three ("1,000"). Anything else that
     * could be read two ways ("10,50" on an English site) is refused rather
     * than guessed.
     */
    public static function parse(mixed $input, string $currency, ?string $locale = null): ?int
    {
        if (is_int($input)) {
            $input = (string) $input;
        }

        if (! is_string($input) || strlen($input) > 40) {
            return null;
        }

        $value = self::withoutCurrency(trim($input), $currency, $locale);

        // Only digits and separators are left in an amount. Anything else
        // ("1e3", "25oops50") isn't one, rather than being quietly dropped.
        if ($value === null || ! preg_match('/^[\d.,]+$/', $value)) {
            return null;
        }

        $decimals = self::decimals($currency);

        // "25.00", as toDecimal() writes amounts for inputs, means the same in
        // every locale. Anything else is read with the locale's separators.
        $canonical = $decimals > 0 && preg_match('/^\d+\.\d{'.$decimals.'}$/', $value) === 1;
        $value = $canonical ? $value : self::normaliseSeparators($value, self::decimalSeparator($locale));

        if ($value === null) {
            return null;
        }

        $pattern = $decimals > 0 ? '/^(\d{1,10})(?:\.(\d{1,'.$decimals.'}))?$/' : '/^(\d{1,10})$/';

        if ($value === '' || ! preg_match($pattern, $value, $matches)) {
            return null;
        }

        $minor = (int) $matches[1] * (10 ** $decimals) + (int) str_pad($matches[2] ?? '', $decimals, '0');

        return $minor <= self::MAX ? $minor : null;
    }

    /**
     * The typed amount without its currency (a symbol or code, before or
     * after it) or spaces, or null if what's left still has anything else.
     */
    protected static function withoutCurrency(string $value, string $currency, ?string $locale): ?string
    {
        $marks = array_unique(array_filter([strtoupper($currency), self::symbol($currency, $locale), '£', '€', '$', 'US$']));
        usort($marks, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        foreach ($marks as $mark) {
            if (str_starts_with($value, $mark)) {
                $value = mb_substr($value, mb_strlen($mark));
                break;
            }
        }

        foreach ($marks as $mark) {
            if (str_ends_with($value, $mark)) {
                $value = mb_substr($value, 0, mb_strlen($value) - mb_strlen($mark));
                break;
            }
        }

        // Spaces, including the non-breaking ones some locales write.
        return (string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $value);
    }

    protected static function symbol(string $currency, ?string $locale): ?string
    {
        try {
            $formatter = new NumberFormatter(($locale ?? 'en_GB').'@currency='.strtoupper($currency), NumberFormatter::CURRENCY);

            return $formatter->getSymbol(NumberFormatter::CURRENCY_SYMBOL) ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The value with "." as its only decimal point and no thousands
     * separators, or null if it can't be read for sure.
     */
    protected static function normaliseSeparators(string $value, string $decimal): ?string
    {
        $hasDot = str_contains($value, '.');
        $hasComma = str_contains($value, ',');

        if ($hasDot && $hasComma) {
            $point = strrpos($value, '.') > strrpos($value, ',') ? '.' : ',';
            $group = $point === '.' ? ',' : '.';

            // Thousands before the point only, in groups of three.
            [$whole, $fraction] = explode($point, $value, 2) + [1 => ''];

            if (str_contains($fraction, $group) || str_contains($fraction, $point) || ! preg_match('/^\d{1,3}(\\'.$group.'\d{3})*$/', $whole)) {
                return null;
            }

            return str_replace($group, '', $whole).'.'.$fraction;
        }

        if (! $hasDot && ! $hasComma) {
            return $value;
        }

        $separator = $hasDot ? '.' : ',';

        // Strict groups of three read as thousands, unless it's this locale's
        // decimal point and there's only one ("1.500" is 1.5 in English).
        $grouped = preg_match('/^\d{1,3}(\\'.$separator.'\d{3})+$/', $value) === 1;

        if ($separator === $decimal && substr_count($value, $separator) === 1) {
            return str_replace($separator, '.', $value);
        }

        if ($grouped) {
            return str_replace($separator, '', $value);
        }

        return null;
    }

    /**
     * The decimal separator people type in a locale: "." for en_GB, "," for de_DE.
     */
    public static function decimalSeparator(?string $locale = null): string
    {
        if ($locale === null) {
            $site = SiteFacade::current();

            if ($custom = $site?->attribute('decimal_separator')) {
                return (string) $custom === ',' ? ',' : '.';
            }

            $locale = $site?->locale();
        }

        try {
            $symbol = (new NumberFormatter($locale ?? 'en_GB', NumberFormatter::DECIMAL))->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);
        } catch (Throwable) {
            $symbol = '.';
        }

        return $symbol === ',' ? ',' : '.';
    }

    /**
     * An amount in minor units as a decimal string for a form field: "25.00".
     */
    public static function toDecimal(int $minor, string $currency): string
    {
        $decimals = self::decimals($currency);

        return $decimals > 0 ? number_format($minor / (10 ** $decimals), $decimals, '.', '') : (string) $minor;
    }

    /**
     * An amount to show: "£25.00", in the site's locale.
     */
    public static function format(int $minor, string $currency, ?string $locale = null): string
    {
        $locale ??= SiteFacade::current()?->locale() ?? 'en_GB';

        try {
            $formatter = new IntlMoneyFormatter(new NumberFormatter($locale, NumberFormatter::CURRENCY), new ISOCurrencies);

            return $formatter->format(new MoneyPhp($minor, new Currency(strtoupper($currency))));
        } catch (Throwable) {
            return strtoupper($currency).' '.self::toDecimal($minor, $currency);
        }
    }
}
