<?php

namespace NineteenNinetyFour\GiftVouchers\Tests\Unit;

use Carbon\CarbonImmutable;
use NineteenNinetyFour\GiftVouchers\Details;
use NineteenNinetyFour\GiftVouchers\Money;
use NineteenNinetyFour\GiftVouchers\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class MoneyAndDetailsTest extends TestCase
{
    #[Test]
    #[DataProvider('amounts')]
    public function typed_amounts_are_read_exactly(mixed $typed, string $currency, ?int $minor, string $locale = 'en_GB'): void
    {
        $this->assertSame($minor, Money::parse($typed, $currency, $locale));
    }

    public static function amounts(): array
    {
        return [
            ['25', 'GBP', 2500],
            ['25.5', 'GBP', 2550],
            ['25.50', 'GBP', 2550],
            ['19.99', 'GBP', 1999], // floats would make this 1998
            ['0.29', 'GBP', 29],
            ['£1,000.00', 'GBP', 100000],
            [' 42 ', 'GBP', 4200],
            [2500, 'GBP', 250000],
            ['1000', 'JPY', 1000],
            ['10.5', 'JPY', null],
            ['25.505', 'GBP', null],
            ['-5', 'GBP', null],
            ['', 'GBP', null],
            ['abc', 'GBP', null],
            ['1.2.3', 'GBP', null],
            ['12345678901', 'GBP', null],
            [25.5, 'GBP', null],
            [null, 'GBP', null],
            [['25'], 'GBP', null],
            // Decimal commas, read for the locale, and never guessed.
            ['10,50', 'GBP', null],
            ['1,000', 'GBP', 100000],
            ['1,000.50', 'GBP', 100050],
            ['12,34,567', 'GBP', null],
            ['10,50', 'EUR', 1050, 'de_DE'],
            ['1.000,50', 'EUR', 100050, 'de_DE'],
            ['1.000', 'EUR', 100000, 'de_DE'],
            ['10.5', 'EUR', null, 'de_DE'],
            ['25.00', 'EUR', 2500, 'de_DE'], // as the form's quick amounts write it
            ['€ 25,5', 'EUR', 2550, 'fr_FR'],
        ];
    }

    #[Test]
    public function amounts_are_formatted_and_written_for_forms(): void
    {
        $this->assertSame('£25.00', Money::format(2500, 'GBP', 'en_GB'));
        $this->assertSame('€1,234.56', Money::format(123456, 'EUR', 'en_GB'));
        $this->assertSame('25.00', Money::toDecimal(2500, 'GBP'));
        $this->assertSame('1000', Money::toDecimal(1000, 'JPY'));
        $this->assertSame('GBP', Money::currency());
    }

    #[Test]
    public function details_are_cleaned_up(): void
    {
        $details = Details::from([
            'recipient_name' => "  Grace\t\x00Hopper  ",
            'recipient_email' => ' grace@example.com ',
            'sender_name' => ['not', 'text'],
            'message' => "Line one\r\n\r\n\r\n\r\nLine two \x07 <3",
            'send_on' => CarbonImmutable::today()->addDays(10)->toDateString(),
        ]);

        $this->assertSame('Grace Hopper', $details->recipientName);
        $this->assertSame('grace@example.com', $details->recipientEmail);
        $this->assertNull($details->senderName);
        $this->assertSame("Line one\n\nLine two <3", $details->message);
        $this->assertSame(CarbonImmutable::today()->addDays(10)->toDateString(), $details->sendOn->toDateString());
    }

    #[Test]
    #[DataProvider('dates')]
    public function send_dates_must_be_from_tomorrow_to_a_year_away(string $date, bool $kept): void
    {
        $this->assertSame($kept, Details::date($date) !== null);
    }

    public static function dates(): array
    {
        $today = CarbonImmutable::today();

        return [
            'today' => [$today->toDateString(), false],
            'tomorrow' => [$today->addDay()->toDateString(), true],
            'a year away' => [$today->addYear()->toDateString(), true],
            'too far' => [$today->addYear()->addDay()->toDateString(), false],
            'the past' => ['2001-01-01', false],
            'not a date' => ['2026-02-30', false],
            'wrong format' => ['15/10/2026', false],
            'with a time' => [$today->addDay()->toDateString().' 10:00', false],
        ];
    }

    #[Test]
    public function emails_must_be_real(): void
    {
        $this->assertNull(Details::email('grace@'));
        $this->assertNull(Details::email("grace@example.com\nBcc: x@y.z"));
        $this->assertNull(Details::email(str_repeat('a', 250).'@example.com'));
        $this->assertTrue(Details::isBadEmail('nope'));
        $this->assertFalse(Details::isBadEmail(''));
        $this->assertFalse(Details::isBadEmail(null));
    }
}
