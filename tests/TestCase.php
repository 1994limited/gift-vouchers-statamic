<?php

namespace NineteenNinetyFour\GiftVouchers\Tests;

use DuncanMcClean\Cargo\Cart\Calculator\CalculateLineItems;
use DuncanMcClean\Cargo\Contracts\Cart\Cart as CartContract;
use DuncanMcClean\Cargo\Contracts\Orders\Order as OrderContract;
use DuncanMcClean\Cargo\Contracts\Products\Product as ProductContract;
use DuncanMcClean\Cargo\Discounts\DiscountServiceProvider;
use DuncanMcClean\Cargo\Facades\Cart;
use DuncanMcClean\Cargo\Facades\PaymentGateway;
use DuncanMcClean\Cargo\Facades\Product;
use DuncanMcClean\Cargo\Orders\Actions\CreateOrderFromCart;
use DuncanMcClean\Cargo\Orders\OrderServiceProvider;
use DuncanMcClean\Cargo\Payments\PaymentServiceProvider;
use DuncanMcClean\Cargo\ServiceProvider as CargoServiceProvider;
use DuncanMcClean\Cargo\Shipping\ShippingServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use NineteenNinetyFour\GiftVouchers\Cargo\PriceHook;
use NineteenNinetyFour\GiftVouchers\Ledger;
use NineteenNinetyFour\GiftVouchers\ServiceProvider;
use NineteenNinetyFour\GiftVouchers\Voucher;
use Statamic\Addons\Manifest;
use Statamic\Facades\Antlers;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\FakesRoles;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Stillat\Proteus\WriterServiceProvider;

abstract class TestCase extends AddonTestCase
{
    use FakesRoles, PreventsSavingStacheItemsToDisk, RefreshDatabase;

    protected string $addonServiceProvider = ServiceProvider::class;

    /** Where Cargo keeps carts and orders, and blueprints are read from, during a test. */
    protected static string $content = __DIR__.'/__fixtures__/tmp';

    protected function getPackageProviders($app)
    {
        // Cargo first, as a site would have it: this addon depends on it.
        return array_merge([
            CargoServiceProvider::class,
            DiscountServiceProvider::class,
            OrderServiceProvider::class,
            PaymentServiceProvider::class,
            ShippingServiceProvider::class,
            WriterServiceProvider::class,
        ], parent::getPackageProviders($app));
    }

    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('statamic.editions.pro', true);
        $app['config']->set('statamic.cargo', require __DIR__.'/../vendor/duncanmcclean/statamic-cargo/config/cargo.php');

        Route::get('checkout', fn () => 'Checkout')->name('checkout');
        Route::get('checkout/confirmation', fn () => 'Confirmation')->name('checkout.confirmation');
    }

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        // Statamic boots only the addons in its manifest, and the test case
        // lists just this one. Cargo is installed alongside it on a site.
        $app->make(Manifest::class)->manifest['duncanmcclean/statamic-cargo'] = [
            'id' => 'duncanmcclean/statamic-cargo',
            'slug' => 'cargo',
            'version' => '1.15.0',
            'namespace' => 'DuncanMcClean\\Cargo',
            'autoload' => 'src',
            'provider' => CargoServiceProvider::class,
        ];

        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        $app['config']->set('statamic.cargo.carts.directory', static::$content.'/carts');
        $app['config']->set('statamic.cargo.orders.directory', static::$content.'/orders');
        $app['config']->set('statamic.cargo.discounts.directory', static::$content.'/discounts');
        $app['config']->set('statamic.cargo.taxes.tax_classes.directory', static::$content.'/tax-classes');
        $app['config']->set('statamic.cargo.taxes.tax_zones.directory', static::$content.'/tax-zones');
        $app['config']->set('mail.default', 'array');
    }

    protected function setUp(): void
    {
        $files = new Filesystem;
        $files->deleteDirectory(static::$content);
        $files->ensureDirectoryExists(static::$content.'/blueprints/collections/products');
        $files->copy(__DIR__.'/../resources/stubs/gift_voucher.yaml', static::$content.'/blueprints/collections/products/gift_voucher.yaml');
        $files->put(static::$content.'/blueprints/collections/products/product.yaml', "title: Product\nfields:\n  -\n    handle: title\n    field:\n      type: text\n");

        parent::setUp();

        Site::setSites([
            'default' => ['name' => 'Bookshop', 'url' => '/', 'locale' => 'en_GB', 'attributes' => ['currency' => 'GBP']],
        ])->save();

        Blueprint::setDirectory(static::$content.'/blueprints');

        Collection::make('products')->routes('/products/{slug}')->save();

        // Cargo's price hook is static, so each test starts with a fresh one.
        CalculateLineItems::priceHook(null);
        PriceHook::uninstall();
        PriceHook::install();
    }

    protected function tearDown(): void
    {
        Cart::forgetCurrentCart();
        CalculateLineItems::priceHook(null);
        PriceHook::uninstall();

        parent::tearDown();

        (new Filesystem)->deleteDirectory(static::$content);
    }

    /**
     * A voucher product sold for a set amount.
     */
    protected function fixedVoucher(int $price = 2500, string $id = 'voucher-25', array $data = []): ProductContract
    {
        return $this->productEntry($id, 'gift_voucher', array_merge(['title' => 'Gift voucher', 'voucher_amount_type' => 'fixed', 'price' => $price], $data));
    }

    /**
     * A voucher product where the shopper chooses the amount.
     */
    protected function openVoucher(int $min = 1000, int $max = 20000, string $id = 'voucher-open', array $data = []): ProductContract
    {
        return $this->productEntry($id, 'gift_voucher', array_merge(['title' => 'Choose your amount', 'voucher_amount_type' => 'open', 'voucher_min' => $min, 'voucher_max' => $max], $data));
    }

    /**
     * An ordinary (physical) product.
     */
    protected function product(int $price = 1500, string $id = 'book'): ProductContract
    {
        return $this->productEntry($id, 'product', ['title' => 'A book', 'price' => $price]);
    }

    protected function productEntry(string $id, string $blueprint, array $data): ProductContract
    {
        Entry::make()->collection('products')->blueprint($blueprint)->id($id)->slug($id)->data($data)->save();

        return Product::find($id);
    }

    /**
     * A cart with a customer and address, ready for checkout, made current.
     *
     * @param  array<int, array<string, mixed>>  $lineItems
     */
    protected function cart(array $lineItems = []): CartContract
    {
        $cart = Cart::make()
            ->customer(['name' => 'Ada Lovelace', 'email' => 'ada@example.com'])
            ->set('billing_address', ['line_1' => '1 High Street', 'city' => 'London', 'postcode' => 'N1 1AA', 'country' => 'GBR']);

        foreach ($lineItems as $lineItem) {
            $cart->lineItems()->create(array_merge(['quantity' => 1], $lineItem));
        }

        $cart->save();
        Cart::setCurrent($cart);

        return $cart;
    }

    /**
     * Check out a cart: free orders straight through, others with Cargo's
     * dummy gateway (which marks them paid).
     */
    protected function checkout(CartContract $cart): OrderContract
    {
        $cart = Cart::find($cart->id());

        return app(CreateOrderFromCart::class)->handle($cart, $cart->isPaid() ? PaymentGateway::find('dummy') : null);
    }

    /**
     * A voucher with a balance, made as the Control Panel makes them.
     */
    protected function voucher(int $amount = 5000, array $attributes = [], ?string $code = null, string $currency = 'GBP'): Voucher
    {
        return app(Ledger::class)->issue($amount, $currency, $attributes, $code);
    }

    protected function user(string $email = 'admin@example.com', array $permissions = []): \Statamic\Contracts\Auth\User
    {
        if ($permissions) {
            $this->setTestRoles(['tester' => ['access cp', ...$permissions]]);
        }

        $user = User::make()->email($email)->id((string) str($email)->before('@')->slug());

        if ($permissions) {
            $user->assignRole('tester');
        }

        $user->save();

        return $user;
    }

    /**
     * Render Antlers as a site template would (trusted, so tags run).
     */
    protected function antlers(string $template, array $data = []): string
    {
        return (string) Antlers::parse($template, $data, true);
    }
}
