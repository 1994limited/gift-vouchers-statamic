# The demo shop

**Paper & Ink** is a small, made-up stationery shop built with Gift Vouchers and Cargo, at [1994limited/gift-vouchers-statamic-demo](https://github.com/1994limited/gift-vouchers-statamic-demo). Every screenshot in these docs comes from it. Run it to try Gift Vouchers before you build with it, or copy its templates.

![Paper & Ink's gift voucher page, with the live preview](images/shop-voucher.png)

It shows:

- **A voucher where the shopper chooses the amount**, with quick amounts, a choice of four designs (Classic, Birthday, Thank you, Christmas), and the live preview.
- **A £25 voucher**, for a set amount, next to ordinary products.
- **The basket**, with a box for codes, vouchers taken off the total, and each voucher's recipient and message.
- **Cargo's prebuilt checkout**, with the Gift Vouchers partials added.
- **A balance checker.**
- **The Control Panel**, with a few weeks of made-up orders: vouchers bought as presents, spent over several orders, refunded, expired, disabled, scheduled and made by hand.

## Running it

You need PHP 8.3+ and Composer.

```bash
git clone https://github.com/1994limited/gift-vouchers-statamic-demo.git
cd gift-vouchers-statamic-demo
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan demo:users
php artisan demo:seed
php artisan serve
```

Open http://localhost:8000. `demo:users` makes `admin@example.com`, for the Control Panel at `/cp`, and `ada@example.com`, a shopper. Both use the password `password`, so it only runs with `APP_ENV=local`. Running Gift Vouchers locally needs no licence.

`demo:seed --fresh` clears the demo's orders and vouchers and makes them again. Checkout uses Cargo's dummy gateway: nothing is charged.

To see the emails, set up a mail catcher (see [Emails](emails.md#testing-emails-locally)).

## Where to look

| File | Shows |
| --- | --- |
| `resources/views/products/show.antlers.html` | The voucher form, quick amounts, designs and live preview |
| `resources/views/basket.antlers.html` | The code box, vouchers with the totals, and voucher details from line items |
| `resources/views/balance.antlers.html` | The balance checker |
| `resources/views/checkout/components/_cart_summary.antlers.html` | Cargo's prebuilt checkout, with `cargo-checkout` and `cargo-checkout-lines` |
| `resources/views/checkout/confirmation.antlers.html` | The vouchers bought, on the confirmation page |
| `app/Console/Commands/DemoSeed.php` | Orders made through Cargo's real checkout |
| `public/css/shop.css` | The shop's styles |

The demo's own code is MIT licensed, so copy whatever helps.
