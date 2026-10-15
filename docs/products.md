# Voucher products

A voucher product is an ordinary Cargo product with the **Gift voucher** blueprint. It's sold like any other product: it goes in the basket, through checkout, and appears on the order. When the order is paid, a voucher is made for each one bought.

Products with a blueprint listed in `gift-vouchers.blueprints` (by default, `gift_voucher`) are vouchers. `php please gift-vouchers:install` adds the blueprint; you can add your own fields to it, such as an image.

## A set amount, or the shopper's choice

**Amount** chooses between:

- **Set amount.** The voucher costs, and is worth, its **Voucher amount**: a £25 voucher. For a choice of set amounts (£25, £50, £100), make a product for each, or give the product Cargo's variants.
- **Shopper chooses.** The shopper types an amount between the **Lowest amount** and **Highest amount** (or the defaults in [the config](configuration.md#amounts): £10 to £500). **Suggested amounts** are quick choices to show beside the box, such as 25, 50 and 100.

What the shopper pays and what the voucher is worth are always the same. Cargo lets shoppers edit a line item's data, so a chosen amount is held to the product's limits every time Cargo works out the cart, not only when it's added.

If a Cargo discount applies to the voucher line, the shopper pays less and the voucher is still worth its full amount. Restrict your discounts to other products if that isn't what you want.

## Expiry

**Expires after (months)** sets how long vouchers from this product last, from the day they're made. Leave it empty for the default (`gift-vouchers.expiry_months`, which is no expiry). A voucher can be spent until the end of its last day. You can change any voucher's expiry in the Control Panel.

UK law doesn't set a minimum, but many shops choose 12 or 24 months. Show the terms on the voucher: **Tools → Addons → Gift Vouchers → Settings → Terms**.

## VAT and tax classes

Voucher products use the **Gift vouchers** tax class, which has no rates in any tax zone, so Cargo charges no tax on them. In the UK, most gift vouchers are "multi-purpose vouchers": VAT is due when they're spent, not sold. Gift Vouchers takes vouchers off an order *after* tax, like a payment, so the VAT on what's bought is worked out as normal.

If your vouchers can only be spent on one rate of VAT ("single-purpose vouchers"), give the product that tax class instead. Ask your accountant which yours are.

## Shipping

Vouchers aren't shipped. Voucher products are saved as digital products (`type: digital`), so a basket of only vouchers skips Cargo's shipping steps. Cargo still asks for a billing address.

## Stock

Vouchers don't need stock, but if you add Cargo's stock field to the blueprint, it's respected when vouchers are added to the basket.

## Several vouchers in one basket

Each voucher added with the voucher form (`{{ gift_vouchers:form }}`) gets its own line in the basket, so two vouchers for different people stay apart, with their own recipient, message, send date and design. A quantity of 3 on one line makes three vouchers with the same details.
