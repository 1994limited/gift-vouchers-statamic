# Tags

Every tag starts `gift_vouchers:`. Values from shoppers are escaped. Amounts are given both as whole minor units (pence) and `_formatted` in the site's currency and locale.

Forms all take `redirect` (where to go afterwards, on this site) and `error_redirect` (where to go if it fails), and any other parameter becomes an attribute of the `<form>` (`class`, `id`, `x-data`…). They include a CSRF token. See [Templates](templates.md) for whole examples.

## gift_vouchers:form

The form to buy a voucher. The product is the entry in context, or `product="…"`. Draws nothing for products that aren't vouchers.

| Variable | |
| --- | --- |
| `product`, `currency` | The product's ID, and the site's currency code |
| `is_open`, `is_fixed` | Whether the shopper chooses the amount |
| `has_variants`, `variants` | For set amounts with Cargo's variants: `key`, `name`, `price`. Post the chosen `key` as `variant` |
| `min`, `max` | The limits as decimals for inputs (`10.00`) |
| `min_formatted`, `max_formatted` | The limits to show (`£10.00`) |
| `step` | `0.01` (or `1` for currencies without decimals) |
| `price` | A set amount, formatted |
| `suggested` | Quick amounts: `value`, `formatted` |
| `designs`, `has_designs` | As [gift_vouchers:designs](#gift_voucherdesigns), and whether there's a choice |
| `old:*` | What was typed, after an error |
| `errors:*` | Each field's error (`errors:amount`, `errors:recipient_email`…) |
| `name_limit`, `message_limit` | 100 and 500 |
| `today`, `earliest_send_on`, `latest_send_on` | Dates (`YYYY-MM-DD`) for the send date input |

## gift_vouchers:preview

The voucher card, filled in live from the voucher form. Parameters: `product`, `form` (the form's id, if there's more than one), `scripts="false"` to leave the script out.

## gift_vouchers:preview_script

The `<script>` tag for the preview, to place yourself.

## gift_vouchers:designs

The designs shoppers can choose from: `handle`, `name`, `style` (`engraved`, `image` or `plain`), `colour`, `preview` (a picture's URL), `is_default`, `is_selected`.

## gift_vouchers:artwork

A design's engraved card as inline SVG. `design="…"` (or the first), `seed="…"` for a different pattern, `shape="emblem"` for just the rosette. Draws nothing for image and plain designs.

## gift_vouchers:is_voucher

`{{ if {gift_vouchers:is_voucher :product="id"} }}`: whether a product is a voucher.

## gift_vouchers:redeem

The form to use a code on the cart. The input is `code`. Variables: `errors:code`.

## gift_vouchers:applied

The vouchers used on the cart, or on an order with `order="…"`: `id`, `code` (`•••• PQRS`), `amount`, `amount_formatted`. Draws nothing when there are none.

## gift_vouchers:has_applied

Whether any vouchers are used on the cart.

## gift_vouchers:total

What vouchers cover on the cart (or `order="…"`), formatted. `raw="true"` for pence.

## gift_vouchers:remove

The form to take a voucher off the cart: `voucher="{{ id }}"`.

## gift_vouchers:check

The form to check a balance. The input is `code`. Variables: `errors:code`.

## gift_vouchers:balance

After a check: `code` (`•••• PQRS`), `code_hint`, `balance`, `balance_formatted`, `initial_amount`, `initial_amount_formatted`, `currency`, `status` (`active`, `spent`, `expired` or `disabled`), `status_label`, `is_usable`, `is_expired`, `expires_at`, `expires_formatted`. As a single tag, the balance formatted.

## gift_vouchers:issued

The vouchers bought on an order (`order="…"`, or the `id` in context). It shows any order you give it, so only pass an order ID you trust, such as the one on Cargo's signed confirmation page, never one from the query string: `code` (masked), `amount`, `amount_formatted`, `recipient_name`, `recipient_email`, `send_on`, `send_on_formatted`, `is_scheduled`, `is_sent`.

## gift_vouchers:success

The message after a form post.

## gift_vouchers:error

The error after a form post. `field="amount"` for one field's.

## Partials

For [Cargo's prebuilt checkout](prebuilt-checkout.md): `gift-vouchers::cargo-checkout`, `gift-vouchers::cargo-checkout-lines`, `gift-vouchers::cargo-order-summary` and `gift-vouchers::cargo-confirmation`.
