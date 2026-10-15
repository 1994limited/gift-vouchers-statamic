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
     * An amount typed by a person ("25", "25.5", "£1,000.00") in minor
     * units, or null if it isn't one. Works with strings, never floats, so
     * nothing is lost to rounding.
     */
    public static function parse(mixed $input, string $currency): ?int
    {
        if (is_int($input)) {
            $input = (string) $input;
        }

        if (! is_string($input)) {
            return null;
        }

        // Currency symbols, spaces and thousands separators.
        $value = preg_replace('/[^\d.\-]/u', '', str_replace(',', '', trim($input)));
        $decimals = self::decimals($currency);

        $pattern = $decimals > 0 ? '/^(\d{1,10})(?:\.(\d{1,'.$decimals.'}))?$/' : '/^(\d{1,10})$/';

        if ($value === '' || ! preg_match($pattern, (string) $value, $matches)) {
            return null;
        }

        $minor = (int) $matches[1] * (10 ** $decimals) + (int) str_pad($matches[2] ?? '', $decimals, '0');

        return $minor <= self::MAX ? $minor : null;
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
