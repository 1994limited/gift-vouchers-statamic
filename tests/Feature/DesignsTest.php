<?php

namespace NineteenNinetyFour\GiftVouchers\Tests\Feature;

use DuncanMcClean\Cargo\Facades\Cart;
use Illuminate\Support\Facades\Mail;
use NineteenNinetyFour\GiftVouchers\Artwork;
use NineteenNinetyFour\GiftVouchers\Cargo\Products;
use NineteenNinetyFour\GiftVouchers\Design;
use NineteenNinetyFour\GiftVouchers\Designs;
use NineteenNinetyFour\GiftVouchers\Settings;
use NineteenNinetyFour\GiftVouchers\Tests\TestCase;
use NineteenNinetyFour\GiftVouchers\Voucher;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Addon;

class DesignsTest extends TestCase
{
    protected function designs(array $designs, array $settings = []): Designs
    {
        Addon::get(Settings::ADDON)->settings()->set(array_merge($settings, ['designs' => $designs]))->save();

        $designs = app(Designs::class);
        $designs->forget();

        return $designs;
    }

    protected function threeDesigns(): Designs
    {
        return $this->designs([
            ['id' => 'a', 'name' => 'Classic', 'style' => 'engraved', 'colour' => '#1d2a44'],
            ['id' => 'b', 'name' => 'Birthday', 'title' => 'Happy birthday', 'style' => 'engraved', 'motif' => 'cake', 'colour' => '#8a2b2b'],
            ['id' => 'c', 'name' => 'Plain & simple', 'style' => 'plain'],
        ]);
    }

    #[Test]
    public function with_no_designs_set_there_is_one_from_the_voucher_settings(): void
    {
        $designs = $this->designs([], ['style' => 'plain', 'accent_colour' => '#14532d']);

        $this->assertCount(1, $designs->all());
        $this->assertFalse($designs->hasChoice());
        $this->assertSame('default', $designs->find(null)->handle);
        $this->assertNull($designs->find(null)->kind());
        $this->assertSame('#14532d', $designs->find('anything')->colour);
    }

    #[Test]
    public function designs_come_from_the_settings(): void
    {
        $designs = $this->threeDesigns();

        $this->assertSame(['classic', 'birthday', 'plain-simple'], $designs->all()->keys()->all());
        $this->assertTrue($designs->hasChoice());

        $birthday = $designs->find('birthday');
        $this->assertSame('Happy birthday', $birthday->title);
        $this->assertSame('cake', $birthday->motif);
        $this->assertSame('#8a2b2b', $birthday->colour);
        $this->assertSame(Design::ENGRAVED, $birthday->kind());

        // Unknown designs fall back to the first.
        $this->assertSame('classic', $designs->find('nope')->handle);
    }

    #[Test]
    public function bad_values_in_the_settings_are_ignored(): void
    {
        $designs = $this->designs([
            ['id' => 'a', 'name' => 'Odd', 'style' => 'sparkly', 'motif' => '<script>', 'colour' => 'red;}</style>', 'title' => str_repeat('x', 100)],
            ['id' => 'b', 'name' => ''],
            ['id' => 'c', 'name' => 'No image', 'style' => 'image'],
        ], ['accent_colour' => '#123456']);

        $odd = $designs->find('odd');
        $this->assertSame(Design::ENGRAVED, $odd->style);
        $this->assertNull($odd->motif);
        $this->assertSame('#123456', $odd->colour);
        $this->assertSame(60, mb_strlen($odd->title));
        $this->assertCount(2, $designs->all());

        // An image design without an image is drawn engraved.
        $this->assertSame(Design::ENGRAVED, $designs->find('no-image')->kind());
    }

    #[Test]
    public function each_vouchers_pattern_is_its_own_and_always_the_same(): void
    {
        $a = (new Artwork('01JAAAA', '#1d2a44'))->card();

        $this->assertSame($a, (new Artwork('01JAAAA', '#1d2a44'))->card());
        $this->assertNotSame($a, (new Artwork('01JBBBB', '#1d2a44'))->card());
        $this->assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg"', $a);
        $this->assertStringContainsString('stroke="#1d2a44"', $a);
        $this->assertNotFalse(simplexml_load_string($a), 'The artwork is not valid XML.');
        $this->assertLessThan(200_000, strlen($a));
    }

    #[Test]
    public function every_motif_draws(): void
    {
        foreach (Artwork::MOTIFS as $motif) {
            $svg = (new Artwork('seed', '#000000', $motif))->card();

            $this->assertNotFalse(simplexml_load_string($svg), "The {$motif} motif is not valid XML.");
            $this->assertNotSame((new Artwork('seed', '#000000'))->card(), $svg);
        }

        // Anything else is no motif.
        $this->assertSame((new Artwork('seed', '#000000'))->card(), (new Artwork('seed', '#000000', 'skull'))->card());
    }

    #[Test]
    public function the_design_chosen_is_kept_with_the_voucher(): void
    {
        Mail::fake();
        $this->threeDesigns();
        $this->fixedVoucher(2500);
        $cart = $this->cart();

        $this->post('/!/gift-vouchers/cart/add', ['product' => 'voucher-25', 'design' => 'birthday'])->assertSessionHasNoErrors();
        $this->assertSame('birthday', Cart::find($cart->id())->lineItems()->sole()->get(Products::DESIGN));

        $this->checkout($cart);

        $this->assertSame('birthday', Voucher::sole()->design);
    }

    #[Test]
    public function a_design_that_does_not_exist_is_refused(): void
    {
        $this->threeDesigns();
        $this->fixedVoucher(2500);

        $this->post('/!/gift-vouchers/cart/add', ['product' => 'voucher-25', 'design' => 'gone'])
            ->assertSessionHasErrors(['design' => 'Choose one of the designs.'], null, 'gift_vouchers');
    }

    #[Test]
    public function without_a_choice_vouchers_get_the_first_design(): void
    {
        Mail::fake();
        $this->threeDesigns();
        $this->fixedVoucher(2500);

        $this->checkout($this->cart([['product' => 'voucher-25', Products::DESIGN => 'made-up']]));

        $this->assertSame('classic', Voucher::sole()->design);
    }

    #[Test]
    public function the_voucher_is_drawn_in_its_design(): void
    {
        $this->threeDesigns();

        $birthday = $this->voucher(2500, ['design' => 'birthday']);
        $this->get($birthday->printUrl())
            ->assertSee('Happy birthday')
            ->assertSee('--gv-colour: #8a2b2b', false)
            ->assertSee('class="gv-card gv-engraved"', false)
            ->assertSee('data:image/svg+xml;base64,', false);

        $plain = $this->voucher(2500, ['design' => 'plain-simple']);
        $this->get($plain->printUrl())
            ->assertSee('class="gv-card gv-plain"', false)
            ->assertDontSee('data:image/svg+xml;base64,', false);
    }

    #[Test]
    public function the_plain_style_leaves_the_pattern_off(): void
    {
        $this->designs([], ['style' => 'plain']);

        $this->get($this->voucher()->printUrl())->assertSee('class="gv-card gv-plain"', false);
    }

    #[Test]
    public function each_design_has_a_card_for_the_preview(): void
    {
        $this->threeDesigns();

        $this->get('/!/gift-vouchers/designs/birthday/artwork')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->get('/!/gift-vouchers/designs/plain-simple/artwork')->assertNotFound();
        $this->get('/!/gift-vouchers/designs/nope/artwork')->assertNotFound();
    }

    #[Test]
    public function the_designs_tag_lists_them_escaped(): void
    {
        $this->threeDesigns();

        $html = $this->antlers('{{ gift_vouchers:designs }}[{{ handle }}|{{ name }}|{{ if is_selected }}x{{ /if }}]{{ /gift_vouchers:designs }}');

        $this->assertSame('[classic|Classic|x][birthday|Birthday|][plain-simple|Plain &amp; simple|]', $html);
    }

    #[Test]
    public function the_preview_is_the_voucher_card_ready_to_fill_in(): void
    {
        $this->threeDesigns();
        $this->openVoucher(1000, 20000, data: ['voucher_suggested_amounts' => ['25', '50']]);

        $html = $this->antlers('{{ gift_vouchers:preview product="voucher-open" form="buy" }}');

        $this->assertStringContainsString('data-gift-vouchers-preview', $html);
        $this->assertStringContainsString('class="gv-card gv-engraved"', $html);
        $this->assertStringContainsString('data-form="buy"', $html);
        $this->assertStringContainsString('data-currency="GBP"', $html);
        $this->assertStringContainsString('£25.00', $html);
        $this->assertStringContainsString('XXXX-XXXX-XXXX-XXXX', $html);
        $this->assertStringContainsString('/!/gift-vouchers/designs/classic/artwork?v=', $html);
        $this->assertStringContainsString('/!/gift-vouchers/preview.js?v=', $html);
        $this->assertStringContainsString('&quot;birthday&quot;', $html);

        $this->assertStringNotContainsString('<script', $this->antlers('{{ gift_vouchers:preview product="voucher-open" scripts="false" }}'));
        $this->assertSame('', $this->antlers('{{ gift_vouchers:preview product="missing" }}'));
    }

    #[Test]
    public function the_preview_script_is_served(): void
    {
        $this->get('/!/gift-vouchers/preview.js')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=UTF-8')
            ->assertSee('data-gift-vouchers-preview', false);
    }

    #[Test]
    public function the_artwork_tag_draws_a_design(): void
    {
        $this->threeDesigns();

        $this->assertStringStartsWith('<svg', $this->antlers('{{ gift_vouchers:artwork design="birthday" }}'));
        $this->assertStringStartsWith('<svg', $this->antlers('{{ gift_vouchers:artwork design="birthday" shape="emblem" }}'));
        $this->assertSame('', $this->antlers('{{ gift_vouchers:artwork design="plain-simple" }}'));
    }
}
