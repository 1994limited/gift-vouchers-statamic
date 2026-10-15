# Changelog

## 1.0.0 (unreleased)

The first release.

### Added
- **Gift voucher products** for Cargo: a set amount, or an amount the shopper chooses between limits, with suggested amounts. A `gift_voucher` blueprint and a tax class with no rates, from `php please gift-vouchers:install`.
- **The voucher form** (`{{ gift_vouchers:form }}`): amount, recipient, sender, message, send date and design. Each voucher is its own line item. Works without JavaScript.
- **Vouchers made when an order is paid**, one per voucher bought, each with a unique 16-character code, worth what was paid, with an optional expiry.
- **Designs**: an engraved, guilloché pattern drawn from each voucher's ID in the brand colour, the shop's own image, or plain. Several designs for shoppers to choose from, each with a title, colour and engraved motif (heart, star, flower, cake, gift).
- **The live preview** (`{{ gift_vouchers:preview }}`): the voucher card exactly as it'll be printed, filled in as the shopper types.
- **Emails** with the code, message and a printable PDF, sent when the order is paid or on the chosen date (`php please gift-vouchers:send`, scheduled every fifteen minutes). With Imagick, the design's emblem in the email.
- **The printable voucher** and PDF, at signed links.
- **Spending vouchers at checkout** (`{{ gift_vouchers:redeem }}`): after tax, like a payment, several per order, with the balance carried over. Fully covered orders skip payment.
- **Partials for Cargo's prebuilt checkout**: the code box, the vouchers with the totals, the order summary and the confirmation.
- **A balance checker** (`{{ gift_vouchers:check }}`).
- **Orders**: money put back when an order fails or is cancelled; vouchers bought on cancelled or fully refunded orders disabled; **Refund to gift voucher** on orders; a Gift Vouchers section under the order's receipt; entries on the order's timeline; recipients on line items and packing slips.
- **Control Panel**: Store → Gift Vouchers, with what's still owed, search, status filter and CSV; each voucher's history; showing codes; changing balances; editing, disabling, emailing and printing; creating vouchers by hand; permissions.
- **Settings** for the voucher's style, logo, colour, wording, terms, designs and email.
- **Your own views**: publish them, or point the print page, PDF and emails at your own Antlers or Blade templates. Every tag works in Blade too.
- **`voucher_value`**: vouchers worth their price (the default) or what was paid after a Cargo discount.
- **Events**, a **PHP API** and a **JSON API**.

### Safety
- **Balances can't go below zero, or be spent twice.** Each spend is a single conditional update inside a database transaction; an order's vouchers are spent together or not at all; checkout stops if a voucher no longer covers its part.
- **Every change is recorded** with the balance after it.
- **Codes are kept hashed and encrypted**, never as plain text, and shown masked in lists.
- **Using a code and checking a balance are rate limited**, so codes can't be guessed.
- **Shopper-chosen amounts are re-checked** whenever Cargo works out the cart, so an edited line item can't change a voucher's price.
- **What shoppers type is escaped** wherever it's shown, and read as plain text only.
- **Amounts are read for the site's locale** ("10,50" in Germany), and ones that could be read two ways are refused, never guessed.
- **Redirects after a form** only go to this site.
- **Order tags only show an order** on Cargo's signed confirmation page, to its signed-in customer, or to staff who can view orders.
- **Voucher lines are limited** to `max_quantity` (20) when added and again at checkout.
- **An order can't be charged twice** by vouchers, even if it's checked out twice at the same moment.
