# Spending vouchers

## How vouchers come off an order

A voucher is a way of paying, not a discount. When a shopper uses a code, the cart remembers the voucher. Each time Cargo works out the cart (after line items, shipping, discounts and tax), Gift Vouchers takes the voucher off the grand total:

| | |
| --- | --- |
| Subtotal | £168.00 |
| Shipping | £0.00 |
| Gift voucher (•••• IRTY) | −£30.00 |
| **To pay** | **£138.00** |

- **Tax is worked out as normal.** The VAT on what's bought doesn't change, as it would with a discount.
- **Several vouchers** can be used on one order (up to `max_per_cart`, 5 by default), in the order they were entered. Each covers what it can.
- **What's left stays on the voucher.** A £50 voucher on a £30 order leaves £20 for next time.
- **If vouchers cover everything**, the order is free: Cargo skips payment and marks it paid.
- **The rest is paid as normal**, by card or any other gateway. The gateway charges the reduced total.
- **Vouchers can't pay for vouchers**, unless you turn on `pay_for_vouchers`. A code can't be used on a basket of only vouchers.
- **A voucher must be in the site's currency.** A voucher bought in GBP can't be spent on a EUR site.

A voucher that would cover nothing (the order's already paid for by other vouchers, say) isn't added, so the shopper isn't confused by a £0.00 line. A voucher that's disabled, expires or is spent elsewhere after it was added drops off the cart the next time Cargo works the cart out (when its contents, address or shipping change). If nothing changes before checkout, checkout itself catches it, as below.

## Nothing is spent until the order is placed

Vouchers on a cart aren't held: the shopper might never check out. The balance is spent when Cargo makes the order, inside a database transaction:

1. Each voucher's part is taken with a single conditional update, "take £30 off this voucher if it has at least £30 and can be spent". If the voucher no longer has enough (spent on another order a moment ago, in another tab), nothing is taken.
2. All the vouchers on an order are spent together or not at all.
3. If any can't cover its part, checkout stops with a message ("This gift voucher only has £5.00 left."). The order Cargo started is deleted, Cargo cancels the payment (Stripe's payments are only authorised at that point), and nothing has been spent.
4. Every change is written to the voucher's history, with the balance after it.

So a balance can't go below zero, and the same money can't be spent twice, even with two checkouts at the same moment, on any database.

## Codes

- 16 characters by default, in groups of four, from letters and numbers that can't be mistaken for each other (no 0/O, 1/I/L or U): about 78 bits, far too many to guess.
- Typed in any case, with or without spaces and dashes. An optional prefix ("GIFT-…") can be left off.
- Kept hashed (to find them) and encrypted (to show them in emails and the Control Panel), never as plain text. The Control Panel's lists show only the last four characters.
- Using a code and checking a balance are rate limited per visitor (`throttle.codes`, 10 a minute).
- Codes you type in the Control Panel (for a raffle prize, say) can be 6 to 32 letters and numbers. Short, guessable codes are your choice.

If your app key changes, codes still work (they're found by their hash), but can't be shown again until the old key is in `APP_PREVIOUS_KEYS`.

## What's stored on the cart and order

| Key | |
| --- | --- |
| `gift_vouchers` | The IDs of the vouchers used |
| `gift_voucher_breakdown` | `[{id, code, amount}]`: what each covers (`code` is the last four characters) |
| `gift_voucher_total` | The total vouchers cover |

Cargo copies them from the cart to the order. Shoppers can't set them through Cargo's routes.
