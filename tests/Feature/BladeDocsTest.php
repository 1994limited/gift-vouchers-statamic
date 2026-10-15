<?php

namespace NineteenNinetyFour\GiftVouchers\Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use NineteenNinetyFour\GiftVouchers\Cargo\CartVouchers;
use NineteenNinetyFour\GiftVouchers\Cargo\Products;
use NineteenNinetyFour\GiftVouchers\Designs;
use NineteenNinetyFour\GiftVouchers\Presenter;
use NineteenNinetyFour\GiftVouchers\Settings;
use NineteenNinetyFour\GiftVouchers\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Addon;

/**
 * The Blade examples in the README and docs, rendered for real. Each file in
 * tests/__fixtures__/views/docs is copied word for word into the docs.
 */
class BladeDocsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        view()->addNamespace('docs', __DIR__.'/../__fixtures__/views/docs');
        view()->addLocation(__DIR__.'/../__fixtures__/views');
    }

    protected function render(string $view, array $data = []): string
    {
        return view("docs::{$view}", $data)->render();
    }

    #[Test]
    public function the_readme_examples(): void
    {
        $this->openVoucher(1000, 20000, data: ['voucher_suggested_amounts' => ['25']]);

        $form = $this->render('readme-form', ['id' => 'voucher-open']);
        $this->assertStringContainsString('data-gift-vouchers-preview', $form);
        $this->assertStringContainsString('action="http://localhost/!/gift-vouchers/cart/add"', $form);
        $this->assertStringContainsString('name="product" value="voucher-open"', $form);
        $this->assertStringContainsString('name="_redirect" value="/basket"', $form);

        $this->assertStringContainsString('/!/gift-vouchers/cart/redeem', $this->render('readme-redeem'));
    }

    #[Test]
    public function the_voucher_form_with_old_input_and_errors(): void
    {
        $this->openVoucher(1000, 20000, data: ['voucher_suggested_amounts' => ['25', '50']]);
        session()->flashInput(['recipient_name' => "Seán O'Brien", 'amount' => '75']);
        session()->put('errors', (new ViewErrorBag)->put('gift_vouchers', new MessageBag(['amount' => 'Choose an amount from £10.00 to £200.00.'])));

        $html = $this->render('form', ['id' => 'voucher-open']);

        $this->assertStringContainsString('Amount (£10.00 to £200.00)', $html);
        $this->assertStringContainsString('data-gift-vouchers-amount="25.00" hidden>£25.00</button>', $html);
        $this->assertStringContainsString('name="amount" value="75"', $html);
        $this->assertStringContainsString('<p class="error">Choose an amount from £10.00 to £200.00.</p>', $html);
        $this->assertStringContainsString('value="Seán O&#039;Brien"', $html);
        $this->assertStringContainsString('maxlength="100"', $html);
        $this->assertStringContainsString('maxlength="500"', $html);
    }

    #[Test]
    public function a_set_amount_shows_its_price(): void
    {
        $this->fixedVoucher(2500);

        $this->assertStringContainsString('<p>£25.00</p>', $this->render('form', ['id' => 'voucher-25']));
    }

    #[Test]
    public function the_designs(): void
    {
        Addon::get(Settings::ADDON)->settings()->set(['designs' => [
            ['id' => 'a', 'name' => 'Classic', 'style' => 'engraved'],
            ['id' => 'b', 'name' => 'Fish & chips', 'style' => 'plain'],
        ]])->save();
        app(Designs::class)->forget();
        $this->fixedVoucher(2500);

        $html = view('docs-designs-wrapper', ['id' => 'voucher-25'])->render();

        $this->assertStringContainsString('<legend>Design</legend>', $html);
        $this->assertStringContainsString('value="classic" checked', $html);
        $this->assertStringContainsString('Fish &amp; chips', $html);
        $this->assertStringNotContainsString('Fish &amp;amp; chips', $html);
    }

    #[Test]
    public function the_preview_and_artwork(): void
    {
        $this->openVoucher();

        $this->assertStringContainsString('class="gv-card gv-engraved"', $this->render('preview', ['id' => 'voucher-open']));
        $this->assertStringStartsWith('<svg', trim($this->render('artwork')));
    }

    #[Test]
    public function the_messages(): void
    {
        $this->assertSame('', trim($this->render('messages')));

        session()->flash('gift_vouchers_success', 'Gift voucher added to your basket.');
        session()->put('errors', (new ViewErrorBag)->put('gift_vouchers', new MessageBag(['gift_vouchers' => 'Nope <b>'])));

        $html = $this->render('messages');
        $this->assertStringContainsString('<p class="notice">Gift voucher added to your basket.</p>', $html);
        $this->assertStringContainsString('<p class="error">Nope &lt;b&gt;</p>', $html);
    }

    #[Test]
    public function the_code_box_applied_vouchers_and_line_items(): void
    {
        $this->product(3000);
        $this->fixedVoucher(2500);
        $voucher = $this->voucher(1000);
        $cart = $this->cart([
            ['product' => 'book'],
            ['product' => 'voucher-25', Products::RECIPIENT_NAME => 'Grace <b>', Products::MESSAGE => 'Happy birthday!'],
        ]);
        app(CartVouchers::class)->add($cart, $voucher->code());
        session()->put('errors', (new ViewErrorBag)->put('gift_vouchers', new MessageBag(['code' => 'That code didn’t work.'])));

        $redeem = $this->render('redeem');
        $this->assertStringContainsString('/!/gift-vouchers/cart/redeem', $redeem);
        $this->assertStringContainsString('<p class="error">That code didn’t work.</p>', $redeem);

        $applied = $this->render('applied');
        $this->assertStringContainsString('Subtotal £55.00', $applied);
        $this->assertStringContainsString('Gift voucher •••• '.$voucher->code_hint.' −£10.00', $applied);
        $this->assertStringContainsString('name="voucher" value="'.$voucher->id.'"', $applied);
        $this->assertStringContainsString('To pay £45.00', $applied);

        $lines = $this->render('line-items');
        $this->assertStringContainsString('<strong>A book</strong> £30.00', $lines);
        $this->assertStringContainsString('<div>For Grace &lt;b&gt;</div>', $lines);
        $this->assertStringContainsString('<div>“Happy birthday!”</div>', $lines);
    }

    #[Test]
    public function the_balance_checker(): void
    {
        $voucher = $this->voucher(5000, ['expires_at' => now()->setDate(2030, 1, 31)]);
        session()->flash('gift_vouchers_check', app(Presenter::class)->forTag($voucher));

        $html = $this->render('balance');

        $this->assertStringContainsString('<p>£50.00 left, to spend by Thu, Jan 31, 2030.</p>', $html);
        $this->assertStringContainsString('/!/gift-vouchers/check', $html);
    }

    #[Test]
    public function the_confirmation_page(): void
    {
        Mail::fake();
        $this->product(3000);
        $this->fixedVoucher(2500);
        $voucher = $this->voucher(1000);
        $cart = $this->cart([['product' => 'book'], ['product' => 'voucher-25', Products::RECIPIENT_NAME => "Seán O'Brien"]]);
        app(CartVouchers::class)->add($cart, $voucher->code());
        $order = $this->checkout($cart);
        $this->onConfirmationPage($order);

        $html = $this->render('confirmation', ['id' => $order->id()]);

        $this->assertStringContainsString('Paid with gift voucher •••• '.$voucher->code_hint.' −£10.00', $html);
        $this->assertStringContainsString('£25.00 for Seán O&#039;Brien:', $html);
        $this->assertStringContainsString('emailed', $html);
    }

    #[Test]
    public function the_docs_show_exactly_these_examples(): void
    {
        $docs = file_get_contents(__DIR__.'/../../README.md').file_get_contents(__DIR__.'/../../docs/templates.md');

        // The artwork example is inline, in the designs doc.
        $artwork = rtrim(file_get_contents(__DIR__.'/../__fixtures__/views/docs/artwork.blade.php'));
        $this->assertStringContainsString('`'.$artwork.'`', file_get_contents(__DIR__.'/../../docs/designs.md'));

        foreach (glob(__DIR__.'/../__fixtures__/views/docs/*.blade.php') as $file) {
            if (basename($file) === 'artwork.blade.php') {
                continue;
            }

            $this->assertStringContainsString("```blade\n".rtrim(file_get_contents($file))."\n```", $docs, basename($file).' is not in the docs as tested.');
        }
    }
}
