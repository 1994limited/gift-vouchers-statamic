<?php

namespace NineteenNinetyFour\GiftVouchers\Tests\Feature;

use DuncanMcClean\Cargo\Facades\TaxClass;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use NineteenNinetyFour\GiftVouchers\Mail\VoucherMail;
use NineteenNinetyFour\GiftVouchers\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\YAML;

class CommandsTest extends TestCase
{
    protected function tearDown(): void
    {
        File::deleteDirectory(resource_path('blueprints/collections/products'));

        parent::tearDown();
    }

    #[Test]
    public function install_adds_the_blueprint_and_a_tax_class_without_rates(): void
    {
        $this->artisan('gift-vouchers:install', ['--no-migrate' => true])->assertSuccessful();

        $blueprint = YAML::file(resource_path('blueprints/collections/products/gift_voucher.yaml'))->parse();
        $this->assertSame('Gift voucher', $blueprint['title']);

        $taxClass = TaxClass::find('gift_vouchers');
        $this->assertSame('Gift vouchers', $taxClass->get('title'));
    }

    #[Test]
    public function install_leaves_an_existing_blueprint_alone_unless_forced(): void
    {
        File::ensureDirectoryExists(resource_path('blueprints/collections/products'));
        File::put(resource_path('blueprints/collections/products/gift_voucher.yaml'), 'title: Mine');

        $this->artisan('gift-vouchers:install', ['--no-migrate' => true])->assertSuccessful();
        $this->assertSame('title: Mine', File::get(resource_path('blueprints/collections/products/gift_voucher.yaml')));

        $this->artisan('gift-vouchers:install', ['--no-migrate' => true, '--force' => true])->assertSuccessful();
        $this->assertStringContainsString('voucher_amount_type', File::get(resource_path('blueprints/collections/products/gift_voucher.yaml')));
    }

    #[Test]
    public function install_needs_the_collection_to_exist(): void
    {
        $this->artisan('gift-vouchers:install', ['--no-migrate' => true, '--collection' => 'nope'])->assertFailed();
    }

    #[Test]
    public function send_emails_vouchers_whose_day_has_come(): void
    {
        Mail::fake();

        $due = $this->voucher(1000, ['recipient_email' => 'due@example.com', 'send_on' => now()->toDateString()]);
        $later = $this->voucher(1000, ['recipient_email' => 'later@example.com', 'send_on' => now()->addDay()->toDateString()]);
        $disabled = $this->voucher(1000, ['recipient_email' => 'off@example.com', 'send_on' => now()->toDateString(), 'disabled_at' => now()]);
        $this->voucher(1000, ['recipient_email' => 'sent@example.com', 'send_on' => now()->subDay()->toDateString(), 'sent_at' => now()->subDay()]);

        $this->artisan('gift-vouchers:send')->expectsOutputToContain('Sent 1 of 1')->assertSuccessful();

        Mail::assertSent(VoucherMail::class, 1);
        Mail::assertSent(VoucherMail::class, fn ($mail) => $mail->hasTo('due@example.com'));
        $this->assertNotNull($due->fresh()->sent_at);
        $this->assertNull($later->fresh()->sent_at);
        $this->assertNull($disabled->fresh()->sent_at);
    }

    #[Test]
    public function send_fails_if_a_voucher_could_not_be_sent(): void
    {
        Mail::fake();

        // A voucher bought on an order that's gone, with no recipient.
        $this->voucher(1000, ['send_on' => now()->toDateString(), 'order_id' => 'gone']);

        $this->artisan('gift-vouchers:send')->expectsOutputToContain('Sent 0 of 1')->assertFailed();
    }

    #[Test]
    public function sending_is_scheduled(): void
    {
        $events = collect(app(Schedule::class)->events());

        $this->assertTrue($events->contains(fn ($event) => str_contains($event->command, 'gift-vouchers:send') && $event->expression === '*/15 * * * *'));
    }
}
