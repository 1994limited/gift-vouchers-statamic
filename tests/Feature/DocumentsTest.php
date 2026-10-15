<?php

namespace NineteenNinetyFour\GiftVouchers\Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use NineteenNinetyFour\GiftVouchers\Delivery;
use NineteenNinetyFour\GiftVouchers\Mail\VoucherMail;
use NineteenNinetyFour\GiftVouchers\Pdf;
use NineteenNinetyFour\GiftVouchers\Settings;
use NineteenNinetyFour\GiftVouchers\Tests\TestCase;
use NineteenNinetyFour\GiftVouchers\VoucherException;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Addon;

class DocumentsTest extends TestCase
{
    protected function details(array $overrides = []): array
    {
        return array_merge([
            'recipient_name' => 'Grace',
            'recipient_email' => 'grace@example.com',
            'sender_name' => 'Ada',
            'message' => 'Happy birthday!',
        ], $overrides);
    }

    protected function settings(array $values): void
    {
        Addon::get(Settings::ADDON)->settings()->set($values)->save();
    }

    #[Test]
    public function the_printable_voucher_shows_the_code_and_details(): void
    {
        $voucher = $this->voucher(2500, $this->details(), 'PRINT-ME-1234');

        $this->get($voucher->printUrl())
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('£25.00')
            ->assertSee('PRIN-TME1-234')
            ->assertSeeText('For Grace')
            ->assertSeeText('From Ada')
            ->assertSee('Happy birthday!')
            ->assertSee('Download PDF')
            ->assertSee('<a href="http://localhost">localhost</a>', false);
    }

    #[Test]
    public function the_printable_voucher_needs_a_signed_link(): void
    {
        $voucher = $this->voucher(2500);

        $this->get(route('statamic.gift-vouchers.print', $voucher->id))->assertForbidden();
        $this->get($voucher->printUrl().'x')->assertForbidden();
        $this->get(route('statamic.gift-vouchers.pdf', $voucher->id))->assertForbidden();
        $this->get(URL::signedRoute('statamic.gift-vouchers.print', ['voucher' => 'nope']))->assertNotFound();
    }

    #[Test]
    public function what_shoppers_wrote_is_escaped_on_the_voucher(): void
    {
        $voucher = $this->voucher(2500, $this->details([
            'recipient_name' => '<script>alert(1)</script>',
            'message' => '<img src=x onerror=alert(1)>',
        ]));

        $this->get($voucher->printUrl())
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<img src=x', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    #[Test]
    public function a_spent_or_disabled_voucher_says_so(): void
    {
        $voucher = $this->voucher(2500, ['disabled_at' => now()]);

        $this->get($voucher->printUrl())->assertSee('This voucher can no longer be used.');
    }

    #[Test]
    public function the_pdf_is_a_pdf(): void
    {
        $voucher = $this->voucher(2500, $this->details());

        $response = $this->get($voucher->pdfUrl())
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertStringContainsString('gift-voucher-'.strtolower($voucher->code_hint).'.pdf', $response->headers->get('Content-Disposition'));
    }

    #[Test]
    public function the_pdf_fetches_nothing_from_the_web(): void
    {
        $voucher = $this->voucher(2500, $this->details(['message' => '<img src="http://127.0.0.1:1/x.png">']));

        // A message can't make the PDF load anything: it's escaped, and
        // remote loading is off. It renders without trying.
        $pdf = app(Pdf::class)->render($voucher);

        $this->assertStringStartsWith('%PDF-', $pdf);
    }

    #[Test]
    public function the_email_has_the_voucher_and_the_pdf(): void
    {
        $voucher = $this->voucher(2500, $this->details());
        $mail = new VoucherMail($voucher);

        $mail->assertHasSubject('You’ve been sent a £25.00 gift voucher');
        $mail->assertSeeInHtml('Ada has sent you a £25.00 gift voucher to spend at Bookshop.');
        $mail->assertSeeInHtml($voucher->code());
        $mail->assertSeeInHtml('Happy birthday!');
        $mail->assertSeeInText($voucher->code());
        $mail->assertSeeInText('Happy birthday!');

        [$attachment] = $mail->attachments();
        $this->assertSame('gift-voucher-'.strtolower($voucher->code_hint).'.pdf', $attachment->as);
        $this->assertSame('application/pdf', $attachment->mime);
        $this->assertStringStartsWith('%PDF-', $attachment->attachWith(fn () => null, fn ($data) => $data()));
    }

    #[Test]
    public function the_pdf_can_be_left_off_the_email(): void
    {
        config(['gift-vouchers.email.attach_pdf' => false]);

        $this->assertSame([], (new VoucherMail($this->voucher()))->attachments());
    }

    #[Test]
    public function the_email_escapes_html_but_the_text_version_does_not_mangle_it(): void
    {
        $voucher = $this->voucher(2500, $this->details(['sender_name' => "O'Brien <b>", 'message' => "Line one\n<script>x</script>"]));
        $mail = new VoucherMail($voucher);

        $mail->assertDontSeeInHtml('<script>x</script>', false);
        $mail->assertSeeInHtml('Line one<br />', false);
        $mail->assertSeeInText("O'Brien <b>");
        $mail->assertSeeInText('<script>x</script>');
    }

    #[Test]
    public function the_wording_comes_from_the_settings(): void
    {
        $this->settings([
            'email_subject' => ':recipient_name, a :amount treat from :sender_name',
            'email_heading' => 'Surprise!',
            'email_intro' => 'Spend it at :site before :expires.',
            'voucher_title' => 'Book token',
            'how_to_redeem' => 'Bring it in store or use it online.',
            'terms' => 'Not exchangeable for cash.',
            'accent_colour' => '#ffee00',
        ]);

        $voucher = $this->voucher(2500, $this->details() + ['expires_at' => now()->setDate(2027, 10, 15)]);
        $mail = new VoucherMail($voucher);

        $mail->assertHasSubject('Grace, a £25.00 treat from Ada');
        $mail->assertSeeInHtml('Surprise!');
        $mail->assertSeeInHtml('Spend it at Bookshop before Fri, Oct 15, 2027.');
        $mail->assertSeeInHtml('Book token');
        $mail->assertSeeInHtml('Not exchangeable for cash.');
        $mail->assertSeeInHtml('background:#ffee00');
        // Dark text on a light brand colour.
        $mail->assertSeeInHtml('color:#111827;', false);

        $this->get($voucher->printUrl())->assertSee('Book token')->assertSee('Bring it in store or use it online.');
    }

    #[Test]
    public function a_bad_colour_in_the_settings_cannot_break_out_of_the_css(): void
    {
        $this->settings(['accent_colour' => 'red;}</style><script>alert(1)</script>']);

        $this->assertSame('#1f2937', Settings::colour());
        $this->get($this->voucher()->printUrl())->assertDontSee('<script>alert(1)</script>', false);
    }

    #[Test]
    public function white_text_on_a_dark_brand_colour(): void
    {
        $this->settings(['accent_colour' => '#1e3a8a']);

        $this->assertSame('#ffffff', Settings::onColour());
    }

    #[Test]
    public function a_voucher_with_nobody_to_send_it_to_is_not_sent(): void
    {
        $voucher = $this->voucher(2500);

        $this->expectExceptionObject(VoucherException::because('no_email'));

        app(Delivery::class)->send($voucher);
    }

    #[Test]
    public function emails_can_be_turned_off(): void
    {
        Mail::fake();
        config(['gift-vouchers.email.enabled' => false]);

        $this->assertFalse(app(Delivery::class)->sendSafely($this->voucher(2500, $this->details())));
        Mail::assertNothingSent();
    }

    #[Test]
    public function emails_can_be_queued_and_copied(): void
    {
        Mail::fake();
        config(['gift-vouchers.email.queue' => 'mail', 'gift-vouchers.email.bcc' => 'shop@example.com']);

        app(Delivery::class)->send($this->voucher(2500, $this->details()));

        Mail::assertQueued(VoucherMail::class, fn ($mail) => $mail->hasTo('grace@example.com') && $mail->hasBcc('shop@example.com') && $mail->queue === 'mail');
    }

    #[Test]
    public function the_print_page_and_pdf_can_use_the_sites_own_antlers_template(): void
    {
        view()->addLocation(__DIR__.'/../__fixtures__/views');
        config(['gift-vouchers.views.print' => 'my-voucher', 'gift-vouchers.views.pdf' => 'my-voucher']);

        $voucher = $this->voucher(2500, ['recipient_name' => '<b>Grace</b>'], 'MY-OWN-1234');

        $this->get($voucher->printUrl())
            ->assertSee('My voucher £25.00')
            ->assertSee('MYOW-N123-4')
            ->assertSee('&lt;b&gt;Grace&lt;/b&gt;', false);

        $this->assertStringStartsWith('%PDF-', app(Pdf::class)->render($voucher));
    }
}
