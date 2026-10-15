<?php

namespace NineteenNinetyFour\GiftVouchers\Tests\Feature;

use DuncanMcClean\Cargo\Facades\Cart;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use NineteenNinetyFour\GiftVouchers\Cargo\CartVouchers;
use NineteenNinetyFour\GiftVouchers\Cargo\Products;
use NineteenNinetyFour\GiftVouchers\Codes;
use NineteenNinetyFour\GiftVouchers\Presenter;
use NineteenNinetyFour\GiftVouchers\Tests\TestCase;
use NineteenNinetyFour\GiftVouchers\Voucher;
use PHPUnit\Framework\Attributes\Test;

class TagsTest extends TestCase
{
    protected function withErrors(array $errors): void
    {
        session()->put('errors', (new ViewErrorBag)->put('gift_vouchers', new MessageBag($errors)));
    }

    #[Test]
    public function the_form_posts_the_product_with_a_csrf_token(): void
    {
        $this->fixedVoucher(2500);

        $html = $this->antlers('{{ gift_vouchers:form product="voucher-25" redirect="/basket" }}<button>Add {{ price }}</button>{{ /gift_vouchers:form }}');

        $this->assertStringContainsString('action="http://localhost/!/gift-vouchers/cart/add"', $html);
        $this->assertStringContainsString('method="POST"', $html);
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertStringContainsString('<input type="hidden" name="product" value="voucher-25">', $html);
        $this->assertStringContainsString('<input type="hidden" name="_redirect" value="/basket">', $html);
        $this->assertStringContainsString('<button>Add £25.00</button>', $html);
    }

    #[Test]
    public function the_form_uses_the_entry_in_context(): void
    {
        $this->fixedVoucher(2500);

        $html = $this->antlers('{{ gift_vouchers:form }}{{ product }}{{ /gift_vouchers:form }}', ['id' => 'voucher-25']);

        $this->assertStringContainsString('voucher-25', $html);
    }

    #[Test]
    public function an_open_voucher_form_has_its_limits_and_suggestions(): void
    {
        $this->openVoucher(1000, 20000, data: ['voucher_suggested_amounts' => ['25', '50', '£100', '500', 'nonsense']]);

        $html = $this->antlers('{{ gift_vouchers:form product="voucher-open" }}{{ if is_open }}open {{ min }}-{{ max }} {{ min_formatted }}-{{ max_formatted }} step {{ step }} {{ suggested }}[{{ value }}|{{ formatted }}]{{ /suggested }}{{ /if }}{{ /gift_vouchers:form }}');

        $this->assertStringContainsString('open 10.00-200.00 £10.00-£200.00 step 0.01 [25.00|£25.00][50.00|£50.00][100.00|£100.00]', $html);
    }

    #[Test]
    public function the_form_shows_nothing_for_a_product_that_is_not_a_voucher(): void
    {
        $this->product();

        $this->assertSame('', trim($this->antlers('{{ gift_vouchers:form product="book" }}x{{ /gift_vouchers:form }}')));
        $this->assertSame('', trim($this->antlers('{{ gift_vouchers:form product="missing" }}x{{ /gift_vouchers:form }}')));
    }

    #[Test]
    public function what_was_typed_comes_back_escaped(): void
    {
        $this->fixedVoucher(2500);
        session()->flashInput(['recipient_name' => '<script>alert(1)</script>', 'message' => '"><img src=x onerror=alert(1)>', 'amount' => ['array']]);

        $html = $this->antlers('{{ gift_vouchers:form product="voucher-25" }}<input value="{{ old:recipient_name }}"><textarea>{{ old:message }}</textarea>[{{ old:amount }}]{{ /gift_vouchers:form }}');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&quot;&gt;&lt;img', $html);
        $this->assertStringContainsString('[]', $html);
    }

    #[Test]
    public function errors_are_shown_per_field(): void
    {
        $this->fixedVoucher(2500);
        $this->withErrors(['amount' => 'Choose an amount from £10.00 to £200.00.', 'recipient_email' => 'Bad <b>email</b>']);

        $html = $this->antlers('{{ gift_vouchers:form product="voucher-25" }}{{ errors:amount }}|{{ errors:recipient_email }}{{ /gift_vouchers:form }}');

        $this->assertStringContainsString('Choose an amount from £10.00 to £200.00.|Bad &lt;b&gt;email&lt;/b&gt;', $html);
        $this->assertSame('Bad &lt;b&gt;email&lt;/b&gt;', $this->antlers('{{ gift_vouchers:error field="recipient_email" }}'));
    }

    #[Test]
    public function the_redeem_remove_and_check_forms(): void
    {
        $this->assertStringContainsString('/!/gift-vouchers/cart/redeem', $this->antlers('{{ gift_vouchers:redeem }}<input name="code">{{ /gift_vouchers:redeem }}'));
        $this->assertStringContainsString('/!/gift-vouchers/check', $this->antlers('{{ gift_vouchers:check }}{{ /gift_vouchers:check }}'));

        $remove = $this->antlers('{{ gift_vouchers:remove voucher="a&quot;b" }}<button>Remove</button>{{ /gift_vouchers:remove }}');
        $this->assertStringContainsString('/!/gift-vouchers/cart/remove', $remove);
        $this->assertStringContainsString('name="voucher" value="a&amp;quot;b"', $remove);
    }

    #[Test]
    public function the_vouchers_on_the_cart_and_their_total(): void
    {
        $this->product(3000);
        $voucher = $this->voucher(1000);
        $cart = $this->cart([['product' => 'book']]);
        app(CartVouchers::class)->add($cart, $voucher->code());

        $html = $this->antlers('{{ gift_vouchers:applied }}{{ code }} {{ amount_formatted }} {{ id }}{{ /gift_vouchers:applied }}|{{ gift_vouchers:total }}|{{ gift_vouchers:total raw="true" }}|{{ if {gift_vouchers:has_applied} }}yes{{ /if }}');

        $this->assertSame("•••• {$voucher->code_hint} £10.00 {$voucher->id}|£10.00|1000|yes", $html);
    }

    #[Test]
    public function with_no_cart_there_is_nothing_applied(): void
    {
        $this->assertSame('|£0.00|', $this->antlers('{{ gift_vouchers:applied }}x{{ /gift_vouchers:applied }}|{{ gift_vouchers:total }}|{{ if {gift_vouchers:has_applied} }}yes{{ /if }}'));
    }

    #[Test]
    public function the_balance_after_a_check(): void
    {
        $voucher = $this->voucher(5000, ['expires_at' => now()->addYear()]);
        session()->flash('gift_vouchers_check', app(Presenter::class)->forTag($voucher));

        $html = $this->antlers('{{ gift_vouchers:balance }}{{ balance_formatted }} {{ status_label }} {{ if is_usable }}usable{{ /if }} {{ code }}{{ /gift_vouchers:balance }}');

        $this->assertSame("£50.00 Active usable •••• {$voucher->code_hint}", $html);
        $this->assertSame('£50.00', $this->antlers('{{ gift_vouchers:balance }}'));
    }

    #[Test]
    public function no_balance_before_a_check(): void
    {
        $this->assertSame('', $this->antlers('{{ gift_vouchers:balance }}x{{ /gift_vouchers:balance }}'));
    }

    #[Test]
    public function messages_are_escaped(): void
    {
        session()->flash('gift_vouchers_success', 'Added <script>');
        $this->withErrors(['gift_vouchers' => 'Nope <b>']);

        $this->assertSame('Added &lt;script&gt;', $this->antlers('{{ gift_vouchers:success }}'));
        $this->assertSame('Nope &lt;b&gt;', $this->antlers('{{ gift_vouchers:error }}'));
    }

    #[Test]
    public function the_vouchers_bought_on_an_order_without_their_codes(): void
    {
        Mail::fake();

        $this->fixedVoucher(2500);
        $order = $this->checkout($this->cart([[
            'product' => 'voucher-25',
            Products::RECIPIENT_NAME => '<b>Grace</b>',
            Products::SEND_ON => now()->addWeek()->toDateString(),
        ]]));

        $html = $this->antlers('{{ gift_vouchers:issued }}{{ recipient_name }} {{ amount_formatted }} {{ if is_scheduled }}on {{ send_on }}{{ /if }} {{ code }}{{ /gift_vouchers:issued }}', ['id' => $order->id()]);
        $voucher = Voucher::sole();

        $this->assertSame('&lt;b&gt;Grace&lt;/b&gt; £25.00 on '.now()->addWeek()->toDateString().' •••• '.$voucher->code_hint, $html);
        $this->assertStringNotContainsString(Codes::normalize($voucher->code()), $html);
    }

    #[Test]
    public function is_voucher(): void
    {
        $this->fixedVoucher();
        $this->product();

        $this->assertSame('yes', $this->antlers('{{ if {gift_vouchers:is_voucher product="voucher-25"} }}yes{{ else }}no{{ /if }}'));
        $this->assertSame('no', $this->antlers('{{ if {gift_vouchers:is_voucher product="book"} }}yes{{ else }}no{{ /if }}'));
    }

    #[Test]
    public function the_tags_work_in_blade_too(): void
    {
        $this->fixedVoucher(2500);
        $this->product(3000);
        $voucher = $this->voucher(1000);
        $cart = $this->cart([['product' => 'book']]);
        app(CartVouchers::class)->add($cart, $voucher->code());
        view()->addLocation(__DIR__.'/../__fixtures__/views');

        Mail::fake();
        $order = $this->checkout($this->cart([['product' => 'voucher-25', Products::RECIPIENT_NAME => "Seán O'Brien <b>"]]));
        Cart::setCurrent(Cart::find($cart->id()));

        $html = view('blade-tags', ['orderId' => $order->id()])->render();

        // Escaped once: the apostrophe isn't mangled, the markup isn't run.
        $this->assertStringContainsString('[Seán O&#039;Brien &lt;b&gt;]', $html);
        $this->assertStringContainsString('/!/gift-vouchers/cart/add', $html);
        $this->assertStringContainsString('Pay £25.00', $html);
        $this->assertStringContainsString('/!/gift-vouchers/cart/redeem', $html);
        $this->assertStringContainsString('•••• '.$voucher->code_hint.'=£10.00', $html);
        $this->assertStringContainsString('Total £10.00', $html);
    }
}
