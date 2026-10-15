<?php

namespace NineteenNinetyFour\GiftVouchers\Commands;

use Illuminate\Console\Command;
use NineteenNinetyFour\GiftVouchers\Delivery;
use Statamic\Console\RunsInPlease;

/**
 * Emails vouchers whose send date has come. Runs every fifteen minutes with
 * Laravel's scheduler (gift-vouchers.schedule).
 */
class SendScheduled extends Command
{
    use RunsInPlease;

    protected $signature = 'gift-vouchers:send';

    protected $description = 'Email gift vouchers whose send date has come';

    public function handle(Delivery $delivery): int
    {
        $due = $delivery->due()->count();
        $sent = $delivery->sendDue();

        $this->components->info("Sent {$sent} of {$due} gift vouchers due.");

        return $sent === $due ? self::SUCCESS : self::FAILURE;
    }
}
