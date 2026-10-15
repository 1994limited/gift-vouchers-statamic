# Installation

## Requirements

- PHP 8.3 or later.
- Statamic 6.35.1 or later.
- [Cargo](https://builtwithcargo.dev) 1.15 or later, installed and set up (`php please cargo:install`).
- A database. Vouchers and their history live in two tables, even if Cargo keeps orders in flat files: spending a balance safely needs a database's locks. SQLite (Statamic's default `database/database.sqlite`) is fine.
- For send dates: Laravel's scheduler running (`* * * * * php artisan schedule:run`).
- Optional: PHP's Imagick extension. With it, emails show the design's emblem; without it, they don't, and nothing else changes.

## Installing

Search for **Gift Vouchers** in **Tools → Addons** in the Control Panel and install it, or run:

```bash
composer require 1994/gift-vouchers-statamic
```

Then run the installer:

```bash
php please gift-vouchers:install
```

It does three things:

1. Adds a **Gift voucher** blueprint to your products collection (`resources/blueprints/collections/products/gift_voucher.yaml`). Pass `--collection=` to choose another of Cargo's product collections.
2. Adds a **Gift vouchers** tax class with no rates. Gift vouchers are usually outside the scope of VAT when they're sold, and taxed when they're spent. Check with your accountant.
3. Runs `php artisan migrate`, making the `gift_vouchers` and `gift_voucher_transactions` tables (and running any other migrations your site has waiting). Pass `--no-migrate` to run them yourself.

**Store → Gift Vouchers** appears in the Control Panel. Next:

- Make a product with the **Gift voucher** blueprint. See [Voucher products](products.md).
- Add the voucher form to its template, and a box for codes to your basket or checkout. See [Templates](templates.md), or [Cargo's prebuilt checkout](prebuilt-checkout.md) if you use it.
- Set the voucher's look and wording: **Tools → Addons → Gift Vouchers → Settings**. See [Designs](designs.md).

### Publishing the config

To change any option, publish the config file:

```bash
php artisan vendor:publish --tag=gift-vouchers-config
```

This writes `config/gift-vouchers.php`. See [Configuration](configuration.md).

## Licensing

Gift Vouchers is a commercial addon, sold through the [Statamic Marketplace](https://statamic.com/marketplace). You can install it and try it on a local or development site without a licence. A site on a public domain needs a licence: buy one from the Marketplace and add it to the site on [statamic.com](https://statamic.com). Statamic shows a warning in the Control Panel when a production site uses an unlicensed addon.

One licence covers one site. See [LICENSE.md](../LICENSE.md) for the terms.

## Updating

```bash
composer update 1994/gift-vouchers-statamic
php artisan migrate
```

Statamic republishes the Control Panel's assets on `composer update`. If the Gift Vouchers pages look wrong after an update, run `php artisan vendor:publish --tag=gift-vouchers-statamic --force`.

If you've published the views, compare them with the new ones: the [changelog](../CHANGELOG.md) says when they change.

## Uninstalling

Vouchers are money your customers have paid for, so think before removing the addon: their codes stop working at checkout.

```bash
composer remove 1994/gift-vouchers-statamic
```

Then remove the `{{ gift_vouchers:* }}` tags and partials from your templates. The `gift_vouchers` and `gift_voucher_transactions` tables stay: export them first (**Download CSV** on the Gift Vouchers page) and drop them when you no longer need them.

Orders that used vouchers have gift voucher entries on their timeline. Cargo can't show those without the addon, so either keep it installed while you need old orders' timelines, or remove the entries (type `gift_voucher`) from those orders.
