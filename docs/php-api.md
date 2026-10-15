# PHP API

`NineteenNinetyFour\GiftVouchers\GiftVouchers` is the way in. Amounts are whole minor units (pence).

```php
use NineteenNinetyFour\GiftVouchers\GiftVouchers;

$vouchers = app(GiftVouchers::class);
```

## Making vouchers

```php
$voucher = $vouchers->create(
    amount: 2500,
    currency: 'GBP',                  // the current site's, if left out
    details: [
        'recipient_name' => 'Grace',
        'recipient_email' => 'grace@example.com',
        'sender_name' => 'Ada',
        'message' => 'Happy birthday!',
        'send_on' => '2026-12-01',    // optional
    ],
    code: 'SPRING-FAIR-2026',         // or null for a random one
    expiresAt: now()->addYear(),
    design: 'birthday',
    note: 'Raffle prize',             // kept in its history
    send: true,                       // email it now (or on send_on)
);

$voucher->code();   // "SPRI-NGFA-IR20-26"
```

Throws a `VoucherException` for a bad amount, or a custom code that's invalid or taken.

## Finding vouchers

```php
$vouchers->find($id);
$vouchers->findByCode('abcd efgh jkmn pqrs');   // any case, spaces or dashes
$vouchers->balance('ABCD-EFGH-JKMN-PQRS');      // pence, or null
$vouchers->issuedFor($order);                   // the vouchers an order bought
```

## Changing vouchers

```php
$vouchers->adjust($voucher, 1000, 'Goodwill');     // add £10
$vouchers->adjust($voucher, -500, 'Correction');   // take £5 (never below zero)
$vouchers->disable($voucher);
$vouchers->enable($voucher);
$vouchers->send($voucher);                         // email it now
```

## Carts and orders

```php
$vouchers->applyToCart('ABCD-EFGH-JKMN-PQRS');     // the current cart, or pass one
$vouchers->removeFromCart($voucher);
$vouchers->refundOrder($order, 1500, 'Returned');  // back onto the vouchers it used
```

`applyToCart()` throws a `VoucherException` saying why a voucher can't be used: its `reason` is a short key (`not_found`, `expired`, `disabled`, `spent`, `currency`, `already_applied`, `too_many`, `nothing_to_pay`) and its message is safe to show customers.

## The voucher

`NineteenNinetyFour\GiftVouchers\Voucher` is an Eloquent model.

| | |
| --- | --- |
| `id`, `currency`, `initial_amount`, `balance` | |
| `recipient_name`, `recipient_email`, `sender_name`, `message`, `send_on`, `sent_at` | |
| `design`, `site`, `expires_at`, `disabled_at`, `notes` | |
| `order_id`, `line_item_id`, `product_id`, `created_by` | Where it came from |
| `code()` | The code, formatted |
| `maskedCode()` | `•••• PQRS` |
| `status()` | A `VoucherStatus`: `Active`, `Spent`, `Expired` or `Disabled` |
| `isUsable()`, `isExpired()`, `isScheduled()` | |
| `formatted($amount)` | An amount in its currency |
| `transactions` | Its history |
| `order()` | The Cargo order it was bought on |
| `printUrl()`, `pdfUrl()`, `editUrl()` | |

Balances only change through the ledger (`NineteenNinetyFour\GiftVouchers\Ledger`), which the methods above use. Don't write `balance` yourself: the history wouldn't add up.

## Designs

```php
use NineteenNinetyFour\GiftVouchers\Designs;

app(Designs::class)->all();              // keyed by handle
app(Designs::class)->find('birthday');   // a Design: name, title, style, colour, motif, image
```

## Cargo's price hook

Gift Vouchers prices voucher line items through Cargo's `CalculateLineItems::priceHook()`. If your site sets its own hook, it's kept and used for everything else, whichever order they're set in, as long as yours is set while the app boots (in a service provider). If something replaces the hook later, the Gift Vouchers page warns you.
