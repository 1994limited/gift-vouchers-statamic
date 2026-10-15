<?php

namespace NineteenNinetyFour\GiftVouchers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use NineteenNinetyFour\GiftVouchers\Actions\RefundToGiftVoucher;
use NineteenNinetyFour\GiftVouchers\Cargo\CartVouchers;
use NineteenNinetyFour\GiftVouchers\Cargo\OrderVouchers;
use NineteenNinetyFour\GiftVouchers\Cargo\PriceHook;
use NineteenNinetyFour\GiftVouchers\Cargo\Products;
use NineteenNinetyFour\GiftVouchers\Commands\Install;
use NineteenNinetyFour\GiftVouchers\Commands\SendScheduled;
use NineteenNinetyFour\GiftVouchers\Fieldtypes\GiftVoucherDesign;
use NineteenNinetyFour\GiftVouchers\Fieldtypes\GiftVoucherOrder;
use NineteenNinetyFour\GiftVouchers\Listeners\CargoSubscriber;
use NineteenNinetyFour\GiftVouchers\Tags\GiftVoucherTags;
use NineteenNinetyFour\GiftVouchers\Timeline\GiftVoucherEvent;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Facades\YAML;
use Statamic\Providers\AddonServiceProvider;
use Statamic\Statamic;

class ServiceProvider extends AddonServiceProvider
{
    /** The Control Panel nav icon (resources/brand/nav-icon.svg). */
    public const ICON = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 14"><path d="M4.5 1.5H9.5L11 3.5V10L8.5 12.5H3V3.5Z" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round"></path><path d="M11 10H8.5V12.5" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round"></path><circle cx="7" cy="4.3" r=".75" stroke="currentColor" stroke-width="1"></circle></svg>';

    protected $tags = [
        GiftVoucherTags::class,
    ];

    protected $commands = [
        Install::class,
        SendScheduled::class,
    ];

    protected $fieldtypes = [
        GiftVoucherOrder::class,
        GiftVoucherDesign::class,
    ];

    protected $actions = [
        RefundToGiftVoucher::class,
    ];

    protected $subscribe = [
        CargoSubscriber::class,
    ];

    protected $vite = [
        'input' => [
            'resources/js/cp.js',
            'resources/css/cp.css',
        ],
        'publicDirectory' => 'resources/dist',
        'hotFile' => __DIR__.'/../resources/dist/hot',
    ];

    /**
     * The package is 1994/gift-vouchers-statamic; its config, views and
     * translations are read as "gift-vouchers", so they're loaded by hand.
     * Routes too: actions live at /!/gift-vouchers.
     */
    protected $config = false;

    protected $translations = false;

    protected $routes = [
        'web' => null,
        'cp' => __DIR__.'/../routes/cp.php',
        'actions' => null,
    ];

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/gift-vouchers.php', 'gift-vouchers');

        $this->app->singleton(Ledger::class);
        $this->app->singleton(Products::class);
        $this->app->singleton(CartVouchers::class);
        $this->app->singleton(OrderVouchers::class);
        $this->app->singleton(Delivery::class);
        $this->app->singleton(Presenter::class);
        $this->app->singleton(Pdf::class);
        $this->app->singleton(GiftVouchers::class);
        $this->app->singleton(Designs::class);
    }

    public function bootAddon(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'gift-vouchers');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'gift-vouchers');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([__DIR__.'/../config/gift-vouchers.php' => config_path('gift-vouchers.php')], 'gift-vouchers-config');
        $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/gift-vouchers')], 'gift-vouchers-views');
        $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'gift-vouchers-migrations');

        GiftVoucherEvent::register();

        // Cargo's price hook, set once everything (the site included) has
        // booted, keeping any hook the site set itself.
        $this->app->booted(fn () => PriceHook::install());

        $this->registerFrontendRoutes();
        $this->registerSchedule();
        $this->registerSettings();

        Permission::extend(function () {
            Permission::group('gift-vouchers', __('gift-vouchers::messages.cp.gift_vouchers'), function () {
                Permission::register('view gift vouchers', function ($permission) {
                    $permission->label(__('gift-vouchers::messages.permissions.view'))->children([
                        Permission::make('create gift vouchers')->label(__('gift-vouchers::messages.permissions.create')),
                        Permission::make('edit gift vouchers')->label(__('gift-vouchers::messages.permissions.edit')),
                    ]);
                });
            });
        });

        Nav::extend(function ($nav) {
            $nav->create(__('gift-vouchers::messages.cp.gift_vouchers'))
                ->section('Store')
                ->route('gift-vouchers.index')
                ->icon(self::ICON)
                ->can('view gift vouchers');
        });
    }

    /**
     * Tools → Addons → Gift Vouchers → Settings (resources/settings.yaml).
     * The logo and design images need an asset container: the first one,
     * if there are several. Without one, those fields are left out.
     */
    protected function registerSettings(): void
    {
        $this->registerSettingsBlueprint(function () {
            $blueprint = YAML::file(__DIR__.'/../resources/settings.yaml')->parse();
            $container = AssetContainer::all()->first()?->handle();

            $blueprint['tabs']['voucher']['sections'][0]['fields'] = $this->withContainer($blueprint['tabs']['voucher']['sections'][0]['fields'], $container);

            foreach ($blueprint['tabs']['designs']['sections'][0]['fields'] as &$field) {
                if ($field['handle'] === 'designs') {
                    $field['field']['fields'] = $this->withContainer($field['field']['fields'], $container);
                }
            }

            return $blueprint;
        });
    }

    /**
     * Point these fields' assets fields at the container, or drop them.
     */
    protected function withContainer(array $fields, ?string $container): array
    {
        return collect($fields)
            ->filter(fn (array $field) => $container || ($field['field']['type'] ?? null) !== 'assets')
            ->map(function (array $field) use ($container) {
                if (($field['field']['type'] ?? null) === 'assets') {
                    $field['field']['container'] = $container;
                }

                return $field;
            })
            ->values()
            ->all();
    }

    protected function registerFrontendRoutes(): void
    {
        // Using a code and checking a balance are limited per visitor, so
        // codes can't be guessed by trying them.
        RateLimiter::for('gift-vouchers-codes', fn (Request $request) => Limit::perMinute(max(1, (int) config('gift-vouchers.throttle.codes', 10)))->by($request->ip()));
        RateLimiter::for('gift-vouchers-cart', fn (Request $request) => Limit::perMinute(max(1, (int) config('gift-vouchers.throttle.cart', 30)))->by($request->ip()));

        Statamic::pushActionRoutes(function () {
            Route::prefix('gift-vouchers')->name('gift-vouchers.')->group(__DIR__.'/../routes/actions.php');
        });
    }

    protected function registerSchedule(): void
    {
        if (! config('gift-vouchers.schedule', true)) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('gift-vouchers:send')->everyFifteenMinutes()->withoutOverlapping();
        });
    }
}
