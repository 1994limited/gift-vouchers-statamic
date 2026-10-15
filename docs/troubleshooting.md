# Troubleshooting

## "Vouchers aren't being priced"

The Gift Vouchers page shows this when something has replaced Cargo's price hook since Gift Vouchers set it. Vouchers where the shopper chooses the amount would be priced wrongly.

If your site sets `CalculateLineItems::priceHook()`, set it in a service provider's `boot()` method: Gift Vouchers keeps it and uses it for everything that isn't a voucher. Setting it later (in middleware, say) replaces Gift Vouchers' hook.

## A voucher product goes in the basket at £0 or the minimum

- Check the product uses a voucher blueprint (`gift-vouchers.blueprints`) and is in one of Cargo's product collections.
- For **Set amount**, fill in **Voucher amount**.
- For **Shopper chooses**, add it with `{{ gift_vouchers:form }}`, which sends the amount. Cargo's own `{{ cart:add }}` doesn't, so the voucher is priced at its lowest amount.

## The code box says there's nothing to pay

Vouchers can't pay for vouchers (unless `pay_for_vouchers` is on), and a voucher isn't added if the order is already covered. See [Spending vouchers](checkout.md).

## Checkout says "This gift voucher only has £x left"

The voucher was spent elsewhere between being added and checkout (in another tab, or by someone else with the code). Nothing was charged. The shopper can take it off, or pay the rest another way.

## Vouchers aren't emailed

- Check Laravel's mail settings: send a voucher from its page with **Email now**, and look in `storage/logs/laravel.log` for "couldn't be emailed".
- With `email.queue` set, a queue worker must be running.
- `email.enabled` must be `true`.

## Vouchers with a send date aren't emailed

The scheduler must be running (`* * * * * php artisan schedule:run`). Run `php please gift-vouchers:send` to send any that are due, and see how many were sent.

## The logo doesn't show in the PDF or email

Use a PNG or JPEG under 2MB. PDFs embed the logo, so it doesn't need to be public; emails link to it, so it must be in a public asset container.

## The design's emblem isn't in the email

It needs PHP's Imagick extension, with SVG support. Without it, emails show the design's title and colour, and nothing else changes.

## The settings page's images or designs are missing

The settings' image fields need an asset container. Make one in **Content → Assets**.

## An order page shows "Timeline Event Type [gift_voucher] could not be found"

Gift Vouchers has been removed, and the order has gift voucher entries on its timeline. Install it again, or see [Uninstalling](installation.md#uninstalling).

## Codes can't be shown in the Control Panel

The app key has changed since they were made. They still work at checkout. Add the old key to `APP_PREVIOUS_KEYS` in `.env` to show them again.

## Still stuck?

Email [hello@1994.co.uk](mailto:hello@1994.co.uk), or ask through the addon's page on the Statamic Marketplace. Include your Statamic, Cargo and PHP versions.
