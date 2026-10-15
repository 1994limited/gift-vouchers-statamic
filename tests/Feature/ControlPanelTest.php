<?php

namespace NineteenNinetyFour\GiftVouchers\Tests\Feature;

use DuncanMcClean\Cargo\Facades\Order;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use NineteenNinetyFour\GiftVouchers\Cargo\CartVouchers;
use NineteenNinetyFour\GiftVouchers\Codes;
use NineteenNinetyFour\GiftVouchers\Designs;
use NineteenNinetyFour\GiftVouchers\Fieldtypes\GiftVoucherOrder;
use NineteenNinetyFour\GiftVouchers\Ledger;
use NineteenNinetyFour\GiftVouchers\Mail\VoucherMail;
use NineteenNinetyFour\GiftVouchers\ServiceProvider;
use NineteenNinetyFour\GiftVouchers\Settings;
use NineteenNinetyFour\GiftVouchers\Tests\TestCase;
use NineteenNinetyFour\GiftVouchers\Transaction;
use NineteenNinetyFour\GiftVouchers\TransactionType;
use NineteenNinetyFour\GiftVouchers\Voucher;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Auth\User;
use Statamic\Facades\Addon;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Permission;
use Statamic\Facades\User as UserFacade;
use Statamic\Fields\Field;

class ControlPanelTest extends TestCase
{
    protected function viewer(): User
    {
        return $this->user('viewer@example.com', ['view gift vouchers']);
    }

    protected function editor(): User
    {
        return $this->user('editor@example.com', ['view gift vouchers', 'edit gift vouchers', 'create gift vouchers']);
    }

    #[Test]
    public function every_page_needs_permission(): void
    {
        $voucher = $this->voucher();
        $this->actingAs($this->user('nobody@example.com', ['access cp']));

        $this->getJson(cp_route('gift-vouchers.index'))->assertForbidden();
        $this->getJson(cp_route('gift-vouchers.show', $voucher->id))->assertForbidden();
        $this->getJson(cp_route('gift-vouchers.export'))->assertForbidden();
        $this->getJson(cp_route('gift-vouchers.create'))->assertForbidden();
        $this->postJson(cp_route('gift-vouchers.store'), ['amount' => '10'])->assertForbidden();
    }

    #[Test]
    public function viewing_does_not_allow_changing_or_seeing_codes(): void
    {
        $voucher = $this->voucher(5000);
        $this->actingAs($this->viewer());

        $this->postJson(cp_route('gift-vouchers.code', $voucher->id))->assertForbidden();
        $this->postJson(cp_route('gift-vouchers.adjust', $voucher->id), ['direction' => 'add', 'amount' => '10', 'note' => 'x'])->assertForbidden();
        $this->postJson(cp_route('gift-vouchers.disable', $voucher->id))->assertForbidden();
        $this->postJson(cp_route('gift-vouchers.send', $voucher->id))->assertForbidden();
        $this->patchJson(cp_route('gift-vouchers.update', $voucher->id), [])->assertForbidden();
        $this->getJson(cp_route('gift-vouchers.create'))->assertForbidden();

        $this->assertSame(5000, $voucher->fresh()->balance);
    }

    #[Test]
    public function the_list_shows_vouchers_and_what_is_still_owed(): void
    {
        $this->voucher(5000, ['recipient_name' => 'Grace']);
        $spent = $this->voucher(1000);
        app(Ledger::class)->debit($spent, 1000);
        $this->voucher(2500, ['currency' => 'EUR'], currency: 'EUR');
        $this->voucher(9999, ['disabled_at' => now()]);

        $this->actingAs($this->viewer())
            ->get(cp_route('gift-vouchers.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('gift-vouchers::Index')
                ->has('vouchers', 4)
                ->where('pagination.total', 4)
                ->where('outstanding', [
                    ['currency' => 'EUR', 'amount' => '€25.00', 'count' => 1],
                    ['currency' => 'GBP', 'amount' => '£50.00', 'count' => 1],
                ])
                ->where('priceHookInstalled', true)
                ->where('canCreate', false)
                ->etc());
    }

    #[Test]
    public function the_list_never_shows_whole_codes(): void
    {
        $voucher = $this->voucher(5000);

        $content = $this->actingAs($this->editor())->get(cp_route('gift-vouchers.index'))->getContent();

        $this->assertStringNotContainsString(Codes::normalize($voucher->code()), $content);
        $this->assertStringNotContainsString($voucher->code(), $content);
    }

    #[Test]
    public function the_list_is_searched_by_code_end_recipient_or_order(): void
    {
        $grace = $this->voucher(5000, ['recipient_name' => 'Grace Hopper', 'recipient_email' => 'grace@example.com'], 'GRACE-0001');
        $this->voucher(5000, ['recipient_name' => 'Alan Turing']);
        $this->actingAs($this->viewer());

        foreach (['GRACE-0001', 'grace0001', '0001', 'hopper', 'grace@ex', '%', '_'] as $search) {
            $expected = in_array($search, ['%', '_']) ? 0 : 1;

            $this->get(cp_route('gift-vouchers.index', ['search' => $search]))
                ->assertInertia(fn (AssertableInertia $page) => $page->has('vouchers', $expected)->etc());
        }

        $this->get(cp_route('gift-vouchers.index', ['search' => ['array']]))->assertOk();
    }

    #[Test]
    public function the_list_is_filtered_by_status(): void
    {
        $this->voucher(5000);
        $this->voucher(5000, ['expires_at' => now()->subDay()]);
        $this->voucher(5000, ['disabled_at' => now()]);
        app(Ledger::class)->debit($this->voucher(100), 100);
        $this->actingAs($this->viewer());

        foreach (['active' => 1, 'expired' => 1, 'disabled' => 1, 'spent' => 1, 'nonsense' => 4] as $status => $count) {
            $this->get(cp_route('gift-vouchers.index', ['status' => $status]))
                ->assertInertia(fn (AssertableInertia $page) => $page->has('vouchers', $count)->etc());
        }
    }

    #[Test]
    public function the_list_downloads_as_a_spreadsheet_safe_from_formulas(): void
    {
        $this->voucher(5000, ['recipient_name' => '=HYPERLINK("http://evil")']);

        $response = $this->actingAs($this->viewer())->get(cp_route('gift-vouchers.export'))->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('Code,Status,Currency,Amount,Balance', $csv);
        $this->assertStringContainsString('50.00', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    #[Test]
    public function a_voucher_shows_its_history(): void
    {
        $voucher = $this->voucher(5000, ['recipient_name' => 'Grace']);
        app(Ledger::class)->debit($voucher, 1200, null, TransactionType::Adjusted, 'editor', 'Mistake');

        $this->actingAs($this->viewer())
            ->get(cp_route('gift-vouchers.show', $voucher->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('gift-vouchers::Show')
                ->where('voucher.balance', '£38.00')
                ->where('voucher.recipient_name', 'Grace')
                ->has('transactions', 2)
                ->where('transactions.0.label', 'Adjusted')
                ->where('transactions.0.amount', '−£12.00')
                ->where('transactions.0.note', 'Mistake')
                ->where('transactions.1.label', 'Issued')
                ->where('canEdit', false)
                ->where('urls.print', null)
                ->etc());

        $this->get(cp_route('gift-vouchers.show', 'missing'))->assertNotFound();
    }

    #[Test]
    public function an_editor_can_see_the_code_when_asked(): void
    {
        $voucher = $this->voucher(5000, code: 'SEE-ME-123');

        $this->actingAs($this->editor())
            ->post(cp_route('gift-vouchers.code', $voucher->id))
            ->assertOk()
            ->assertExactJson(['code' => 'SEEM-E123'])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    #[Test]
    public function a_voucher_is_created_by_hand(): void
    {
        Mail::fake();

        $this->actingAs($this->editor())
            ->post(cp_route('gift-vouchers.store'), [
                'amount' => '75.50',
                'recipient_name' => 'Grace',
                'recipient_email' => 'grace@example.com',
                'message' => 'Sorry about the delay.',
                'expires_at' => now()->addYear()->toDateString(),
                'code' => 'SORRY-GRACE',
                'note' => 'Complaint #42',
                'send' => true,
            ])
            ->assertRedirect();

        $voucher = Voucher::sole();
        $this->assertSame(7550, $voucher->balance);
        $this->assertSame('GBP', $voucher->currency);
        $this->assertSame('SORR-YGRA-CE', $voucher->code());
        $this->assertSame('editor', $voucher->created_by);
        $this->assertSame(now()->addYear()->toDateString(), $voucher->expires_at->toDateString());
        $this->assertSame('Complaint #42', $voucher->transactions()->sole()->note);
        Mail::assertSent(VoucherMail::class, fn ($mail) => $mail->hasTo('grace@example.com'));
    }

    #[Test]
    public function a_send_date_is_ignored_unless_the_voucher_is_to_be_emailed(): void
    {
        Mail::fake();
        $this->actingAs($this->editor())->post(cp_route('gift-vouchers.store'), [
            'amount' => '10',
            'recipient_email' => 'grace@example.com',
            'send_on' => now()->addWeek()->toDateString(),
        ]);

        $this->assertNull(Voucher::sole()->send_on);

        $this->travel(8)->days();
        $this->artisan('gift-vouchers:send')->assertSuccessful();
        Mail::assertNothingSent();
    }

    #[Test]
    public function creating_by_hand_checks_what_was_entered(): void
    {
        $this->voucher(100, code: 'TAKEN-CODE');
        $this->actingAs($this->editor());

        $this->post(cp_route('gift-vouchers.store'), ['amount' => '0'])->assertSessionHasErrors('amount');
        $this->post(cp_route('gift-vouchers.store'), ['amount' => 'lots'])->assertSessionHasErrors('amount');
        $this->post(cp_route('gift-vouchers.store'), ['amount' => '10', 'code' => 'taken code'])->assertSessionHasErrors('code');
        $this->post(cp_route('gift-vouchers.store'), ['amount' => '10', 'code' => 'ab'])->assertSessionHasErrors('code');
        $this->post(cp_route('gift-vouchers.store'), ['amount' => '10', 'recipient_email' => 'nope'])->assertSessionHasErrors('recipient_email');
        $this->post(cp_route('gift-vouchers.store'), ['amount' => '10', 'send' => true])->assertSessionHasErrors('recipient_email');
        $this->post(cp_route('gift-vouchers.store'), ['amount' => '10', 'expires_at' => '2001-01-01'])->assertSessionHasErrors('expires_at');

        $this->assertSame(1, Voucher::count());
    }

    #[Test]
    public function creating_needs_its_own_permission(): void
    {
        $this->actingAs($this->user('e@example.com', ['view gift vouchers', 'edit gift vouchers']))
            ->postJson(cp_route('gift-vouchers.store'), ['amount' => '10'])
            ->assertForbidden();
    }

    #[Test]
    public function a_balance_is_changed_with_a_note(): void
    {
        $voucher = $this->voucher(5000);
        $this->actingAs($this->editor());

        $this->post(cp_route('gift-vouchers.adjust', $voucher->id), ['direction' => 'add', 'amount' => '10', 'note' => 'Goodwill'])->assertRedirect();
        $this->assertSame(6000, $voucher->fresh()->balance);

        $this->post(cp_route('gift-vouchers.adjust', $voucher->id), ['direction' => 'remove', 'amount' => '60.01', 'note' => 'Too much'])->assertSessionHasErrors('amount');
        $this->post(cp_route('gift-vouchers.adjust', $voucher->id), ['direction' => 'remove', 'amount' => '20', 'note' => ''])->assertSessionHasErrors('note');
        $this->post(cp_route('gift-vouchers.adjust', $voucher->id), ['direction' => 'sideways', 'amount' => '20', 'note' => 'x'])->assertSessionHasErrors('direction');

        $this->assertSame(6000, $voucher->fresh()->balance);
        $this->assertSame('editor', Transaction::where('type', 'adjusted')->sole()->user_id);
    }

    #[Test]
    public function a_voucher_is_disabled_and_enabled(): void
    {
        $voucher = $this->voucher(5000);
        $this->actingAs($this->editor());

        $this->post(cp_route('gift-vouchers.disable', $voucher->id))->assertRedirect();
        $this->assertFalse($voucher->fresh()->isUsable());

        $this->post(cp_route('gift-vouchers.enable', $voucher->id))->assertRedirect();
        $this->assertTrue($voucher->fresh()->isUsable());
    }

    #[Test]
    public function the_recipient_and_expiry_are_edited(): void
    {
        $voucher = $this->voucher(5000);

        $this->actingAs($this->editor())->patch(cp_route('gift-vouchers.update', $voucher->id), [
            'recipient_name' => '  Grace  ',
            'recipient_email' => 'grace@example.com',
            'message' => 'Hi',
            'expires_at' => '2030-01-31',
            'notes' => 'Phoned in',
        ])->assertRedirect();

        $voucher = $voucher->fresh();
        $this->assertSame('Grace', $voucher->recipient_name);
        $this->assertSame('2030-01-31', $voucher->expires_at->toDateString());
        $this->assertSame('Phoned in', $voucher->notes);
        $this->assertSame(5000, $voucher->balance);
    }

    #[Test]
    public function a_voucher_is_emailed_again(): void
    {
        Mail::fake();
        $voucher = $this->voucher(5000, ['recipient_email' => 'grace@example.com']);

        $this->actingAs($this->editor())->post(cp_route('gift-vouchers.send', $voucher->id))->assertRedirect();

        Mail::assertSent(VoucherMail::class);
        $this->assertNotNull($voucher->fresh()->sent_at);
    }

    #[Test]
    public function an_order_shows_the_vouchers_it_used_and_bought(): void
    {
        Mail::fake();
        $this->actingAs($this->viewer());

        $this->product(3000);
        $this->fixedVoucher(2500);
        $used = $this->voucher(1000);
        $cart = $this->cart([['product' => 'book'], ['product' => 'voucher-25']]);
        app(CartVouchers::class)->add($cart, $used->code());
        $order = Order::find($this->checkout($cart)->id());

        $field = (new Field('gift_voucher_details', ['type' => 'gift_voucher_order']))->setParent($order);
        $value = (new GiftVoucherOrder)->setField($field)->preProcess(null);

        $this->assertSame('•••• '.$used->code_hint, $value['used'][0]['code']);
        $this->assertSame('£10.00', $value['used'][0]['paid']);
        $this->assertSame('£10.00', $value['refundable']);
        $this->assertCount(1, $value['issued']);
        $this->assertSame('£25.00', $value['issued'][0]['amount']);
        $this->assertNotNull($value['issued'][0]['url']);

        $this->assertTrue($order->blueprint()->hasField('gift_voucher_details'));
    }

    #[Test]
    public function the_nav_and_permissions_are_registered(): void
    {
        $this->actingAs($this->viewer())->get(cp_route('gift-vouchers.index'))->assertOk();

        $this->assertNotNull(Permission::get('view gift vouchers'));
        $this->assertNotNull(Permission::get('edit gift vouchers'));
        $this->assertNotNull(Permission::get('create gift vouchers'));
    }

    #[Test]
    public function the_nav_icon_is_small_and_has_no_embedded_metadata(): void
    {
        // It's inlined into every Control Panel page.
        $this->assertLessThan(1024, strlen(ServiceProvider::ICON));
        $this->assertStringNotContainsString('<metadata', ServiceProvider::ICON);
        $this->assertSame(trim(file_get_contents(__DIR__.'/../../resources/brand/nav-icon.svg')), ServiceProvider::ICON);
    }

    #[Test]
    public function a_vouchers_design_shows_by_name_on_the_receipt(): void
    {
        $design = app(Designs::class)->all()->first();
        $field = (new Field('voucher_design', ['type' => 'gift_voucher_design']))->setValue($design->handle);

        $this->assertSame($design->name, $field->preProcessIndex()->value());
        $this->assertSame('gone', $field->setValue('gone')->preProcessIndex()->value());
    }

    #[Test]
    public function the_settings_page_opens_with_any_number_of_asset_containers(): void
    {
        $addon = Addon::get(Settings::ADDON);
        $admin = UserFacade::make()->email('admin@example.com')->id('admin')->makeSuper();
        $admin->save();
        $this->actingAs($admin);

        // None: no logo or design image fields.
        $this->get(cp_route('addons.settings.edit', $addon->slug()))->assertOk();
        $this->assertNull($addon->settingsBlueprint()->field('logo'));

        // One.
        Storage::fake('images');
        AssetContainer::make('images')->disk('images')->save();
        $this->get(cp_route('addons.settings.edit', $addon->slug()))->assertOk();

        // Several: the first is used.
        Storage::fake('files');
        AssetContainer::make('files')->disk('files')->save();
        $this->get(cp_route('addons.settings.edit', $addon->slug()))->assertOk();
    }

    #[Test]
    public function the_logo_and_design_images_come_from_the_first_asset_container(): void
    {
        Storage::fake('images');
        Storage::fake('files');
        AssetContainer::make('images')->disk('images')->save();
        AssetContainer::make('files')->disk('files')->save();
        Storage::disk('images')->put('logo.png', UploadedFile::fake()->image('logo.png')->getContent());
        Storage::disk('images')->put('birthday.jpg', UploadedFile::fake()->image('birthday.jpg')->getContent());

        Addon::get(Settings::ADDON)->settings()->set([
            'logo' => ['logo.png'],
            'designs' => [['id' => 'a', 'name' => 'Birthday', 'style' => 'image', 'image' => ['birthday.jpg']]],
        ])->save();

        $this->assertSame('images::logo.png', Settings::logo()?->id());

        $designs = app(Designs::class);
        $designs->forget();
        $this->assertSame('images::birthday.jpg', $designs->all()->first()->image?->id());
    }
}
