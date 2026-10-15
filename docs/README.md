# Gift Vouchers documentation

Gift Vouchers adds gift vouchers to [Cargo](https://builtwithcargo.dev), Statamic's e-commerce addon. Shoppers buy a voucher for a set amount or one they choose, with a recipient, a message and a design. When the order is paid, the recipient is emailed their code and a printable PDF. They spend it at checkout, all at once or over several orders.

## Setting up

1. [Installation](installation.md): requirements, installing, licensing, updating.
2. [Voucher products](products.md): set amounts, shopper-chosen amounts, expiry and VAT.
3. [Designs](designs.md): the engraved pattern, your own artwork, a choice of designs, and changing the voucher's look completely.
4. [Configuration](configuration.md): every option in `config/gift-vouchers.php`.

## Building the front end

5. [Templates](templates.md): the voucher form and live preview, the basket, the balance checker. Start here.
6. [Cargo's prebuilt checkout](prebuilt-checkout.md): adding vouchers to the checkout Cargo ships.
7. [Tags](tags.md): every `{{ gift_vouchers:* }}` tag, its parameters and variables.
8. [The demo shop](demo.md): Paper & Ink, a whole shop built with Gift Vouchers.

## Running a shop

9. [Spending vouchers](checkout.md): how vouchers come off an order, and how balances are kept safe.
10. [Orders and refunds](orders-and-refunds.md): when vouchers are made, emailed, put back and disabled.
11. [Emails](emails.md): the voucher email, send dates, and testing.
12. [The Control Panel](control-panel.md): Store → Gift Vouchers, orders, and permissions.

## Reference

13. [Events](events.md)
14. [PHP API](php-api.md)
15. [HTTP API](http-api.md): the `/!/gift-vouchers/…` endpoints.
16. [Troubleshooting](troubleshooting.md)

## Requirements

- PHP 8.3 or later.
- Statamic 6.35.1 or later, and Cargo 1.15 or later.
- A database for vouchers and their history (SQLite, MySQL, PostgreSQL or SQL Server), whichever way Cargo keeps orders.
- Laravel's scheduler running, if shoppers can choose a send date.
- Optional: PHP's Imagick extension, to show the design's emblem in emails.
