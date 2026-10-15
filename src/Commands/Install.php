<?php

namespace NineteenNinetyFour\GiftVouchers\Commands;

use DuncanMcClean\Cargo\Facades\TaxClass;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\Collection;

/**
 * Sets up a site to sell vouchers: a "Gift voucher" blueprint for products,
 * a tax class with no rates (vouchers are outside the scope of VAT when
 * sold), and the database tables.
 */
class Install extends Command
{
    use RunsInPlease;

    protected $signature = 'gift-vouchers:install
        {--collection= : The products collection to add the blueprint to}
        {--force : Replace the blueprint if it already exists}
        {--no-migrate : Don\'t run the migrations}';

    protected $description = 'Set up Gift Vouchers: a product blueprint, a tax class and the database tables';

    public function handle(): int
    {
        $collection = $this->option('collection') ?: collect(config('statamic.cargo.products.collections', ['products']))->first();

        if (! Collection::find($collection)) {
            $this->components->error("There's no [{$collection}] collection. Make your products collection first, or pass --collection.");

            return self::FAILURE;
        }

        if (! in_array($collection, (array) config('statamic.cargo.products.collections', []), true)) {
            $this->components->warn("[{$collection}] isn't one of Cargo's product collections (statamic.cargo.products.collections).");
        }

        $this->installBlueprint($collection);
        $this->installTaxClass();

        if (! $this->option('no-migrate')) {
            // Through Laravel's console, not $this->call(): under `php please`
            // that reaches Statamic's console, which has no migrate command.
            Artisan::call('migrate', ['--force' => true], $this->output);
        }

        $this->newLine();
        $this->components->info('Gift Vouchers is installed.');
        $this->line('  Next: make a product with the "Gift voucher" blueprint, then add the voucher form to its template.');
        $this->line('  The docs: docs/templates.md');

        return self::SUCCESS;
    }

    protected function installBlueprint(string $collection): void
    {
        $handle = collect(config('gift-vouchers.blueprints', ['gift_voucher']))->first();
        $path = resource_path("blueprints/collections/{$collection}/{$handle}.yaml");

        if (File::exists($path) && ! $this->option('force')) {
            $this->components->twoColumnDetail("Blueprint <comment>{$handle}</comment>", '<fg=yellow>already there</>');

            return;
        }

        File::ensureDirectoryExists(dirname($path));
        File::copy(__DIR__.'/../../resources/stubs/gift_voucher.yaml', $path);

        $this->components->twoColumnDetail("Blueprint <comment>{$handle}</comment>", '<fg=green>added</>');
    }

    protected function installTaxClass(): void
    {
        if (TaxClass::find('gift_vouchers')) {
            $this->components->twoColumnDetail('Tax class <comment>gift_vouchers</comment>', '<fg=yellow>already there</>');

            return;
        }

        TaxClass::make()->handle('gift_vouchers')->set('title', 'Gift vouchers')->save();

        $this->components->twoColumnDetail('Tax class <comment>gift_vouchers</comment>', '<fg=green>added, with no rates</>');
    }
}
