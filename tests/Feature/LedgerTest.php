<?php

namespace NineteenNinetyFour\GiftVouchers\Tests\Feature;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use NineteenNinetyFour\GiftVouchers\Codes;
use NineteenNinetyFour\GiftVouchers\Events\VoucherAdjusted;
use NineteenNinetyFour\GiftVouchers\Events\VoucherCredited;
use NineteenNinetyFour\GiftVouchers\Events\VoucherIssued;
use NineteenNinetyFour\GiftVouchers\Events\VoucherRedeemed;
use NineteenNinetyFour\GiftVouchers\Ledger;
use NineteenNinetyFour\GiftVouchers\Tests\TestCase;
use NineteenNinetyFour\GiftVouchers\Transaction;
use NineteenNinetyFour\GiftVouchers\TransactionType;
use NineteenNinetyFour\GiftVouchers\Voucher;
use NineteenNinetyFour\GiftVouchers\VoucherException;
use NineteenNinetyFour\GiftVouchers\VoucherStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class LedgerTest extends TestCase
{
    protected function ledger(): Ledger
    {
        return app(Ledger::class);
    }

    #[Test]
    public function issuing_makes_a_voucher_with_its_balance_and_history(): void
    {
        Event::fake([VoucherIssued::class]);

        $voucher = $this->voucher(5000);

        $this->assertSame(5000, $voucher->initial_amount);
        $this->assertSame(5000, $voucher->fresh()->balance);
        $this->assertSame('GBP', $voucher->currency);
        $this->assertSame(VoucherStatus::Active, $voucher->status());

        $transaction = $voucher->transactions()->sole();
        $this->assertSame(TransactionType::Issued, $transaction->type);
        $this->assertSame(5000, $transaction->amount);
        $this->assertSame(5000, $transaction->balance_after);

        Event::assertDispatched(VoucherIssued::class, fn ($e) => $e->voucher->is($voucher));
    }

    #[Test]
    public function the_code_is_kept_hashed_and_encrypted_never_as_plain_text(): void
    {
        $voucher = $this->voucher(1000, code: 'MY-CODE-1234');

        $row = (array) DB::table('gift_vouchers')->where('id', $voucher->id)->first();

        foreach ($row as $column => $value) {
            $this->assertStringNotContainsString('MYCODE1234', (string) $value, "The code is in plain text in [{$column}].");
        }

        $this->assertSame(Codes::hash('MYCODE1234'), $row['code_hash']);
        $this->assertSame('MYCODE1234', Crypt::decryptString($row['code_encrypted']));
        $this->assertSame('1234', $row['code_hint']);
        $this->assertSame('MYCO-DE12-34', $voucher->code());
    }

    #[Test]
    public function vouchers_are_found_by_code_whatever_the_case_spaces_or_dashes(): void
    {
        $voucher = $this->voucher(1000, code: 'ABCD-EFGH-JKMN');

        foreach (['ABCD-EFGH-JKMN', 'abcdefghjkmn', ' abcd efgh jkmn ', 'AbCd–EfGh_JkMn'] as $typed) {
            $this->assertTrue(Voucher::findByCode($typed)?->is($voucher), "Not found as [{$typed}].");
        }

        $this->assertNull(Voucher::findByCode('ABCD-EFGH-JKMP'));
        $this->assertNull(Voucher::findByCode(''));
        $this->assertNull(Voucher::findByCode('---'));
    }

    #[Test]
    public function random_codes_are_unique_and_use_only_unambiguous_characters(): void
    {
        $codes = collect(range(1, 200))->map(fn () => Codes::normalize(Codes::generate()));

        $this->assertCount(200, $codes->unique());

        foreach ($codes as $code) {
            $this->assertSame(16, strlen($code));
            $this->assertMatchesRegularExpression('/^['.Codes::ALPHABET.']+$/', $code);
            $this->assertDoesNotMatchRegularExpression('/[01ILOU]/', $code);
        }
    }

    #[Test]
    public function a_prefix_goes_in_front_and_can_be_left_off_when_typed(): void
    {
        config(['gift-vouchers.codes.prefix' => 'gift']);

        $voucher = $this->voucher(1000);
        $code = $voucher->code();

        $this->assertStringStartsWith('GIFT-', $code);
        $this->assertTrue(Voucher::findByCode($code)->is($voucher));
        $this->assertTrue(Voucher::findByCode(substr($code, 5))->is($voucher));
    }

    #[Test]
    public function a_custom_code_must_be_valid_and_unused(): void
    {
        $this->voucher(1000, code: 'SUMMER-2026');

        try {
            $this->voucher(1000, code: 'summer 2026');
            $this->fail('A duplicate code was allowed.');
        } catch (VoucherException $e) {
            $this->assertSame('duplicate_code', $e->reason);
        }

        foreach (['ABC', str_repeat('A', 33), 'ABC<script>', 'CAFÉ-CODE'] as $bad) {
            try {
                $this->voucher(1000, code: $bad);
                $this->fail("[{$bad}] was allowed.");
            } catch (VoucherException $e) {
                $this->assertSame('invalid_custom_code', $e->reason);
            }
        }

        $this->assertSame(1, Voucher::count());
    }

    #[Test]
    #[DataProvider('badAmounts')]
    public function amounts_must_be_positive_and_sensible(int $amount): void
    {
        $this->expectException(VoucherException::class);

        $this->voucher($amount);
    }

    public static function badAmounts(): array
    {
        return ['zero' => [0], 'negative' => [-500], 'too big' => [1_000_000_001]];
    }

    #[Test]
    public function spending_takes_from_the_balance_and_records_it(): void
    {
        Event::fake([VoucherRedeemed::class]);

        $voucher = $this->voucher(5000);
        $transaction = $this->ledger()->debit($voucher, 1200, 'order-1');

        $this->assertSame(3800, $voucher->balance);
        $this->assertSame(3800, $voucher->fresh()->balance);
        $this->assertSame(-1200, $transaction->amount);
        $this->assertSame(3800, $transaction->balance_after);
        $this->assertSame('order-1', $transaction->order_id);

        Event::assertDispatched(VoucherRedeemed::class);
    }

    #[Test]
    public function the_whole_balance_can_be_spent_and_then_it_is_spent(): void
    {
        $voucher = $this->voucher(5000);

        $this->ledger()->debit($voucher, 5000, 'order-1');

        $this->assertSame(0, $voucher->fresh()->balance);
        $this->assertSame(VoucherStatus::Spent, $voucher->fresh()->status());
    }

    #[Test]
    public function a_balance_can_never_go_below_zero(): void
    {
        $voucher = $this->voucher(5000);

        try {
            $this->ledger()->debit($voucher, 5001, 'order-1');
            $this->fail('Spent more than the balance.');
        } catch (VoucherException $e) {
            $this->assertSame('insufficient', $e->reason);
        }

        $this->assertSame(5000, $voucher->fresh()->balance);
        $this->assertSame(1, $voucher->transactions()->count());
    }

    #[Test]
    public function a_stale_copy_of_a_voucher_cannot_spend_money_already_spent(): void
    {
        // Two checkouts load the same voucher at the same moment…
        $first = $this->voucher(5000);
        $second = Voucher::find($first->id);

        // …the first spends £40…
        $this->ledger()->debit($first, 4000, 'order-1');

        // …and the second, still thinking there's £50, can't spend £40 more.
        try {
            $this->ledger()->debit($second, 4000, 'order-2');
            $this->fail('The same money was spent twice.');
        } catch (VoucherException $e) {
            $this->assertSame('insufficient', $e->reason);
        }

        $this->assertSame(1000, $first->fresh()->balance);
        $this->assertSame(1, Transaction::where('type', 'redeemed')->count());
    }

    #[Test]
    public function many_spends_in_turn_never_overspend(): void
    {
        $voucher = $this->voucher(1000);
        $copies = collect(range(1, 15))->map(fn () => Voucher::find($voucher->id));

        $spent = 0;
        foreach ($copies as $i => $copy) {
            try {
                $this->ledger()->debit($copy, 300, "order-{$i}");
                $spent += 300;
            } catch (VoucherException) {
                //
            }
        }

        $this->assertSame(900, $spent);
        $this->assertSame(100, $voucher->fresh()->balance);
        $this->assertSame(100, (int) $voucher->transactions()->sum('amount'));
    }

    #[Test]
    public function disabled_and_expired_vouchers_cannot_be_spent(): void
    {
        $disabled = $this->voucher(5000, ['disabled_at' => now()]);
        $expired = $this->voucher(5000, ['expires_at' => now()->subMinute()]);

        foreach (['disabled' => $disabled, 'expired' => $expired] as $reason => $voucher) {
            try {
                $this->ledger()->debit($voucher, 100, 'order-1');
                $this->fail("A {$reason} voucher was spent.");
            } catch (VoucherException $e) {
                $this->assertSame($reason, $e->reason);
            }

            $this->assertSame(5000, $voucher->fresh()->balance);
        }
    }

    #[Test]
    public function a_voucher_expiring_later_today_can_still_be_spent(): void
    {
        $voucher = $this->voucher(5000, ['expires_at' => now()->addMinute()]);

        $this->ledger()->debit($voucher, 100);

        $this->assertSame(4900, $voucher->fresh()->balance);
    }

    #[Test]
    public function spending_nothing_or_a_negative_amount_is_refused(): void
    {
        $voucher = $this->voucher(5000);

        foreach ([0, -100] as $amount) {
            try {
                $this->ledger()->debit($voucher, $amount);
                $this->fail("Spent {$amount}.");
            } catch (VoucherException $e) {
                $this->assertSame('invalid_amount', $e->reason);
            }
        }

        $this->assertSame(5000, $voucher->fresh()->balance);
    }

    #[Test]
    public function adjusting_raises_or_lowers_a_balance_but_not_below_zero(): void
    {
        Event::fake([VoucherAdjusted::class]);

        $voucher = $this->voucher(5000);

        $this->ledger()->adjust($voucher, 2500, 'admin', 'Goodwill');
        $this->assertSame(7500, $voucher->fresh()->balance);

        $this->ledger()->adjust($voucher, -7000, 'admin', 'Mistake');
        $this->assertSame(500, $voucher->fresh()->balance);

        $this->expectException(VoucherException::class);
        $this->ledger()->adjust($voucher, -501, 'admin', 'Too much');
    }

    #[Test]
    public function an_admin_can_lower_the_balance_of_a_disabled_or_expired_voucher(): void
    {
        $voucher = $this->voucher(5000, ['expires_at' => now()->subDay(), 'disabled_at' => now()]);

        $transaction = $this->ledger()->adjust($voucher, -5000, 'admin', 'Written off');

        $this->assertSame(0, $transaction->balance_after);
        $this->assertSame(TransactionType::Adjusted, $transaction->type);
        $this->assertSame('admin', $transaction->user_id);
        $this->assertSame('Written off', $transaction->note);
    }

    #[Test]
    public function an_adjustment_of_nothing_is_refused(): void
    {
        $this->expectException(VoucherException::class);

        $this->ledger()->adjust($this->voucher(), 0);
    }

    #[Test]
    public function redeeming_for_an_order_is_all_or_nothing(): void
    {
        $plenty = $this->voucher(5000);
        $little = $this->voucher(500);

        try {
            $this->ledger()->redeemForOrder('order-1', [
                ['id' => $plenty->id, 'amount' => 3000],
                ['id' => $little->id, 'amount' => 1000],
            ]);
            $this->fail('An order was paid with money a voucher didn\'t have.');
        } catch (VoucherException $e) {
            $this->assertSame('insufficient', $e->reason);
        }

        // The first voucher wasn't charged either.
        $this->assertSame(5000, $plenty->fresh()->balance);
        $this->assertSame(500, $little->fresh()->balance);
        $this->assertSame(0, Transaction::where('type', 'redeemed')->count());
    }

    #[Test]
    public function redeeming_for_the_same_order_twice_only_spends_once(): void
    {
        $voucher = $this->voucher(5000);
        $breakdown = [['id' => $voucher->id, 'amount' => 2000]];

        $this->ledger()->redeemForOrder('order-1', $breakdown);
        $again = $this->ledger()->redeemForOrder('order-1', $breakdown);

        $this->assertCount(0, $again);
        $this->assertSame(3000, $voucher->fresh()->balance);
    }

    #[Test]
    public function redeeming_a_voucher_that_has_gone_fails(): void
    {
        $this->expectException(VoucherException::class);

        $this->ledger()->redeemForOrder('order-1', [['id' => '01JAAAAAAAAAAAAAAAAAAAAAAA', 'amount' => 100]]);
    }

    #[Test]
    public function restoring_an_order_puts_back_exactly_what_it_spent_once(): void
    {
        Event::fake([VoucherCredited::class]);

        $a = $this->voucher(6000);
        $b = $this->voucher(2000);
        $this->ledger()->redeemForOrder('order-1', [['id' => $a->id, 'amount' => 5000], ['id' => $b->id, 'amount' => 1500]]);
        $this->ledger()->debit($a->fresh(), 100, 'order-2'); // another order, not restored

        $this->ledger()->restoreForOrder('order-1', 'Cancelled');
        $this->ledger()->restoreForOrder('order-1', 'Cancelled again');

        $this->assertSame(5900, $a->fresh()->balance);
        $this->assertSame(2000, $b->fresh()->balance);
        $this->assertSame(2, Transaction::where('type', 'restored')->count());
        Event::assertDispatchedTimes(VoucherCredited::class, 2);
    }

    #[Test]
    public function restoring_puts_money_back_even_on_a_voucher_that_has_since_expired(): void
    {
        $voucher = $this->voucher(5000);
        $this->ledger()->redeemForOrder('order-1', [['id' => $voucher->id, 'amount' => 2000]]);
        $voucher->forceFill(['expires_at' => now()->subDay()])->save();

        $this->ledger()->restoreForOrder('order-1');

        $this->assertSame(5000, $voucher->fresh()->balance);
    }

    #[Test]
    public function refunding_an_order_returns_money_to_the_last_voucher_spent_first(): void
    {
        $first = $this->voucher(3000);
        $second = $this->voucher(3000);
        $this->ledger()->redeemForOrder('order-1', [['id' => $first->id, 'amount' => 3000], ['id' => $second->id, 'amount' => 2000]]);

        $this->ledger()->refundForOrder('order-1', 2500, 'admin');

        $this->assertSame(3000, $second->fresh()->balance); // 1000 + 2000 back
        $this->assertSame(500, $first->fresh()->balance);   // 0 + 500 back

        $this->assertSame(2500, $this->ledger()->outstandingForOrder('order-1')->sum());
    }

    #[Test]
    public function an_order_cannot_be_refunded_more_than_it_paid_by_voucher(): void
    {
        $voucher = $this->voucher(5000);
        $this->ledger()->redeemForOrder('order-1', [['id' => $voucher->id, 'amount' => 2000]]);
        $this->ledger()->refundForOrder('order-1', 1500);

        try {
            $this->ledger()->refundForOrder('order-1', 501);
            $this->fail('Refunded more than was paid.');
        } catch (VoucherException $e) {
            $this->assertSame('refund_too_much', $e->reason);
        }

        $this->ledger()->refundForOrder('order-1', 500);

        $this->assertSame(5000, $voucher->fresh()->balance);
        $this->assertSame(0, $this->ledger()->outstandingForOrder('order-1')->sum());
        $this->assertCount(0, $this->ledger()->restoreForOrder('order-1'));
    }

    #[Test]
    public function refunds_and_restores_of_the_same_order_share_one_limit(): void
    {
        $voucher = $this->voucher(5000);
        $this->ledger()->redeemForOrder('order-1', [['id' => $voucher->id, 'amount' => 4000]]);

        $this->ledger()->refundForOrder('order-1', 1000);
        $this->ledger()->restoreForOrder('order-1');

        $this->assertSame(5000, $voucher->fresh()->balance);
    }

    #[Test]
    public function refunding_nothing_is_refused(): void
    {
        $this->expectException(VoucherException::class);

        $this->ledger()->refundForOrder('order-1', 0);
    }

    #[Test]
    public function the_history_always_adds_up_to_the_balance(): void
    {
        $voucher = $this->voucher(10000);
        $this->ledger()->debit($voucher, 2500, 'a');
        $this->ledger()->adjust($voucher, 1000, 'admin', 'Sorry');
        $this->ledger()->redeemForOrder('b', [['id' => $voucher->id, 'amount' => 3000]]);
        $this->ledger()->refundForOrder('b', 1000);
        $this->ledger()->restoreForOrder('b');

        $voucher = $voucher->fresh();
        $transactions = $voucher->transactions()->get();

        $this->assertSame($voucher->balance, (int) $transactions->sum('amount'));
        $this->assertSame($voucher->balance, $transactions->last()->balance_after);
        $this->assertSame(8500, $voucher->balance);
    }
}
