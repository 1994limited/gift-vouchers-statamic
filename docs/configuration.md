# Configuration

Publish the config file to change these:

```bash
php artisan vendor:publish --tag=gift-vouchers-config
```

The voucher's look and wording are set in the Control Panel instead: **Tools → Addons → Gift Vouchers → Settings** (see [Designs](designs.md)).

## Voucher products

```php
'blueprints' => ['gift_voucher'],
```

Products with one of these blueprints are vouchers. See [Voucher products](products.md).

## Amounts

```php
'amounts' => [
    'min' => 1000,
    'max' => 50000,
],
```

The limits for vouchers where the shopper chooses the amount, in minor units (pence): £10 to £500. Each product can set its own.

## Expiry

```php
'expiry_months' => null,
```

How many months vouchers last after they're made, or `null` for no expiry. Each product can set its own.

## Codes

```php
'codes' => [
    'length' => 16,
    'group' => 4,
    'prefix' => '',
],
```

- `length`: random characters in a code, 8 to 32. 16 gives about 78 bits.
- `group`: shown in groups of this many, with dashes. `0` for none.
- `prefix`: put in front of new codes, such as `GIFT`. Customers can leave it off when they type a code.

## Checkout

```php
'max_per_cart' => 5,
'voucher_value' => 'price',
'pay_for_vouchers' => false,
```

- `max_per_cart`: how many vouchers can be used on one order.
- `voucher_value`: what a voucher is worth if a Cargo discount applied to it: `'price'`, its full price (the shop pays the discount), or `'paid'`, what the shopper paid after the discount.
- `pay_for_vouchers`: whether vouchers can be spent on more vouchers.

## Orders

```php
'restore_on_cancel' => true,
'disable_on_full_refund' => true,
```

- `restore_on_cancel`: when an order is cancelled, put back what it spent from vouchers and disable the vouchers it bought.
- `disable_on_full_refund`: when an order is refunded in full, disable the vouchers it bought.

See [Orders and refunds](orders-and-refunds.md).

## Emails

```php
'email' => [
    'enabled' => true,
    'attach_pdf' => true,
    'bcc' => null,
    'queue' => null,
],

'schedule' => true,
```

See [Emails](emails.md). `schedule` runs `gift-vouchers:send` every fifteen minutes with Laravel's scheduler.

## PDF

```php
'pdf' => [
    'paper' => 'a4',
    'orientation' => 'portrait',
],
```

Any paper size Dompdf knows: `a4`, `letter`, `a5`…

## Views

```php
'views' => [
    'print' => 'gift-vouchers::voucher',
    'pdf' => 'gift-vouchers::voucher',
    'email' => 'gift-vouchers::emails.voucher',
    'email_text' => 'gift-vouchers::emails.voucher-text',
],
```

The views the voucher is drawn with. Point them at your own Antlers or Blade templates. See [Designs](designs.md#your-own-templates-antlers).

## Rate limits

```php
'throttle' => [
    'codes' => 10,
    'cart' => 30,
],
```

Requests a minute per visitor. `codes` covers using a code and checking a balance; `cart` covers adding vouchers to the basket and taking them off.

## Database

```php
'database' => [
    'connection' => null,
],
```

The database connection for the `gift_vouchers` and `gift_voucher_transactions` tables, or `null` for the default.
