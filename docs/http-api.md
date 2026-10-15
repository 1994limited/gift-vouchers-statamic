# HTTP API

The tags' forms post to these. Scripts and headless front ends can use them too: send `Accept: application/json` for JSON answers, and a CSRF token (`_token`, or the `X-CSRF-TOKEN` header) with every `POST`.

Without `Accept: application/json`, each redirects back (or to `_redirect`, on this site), with a message in the session (`gift_vouchers_success`) or errors in the `gift_vouchers` error bag.

Errors answer `422` with `{ "success": false, "message": "…", "errors": { "field": "…" } }`. Too many requests answer `429`.

## GET /!/gift-vouchers/cart

The vouchers on the visitor's cart.

```json
{
  "success": true,
  "cart": {
    "grand_total": 13800,
    "grand_total_formatted": "£138.00",
    "gift_voucher_total": 3000,
    "gift_voucher_total_formatted": "£30.00",
    "gift_vouchers": [
      { "id": "01m50…", "code": "•••• IRTY", "amount": 3000, "amount_formatted": "£30.00" }
    ]
  }
}
```

`cart` is `null` if the visitor has no cart.

## POST /!/gift-vouchers/cart/add

Adds a voucher to the cart, as its own line item. Fields: `product` (required), `amount`, `variant`, `quantity`, `recipient_name`, `recipient_email`, `sender_name`, `message`, `send_on`, `design`. See [Templates](templates.md#the-voucher-form). Answers `{ success, message, cart }`.

Rate limited with `throttle.cart`.

## POST /!/gift-vouchers/cart/redeem

Uses a voucher on the cart. Field: `code`. Answers `{ success, message, cart }`.

## POST /!/gift-vouchers/cart/remove

Takes a voucher off the cart. Field: `voucher` (its ID, from the cart's `gift_vouchers`). Answers `{ success, message, cart }`.

## POST /!/gift-vouchers/check

A voucher's balance. Field: `code`. Answers `{ success, voucher }`, with the same values as [gift_vouchers:balance](tags.md#gift_vouchersbalance). Never the whole code.

`redeem` and `check` are rate limited together with `throttle.codes`.

## GET /!/gift-vouchers/vouchers/{id}/print and /pdf

The printable voucher and its PDF. These links are signed (they show the code), so they only work as sent in the voucher's email or opened from the Control Panel.

## GET /!/gift-vouchers/designs/{design}/artwork

A design's engraved card as SVG, for the live preview.

## GET /!/gift-vouchers/preview.js

The live preview's script.
