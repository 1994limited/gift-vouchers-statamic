<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Voucher products
    |--------------------------------------------------------------------------
    |
    | Products with one of these blueprints are sold as gift vouchers. The
    | install command (php please gift-vouchers:install) adds a blueprint
    | called gift_voucher to your products collection.
    |
    */

    'blueprints' => ['gift_voucher'],

    /*
    |--------------------------------------------------------------------------
    | Amounts
    |--------------------------------------------------------------------------
    |
    | Limits for vouchers where the shopper chooses the amount, in minor
    | units (pence). Each product can set its own minimum and maximum; these
    | are used when it doesn't.
    |
    */

    'amounts' => [
        'min' => 1000,
        'max' => 50000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Expiry
    |--------------------------------------------------------------------------
    |
    | How many months a voucher can be spent for after it's issued. Null
    | means vouchers don't expire. Each product can set its own.
    |
    */

    'expiry_months' => null,

    /*
    |--------------------------------------------------------------------------
    | Codes
    |--------------------------------------------------------------------------
    |
    | Codes are made from letters and numbers that can't be confused with
    | each other (no 0/O, 1/I/L or U), shown in groups. 16 characters gives
    | around 78 bits: far too many to guess. An optional prefix is added in
    | front, such as "GIFT".
    |
    */

    'codes' => [
        'length' => 16,
        'group' => 4,
        'prefix' => '',
    ],

    /*
    |--------------------------------------------------------------------------
    | Checkout
    |--------------------------------------------------------------------------
    |
    | max_per_cart: how many vouchers can be used on one order.
    |
    | pay_for_vouchers: whether vouchers can be spent on more vouchers. Off
    | by default, as most shops don't allow it.
    |
    */

    'max_per_cart' => 5,

    'pay_for_vouchers' => false,

    /*
    |--------------------------------------------------------------------------
    | Orders
    |--------------------------------------------------------------------------
    |
    | restore_on_cancel: when an order is cancelled, put back what was spent
    | from vouchers on it, and disable vouchers it bought.
    |
    | disable_on_full_refund: when an order is refunded in full, disable the
    | vouchers it bought.
    |
    */

    'restore_on_cancel' => true,

    'disable_on_full_refund' => true,

    /*
    |--------------------------------------------------------------------------
    | Emails
    |--------------------------------------------------------------------------
    |
    | Vouchers are emailed to their recipient (or, if no recipient was given,
    | to the customer) when the order is paid, or on the date chosen. The
    | wording is edited in the Control Panel: Tools → Addons → Gift Vouchers.
    |
    | Scheduled vouchers are sent by `php please gift-vouchers:send`, which
    | runs every fifteen minutes with Laravel's scheduler.
    |
    */

    'email' => [
        'enabled' => true,
        'attach_pdf' => true,
        'bcc' => null,
        'queue' => null,
    ],

    'schedule' => true,

    /*
    |--------------------------------------------------------------------------
    | PDF
    |--------------------------------------------------------------------------
    */

    'pdf' => [
        'paper' => 'a4',
        'orientation' => 'portrait',
    ],

    /*
    |--------------------------------------------------------------------------
    | Views
    |--------------------------------------------------------------------------
    |
    | The views the voucher is drawn with. To change a little, publish the
    | addon's (php artisan vendor:publish --tag=gift-vouchers-views) and edit
    | them. To start again in Antlers, point these at your own templates,
    | such as 'vouchers/print' for resources/views/vouchers/print.antlers.html.
    | Antlers doesn't escape: use | sanitize on anything shoppers typed.
    |
    */

    'views' => [
        'print' => 'gift-vouchers::voucher',
        'pdf' => 'gift-vouchers::voucher',
        'email' => 'gift-vouchers::emails.voucher',
        'email_text' => 'gift-vouchers::emails.voucher-text',
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits
    |--------------------------------------------------------------------------
    |
    | Requests a minute per visitor. "codes" covers using a code and checking
    | a balance, so codes can't be guessed. "cart" covers adding vouchers.
    |
    */

    'throttle' => [
        'codes' => 10,
        'cart' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    |
    | Vouchers and their history are kept in two database tables, whichever
    | way Cargo keeps orders: spending a balance needs a database's locks.
    |
    */

    'database' => [
        'connection' => null,
    ],

];
