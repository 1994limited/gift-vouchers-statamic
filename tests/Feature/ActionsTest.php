<?php

namespace NineteenNinetyFour\GiftVouchers\Tests\Feature;

use DuncanMcClean\Cargo\Facades\Cart;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use NineteenNinetyFour\GiftVouchers\Cargo\CartVouchers;
use NineteenNinetyFour\GiftVouchers\Cargo\Products;
use NineteenNinetyFour\GiftVouchers\Codes;
use NineteenNinetyFour\GiftVouchers\Ledger;
use NineteenNinetyFour\GiftVouchers\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Entry;

class ActionsTest extends TestCase
{
    /**
     * The line items of the cart the last request made.
     */
    protected function lineItems(): Collection
    {
        return Cart::query()->get()->sole()->lineItems();
    }

    protected function cartTotal(): int
    {
        return Cart::query()->get()->sole()->grandTotal();
    }

    #[Test]
    public function a_fixed_voucher_is_added_with_its_recipient(): void
    {
        $this->fixedVoucher(2500);

        $this->from('/products/voucher-25')->post('/!/gift-vouchers/cart/add', [
            'product' => 'voucher-25',
            'recipient_name' => 'Grace',
            'recipient_email' => 'grace@example.com',
            'sender_name' => 'Ada',
            'message' => "Happy birthday!\nLove, Ada",
        ])->assertRedirect('/products/voucher-25')->assertSessionHas('gift_vouchers_success');

        $lineItem = $this->lineItems()->sole();
        $this->assertSame('voucher-25', $lineItem->product()->id());
        $this->assertSame(2500, $lineItem->unitPrice());
        $this->assertSame('Grace', $lineItem->get(Products::RECIPIENT_NAME));
        $this->assertSame('grace@example.com', $lineItem->get(Products::RECIPIENT_EMAIL));
        $this->assertSame("Happy birthday!\nLove, Ada", $lineItem->get(Products::MESSAGE));
    }

    #[Test]
    public function an_open_voucher_is_added_with_the_amount_chosen(): void
    {
        $this->openVoucher(1000, 20000);

        $this->post('/!/gift-vouchers/cart/add', ['product' => 'voucher-open', 'amount' => '£42.50'])->assertSessionHasNoErrors();

        $this->assertSame(4250, $this->lineItems()->sole()->get(Products::AMOUNT));
        $this->assertSame(4250, $this->cartTotal());
    }

    #[Test]
    public function two_vouchers_for_different_people_stay_apart(): void
    {
        $this->fixedVoucher(2500);
        $cart = $this->cart();

        $this->post('/!/gift-vouchers/cart/add', ['product' => 'voucher-25', 'recipient_name' => 'Grace']);
        $this->post('/!/gift-vouchers/cart/add', ['product' => 'voucher-25', 'recipient_name' => 'Alan']);

        $cart = Cart::find($cart->id());
        $this->assertSame(['Grace', 'Alan'], $cart->lineItems()->map->get(Products::RECIPIENT_NAME)->all());
        $this->assertSame(5000, $cart->grandTotal());
    }

    #[Test]
    #[DataProvider('badAmounts')]
    public function the_amount_must_be_in_range(mixed $amount): void
    {
        $this->openVoucher(1000, 20000);

        $this->post('/!/gift-vouchers/cart/add', ['product' => 'voucher-open', 'amount' => $amount])
            ->assertSessionHasErrors(['amount' => 'Choose an amount from £10.00 to £200.00.'], null, 'gift_vouchers');

        $this->assertSame(0, Cart::query()->get()->sum(fn ($cart) => $cart->lineItems()->count()));
    }

    public static function badAmounts(): array
    {
        return [
            'too little' => ['9.99'],
            'too much' => ['200.01'],
            'nothing' => [''],
            'words' => ['ten pounds'],
            'negative' => ['-20'],
            'three decimals' => ['20.005'],
            'an array' => [['20']],
            'huge' => ['99999999999999999999'],
        ];
    }

    #[Test]
    public function a_bad_email_or_send_date_is_refused(): void
    {
        $this->fixedVoucher(2500);

        $this->post('/!/gift-vouchers/cart/add', ['product' => 'voucher-25', 'recipient_email' => 'grace@', 'send_on' => '2001-01-01'])
            ->assertSessionHasErrors(['recipient_email', 'send_on'], null, 'gift_vouchers');

        $this->post('/!/gift-vouchers/cart/add', ['product' => 'voucher-25', 'send_on' => now()->addYears(2)->toDateString()])
            ->assertSessionHasErrors(['send_on'], null, 'gift_vouchers');

        $this->post('/!/gift-vouchers/cart/add', ['product' => 'voucher-25', 'send_on' => '2026-02-30'])
            ->assertSessionHasErrors(['send_on'], null, 'gift_vouchers');

        $this->assertSame(0, Cart::query()->get()->sum(fn ($cart) => $cart->lineItems()->count()));
    }

    #[Test]
    public function today_as_the_send_date_means_send_straight_away(): void
    {
        $this->fixedVoucher(2500);

        $this->post('/!/gift-vouchers/cart/add', ['product' => 'voucher-25', 'send_on' => now()->toDateString()])->assertSessionHasNoErrors();

        $this->assertNull($this->lineItems()->sole()->get(Products::SEND_ON));
    }

    #[Test]
    public function only_voucher_products_can_be_added_this_way(): void
    {
        $this->product();
        $this->productEntry('draft', 'gift_voucher', ['title' => 'Draft', 'price' => 100]);
        Entry::find('draft')->published(false)->save();

        foreach (['book', 'draft', 'nope', ''] as $product) {
            $this->post('/!/gift-vouchers/cart/add', ['product' => $product])->assertSessionHasErrors(['product'], null, 'gift_vouchers');
        }

        $this->post('/!/gift-vouchers/cart/add', ['product' => ['voucher-25']])->assertSessionHasErrors(['product'], null, 'gift_vouchers');
    }

    #[Test]
    public function the_quantity_must_be_sensible(): void
    {
        $this->fixedVoucher(2500);

        foreach (['0', '21', '-1', '1.5', 'many'] as $quantity) {
            $this->post('/!/gift-vouchers/cart/add', ['product' => 'voucher-25', 'quantity' => $quantity])->assertSessionHasErrors(['quantity'], null, 'gift_vouchers');
        }

        $this->post('/!/gift-vouchers/cart/add', ['product' => 'voucher-25', 'quantity' => '3'])->assertSessionHasNoErrors();
        $this->assertSame(3, $this->lineItems()->sole()->quantity());
    }

    #[Test]
    public function a_long_message_is_cut_short_and_control_characters_go(): void
    {
        $this->fixedVoucher(2500);

        $this->post('/!/gift-vouchers/cart/add', [
            'product' => 'voucher-25',
            'recipient_name' => "Gr\x00ace\x07   Hopper",
            'message' => str_repeat('a', 900),
        ]);

        $lineItem = $this->lineItems()->sole();
        $this->assertSame('Grace Hopper', $lineItem->get(Products::RECIPIENT_NAME));
        $this->assertSame(500, mb_strlen($lineItem->get(Products::MESSAGE)));
    }

    #[Test]
    public function adding_answers_json_to_scripts(): void
    {
        $this->fixedVoucher(2500);

        $this->postJson('/!/gift-vouchers/cart/add', ['product' => 'voucher-25'])
            ->assertOk()
            ->assertJson(['success' => true, 'cart' => ['grand_total' => 2500]]);

        $this->postJson('/!/gift-vouchers/cart/add', ['product' => 'nope'])
            ->assertStatus(422)
            ->assertJson(['success' => false, 'errors' => ['product' => 'That isn’t a gift voucher we sell.']]);
    }

    #[Test]
    public function a_code_is_used_on_the_cart(): void
    {
        $this->product(3000);
        $voucher = $this->voucher(1000);
        $this->cart([['product' => 'book']]);

        $this->post('/!/gift-vouchers/cart/redeem', ['code' => strtolower($voucher->code())])
            ->assertSessionHas('gift_vouchers_success', 'Gift voucher applied: £10.00 off your order.');

        $this->assertSame(2000, Cart::current()->grandTotal());
    }

    #[Test]
    public function using_a_code_explains_what_went_wrong(): void
    {
        $this->product(3000);
        $this->cart([['product' => 'book']]);
        $expired = $this->voucher(1000, ['expires_at' => now()->subDay()->setTime(12, 0)]);

        $this->post('/!/gift-vouchers/cart/redeem', ['code' => ''])->assertSessionHasErrors(['code' => 'Enter the code from your gift voucher.'], null, 'gift_vouchers');
        $this->post('/!/gift-vouchers/cart/redeem', ['code' => 'WRONG-CODE'])->assertSessionHasErrors(['code' => 'We couldn’t find a gift voucher with that code. Check it and try again.'], null, 'gift_vouchers');
        $this->post('/!/gift-vouchers/cart/redeem', ['code' => $expired->code()])->assertSessionHasErrors(['code'], null, 'gift_vouchers');
        $this->post('/!/gift-vouchers/cart/redeem', ['code' => ['x']])->assertSessionHasErrors(['code'], null, 'gift_vouchers');
    }

    #[Test]
    public function the_code_typed_is_not_kept_in_the_session(): void
    {
        $this->product(3000);
        $this->cart([['product' => 'book']]);

        $this->post('/!/gift-vouchers/cart/redeem', ['code' => 'WRONG-CODE']);

        $this->assertNull(session()->getOldInput('code'));
    }

    #[Test]
    public function using_a_code_needs_a_cart(): void
    {
        $voucher = $this->voucher(1000);

        $this->post('/!/gift-vouchers/cart/redeem', ['code' => $voucher->code()])
            ->assertSessionHasErrors(['code' => 'Add something to your basket first.'], null, 'gift_vouchers');
    }

    #[Test]
    public function a_voucher_is_taken_off_the_cart(): void
    {
        $this->product(3000);
        $voucher = $this->voucher(1000);
        $cart = $this->cart([['product' => 'book']]);
        app(CartVouchers::class)->add($cart, $voucher->code());

        $this->post('/!/gift-vouchers/cart/remove', ['voucher' => $voucher->id])->assertSessionHas('gift_vouchers_success');
        $this->assertSame(3000, Cart::current()->grandTotal());

        $this->post('/!/gift-vouchers/cart/remove', ['voucher' => $voucher->id])->assertSessionHasErrors(['voucher'], null, 'gift_vouchers');
    }

    #[Test]
    public function a_balance_can_be_checked(): void
    {
        $voucher = $this->voucher(5000);
        app(Ledger::class)->debit($voucher, 1250);

        $this->post('/!/gift-vouchers/check', ['code' => $voucher->code()])
            ->assertSessionHas('gift_vouchers_check', fn (array $result) => $result['balance'] === 3750 && $result['balance_formatted'] === '£37.50');

        $this->postJson('/!/gift-vouchers/check', ['code' => $voucher->code()])
            ->assertOk()
            ->assertJsonPath('voucher.balance', 3750)
            ->assertJsonPath('voucher.status', 'active')
            ->assertJsonMissingPath('voucher.id');

        $this->postJson('/!/gift-vouchers/check', ['code' => 'NOPE'])->assertStatus(422);
    }

    #[Test]
    public function checking_a_balance_never_shows_the_whole_code(): void
    {
        $voucher = $this->voucher(5000);

        $json = $this->postJson('/!/gift-vouchers/check', ['code' => $voucher->code()])->getContent();

        $this->assertStringNotContainsString(Codes::normalize($voucher->code()), $json);
        $this->assertStringNotContainsString($voucher->code(), $json);
    }

    #[Test]
    public function codes_cannot_be_guessed_by_trying_lots_of_them(): void
    {
        config(['gift-vouchers.throttle.codes' => 3]);

        foreach (range(1, 3) as $i) {
            $this->postJson('/!/gift-vouchers/check', ['code' => "GUESS-{$i}"])->assertStatus(422);
        }

        $this->postJson('/!/gift-vouchers/check', ['code' => 'GUESS-4'])->assertStatus(429);
        $this->postJson('/!/gift-vouchers/cart/redeem', ['code' => 'GUESS-5'])->assertStatus(429);
    }

    #[Test]
    public function adding_to_the_cart_is_rate_limited_too(): void
    {
        config(['gift-vouchers.throttle.cart' => 2]);
        $this->fixedVoucher(2500);

        $this->postJson('/!/gift-vouchers/cart/add', ['product' => 'voucher-25'])->assertOk();
        $this->postJson('/!/gift-vouchers/cart/add', ['product' => 'voucher-25'])->assertOk();
        $this->postJson('/!/gift-vouchers/cart/add', ['product' => 'voucher-25'])->assertStatus(429);
    }

    #[Test]
    public function redirects_only_go_to_this_site(): void
    {
        $this->fixedVoucher(2500);

        $this->from('/shop')->post('/!/gift-vouchers/cart/add', ['product' => 'voucher-25', '_redirect' => '/basket'])->assertRedirect('/basket');
        $this->from('/shop')->post('/!/gift-vouchers/cart/add', ['product' => 'voucher-25', '_redirect' => 'https://evil.example/'])->assertRedirect('/shop');
        $this->from('/shop')->post('/!/gift-vouchers/cart/add', ['product' => 'voucher-25', '_redirect' => '//evil.example/'])->assertRedirect('/shop');
        $this->from('/shop')->post('/!/gift-vouchers/cart/add', ['product' => 'voucher-25', '_redirect' => '/\\evil.example/'])->assertRedirect('/shop');
        $this->from('/shop')->post('/!/gift-vouchers/cart/add', ['product' => 'nope', '_error_redirect' => '/oops'])->assertRedirect('/oops');
    }

    #[Test]
    public function the_vouchers_on_the_cart_are_given_to_scripts(): void
    {
        $this->getJson('/!/gift-vouchers/cart')->assertOk()->assertExactJson(['success' => true, 'cart' => null]);

        $this->product(3000);
        $voucher = $this->voucher(1000);
        $cart = $this->cart([['product' => 'book']]);
        app(CartVouchers::class)->add($cart, $voucher->code());

        $this->getJson('/!/gift-vouchers/cart')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('cart.grand_total', 2000)
            ->assertJsonPath('cart.grand_total_formatted', '£20.00')
            ->assertJsonPath('cart.gift_vouchers.0.code', '•••• '.$voucher->code_hint)
            ->assertJsonPath('cart.gift_vouchers.0.amount_formatted', '£10.00');
    }

    #[Test]
    public function the_cargo_checkout_partials_render(): void
    {
        $this->product(3000);
        $voucher = $this->voucher(1000);
        $cart = $this->cart([['product' => 'book']]);
        app(CartVouchers::class)->add($cart, $voucher->code());

        $form = $this->antlers('{{ partial:gift-vouchers::cargo-checkout }}');
        $this->assertStringContainsString('/!/gift-vouchers/cart/redeem', $form);
        $this->assertStringContainsString('@submit.prevent="redeem"', $form);
        $this->assertStringNotContainsString('<script', $form);

        $lines = $this->antlers('{{ partial:gift-vouchers::cargo-checkout-lines }}');
        $this->assertStringContainsString('data-vouchers-url="http://localhost/!/gift-vouchers/cart"', $lines);
        $this->assertStringNotContainsString('<script', $lines);

        Mail::fake();
        $order = $this->checkout($cart);
        $summary = $this->antlers('{{ partial:gift-vouchers::cargo-order-summary :order="id" }}', ['id' => $order->id()]);
        $this->assertStringContainsString('•••• '.$voucher->code_hint, $summary);
        $this->assertStringContainsString('-£10.00', $summary);
    }
}
