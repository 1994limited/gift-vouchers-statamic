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
use NineteenNinetyFour\GiftVouchers\Fieldtypes\GiftVoucherOrder;
use NineteenNinetyFour\GiftVouchers\Listeners\CargoSubscriber;
use NineteenNinetyFour\GiftVouchers\Tags\GiftVoucherTags;
use NineteenNinetyFour\GiftVouchers\Timeline\GiftVoucherEvent;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;
use Statamic\Statamic;

class ServiceProvider extends AddonServiceProvider
{
    /**
     * The Control Panel nav icon. A placeholder gift tag until the brand
     * kit's icon is ready (resources/brand/nav-icon.svg).
     */
    public const ICON = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 14"><path d="M1.5 7.2V2.5a1 1 0 0 1 1-1h4.7l5.3 5.3a1 1 0 0 1 0 1.4l-4.3 4.3a1 1 0 0 1-1.4 0Z" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round"/><circle cx="4.5" cy="4.5" r="1" stroke="currentColor" stroke-width="1"/></svg>';

    protected $tags = [
        GiftVoucherTags::class,
    ];

    protected $commands = [
        Install::class,
        SendScheduled::class,
    ];

    protected $fieldtypes = [
        GiftVoucherOrder::class,
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
