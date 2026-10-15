<?php

return [

    // Front end
    'added' => 'Gift voucher added to your basket.',
    'redeemed' => 'Gift voucher applied: :amount off your order.',
    'removed' => 'Gift voucher removed.',

    'errors' => [
        'enter_code' => 'Enter the code from your gift voucher.',
        'not_found' => 'We couldn’t find a gift voucher with that code. Check it and try again.',
        'disabled' => 'This gift voucher can’t be used.',
        'expired' => 'This gift voucher expired on :date.',
        'spent' => 'This gift voucher has been spent.',
        'insufficient' => 'This gift voucher only has :balance left.',
        'currency' => 'This gift voucher is in :currency and can’t be used here.',
        'already_applied' => 'You’ve already used this gift voucher on your order.',
        'too_many' => 'You can use up to :max gift vouchers on one order.',
        'nothing_to_pay' => 'There’s nothing left on your order to pay with a gift voucher.',
        'no_cart' => 'Add something to your basket first.',
        'not_on_cart' => 'That gift voucher isn’t on your order.',
        'not_a_voucher' => 'That isn’t a gift voucher we sell.',
        'amount_range' => 'Choose an amount from :min to :max.',
        'choose_amount' => 'Choose an amount.',
        'quantity' => 'Choose a quantity from 1 to :max.',
        'bad_email' => 'Enter a valid email address for the recipient.',
        'bad_date' => 'Choose a send date from tomorrow up to a year away, or leave it empty to send it straight away.',
        'out_of_stock' => 'Sorry, this gift voucher is sold out.',
        'invalid_amount' => 'Enter an amount greater than zero.',
        'invalid_custom_code' => 'Codes need :min to :max letters and numbers.',
        'duplicate_code' => 'Another voucher already has that code.',
        'refund_too_much' => 'Only :amount was paid by gift voucher on this order and not yet refunded.',
        'no_email' => 'There’s no email address to send this voucher to.',
    ],

    // Wording that can be changed in the addon's settings.
    'defaults' => [
        'email_subject' => 'You’ve been sent a :amount gift voucher',
        'email_heading' => 'A gift for you',
        'email_intro' => ':sender_name has sent you a :amount gift voucher to spend at :site.',
        'voucher_title' => 'Gift voucher',
        'how_to_redeem' => 'To spend it, enter the code at checkout on :site. Anything you don’t spend stays on the voucher for next time.',
        'terms' => '',
    ],

    // The printable voucher and PDF.
    'document' => [
        'download' => 'Download PDF',
        'print' => 'Print',
        'open' => 'View and print your voucher',
        'for' => 'For :name',
        'from' => 'From :name',
        'code' => 'Your code',
        'balance' => 'Balance left',
        'expires' => 'Use by',
        'never' => 'No expiry date',
        'where' => 'Spend at',
        'status_spent' => 'This voucher has been spent.',
        'status_expired' => 'This voucher has expired.',
        'status_disabled' => 'This voucher can no longer be used.',
    ],

    'status' => [
        'active' => 'Active',
        'spent' => 'Spent',
        'expired' => 'Expired',
        'disabled' => 'Disabled',
    ],

    'transaction' => [
        'issued' => 'Issued',
        'redeemed' => 'Spent',
        'restored' => 'Restored',
        'refunded' => 'Refunded',
        'adjusted' => 'Adjusted',
    ],

    // On an order's timeline.
    'timeline' => [
        'redeemed' => ':amount paid with gift voucher :voucher',
        'issued' => '{1} 1 gift voucher issued|[2,*] :count gift vouchers issued',
        'restored' => ':amount put back on gift voucher :voucher',
        'refunded' => ':amount refunded to gift voucher :voucher',
        'disabled' => '{1} 1 gift voucher bought on this order disabled|[2,*] :count gift vouchers bought on this order disabled',
        'part_refund' => 'Part refunded. Gift vouchers bought on this order are still active: adjust them in Gift Vouchers if you need to.',
    ],

    'notes' => [
        'order_cancelled' => 'Order #:order was cancelled.',
        'order_failed' => 'The order wasn’t completed.',
        'order_refunded' => 'Order #:order was refunded.',
    ],

    'fields' => [
        'recipient_name' => 'Recipient',
        'recipient_email' => 'Recipient email',
        'sender_name' => 'From',
        'message' => 'Message',
        'send_on' => 'Send on',
    ],

    'permissions' => [
        'view' => 'View gift vouchers',
        'create' => 'Create gift vouchers',
        'edit' => 'Edit gift vouchers, change balances and see codes',
    ],

    // Control Panel
    'cp' => [
        'gift_vouchers' => 'Gift Vouchers',
        'gift_voucher' => 'Gift Voucher',
        'refund_action' => 'Refund to gift voucher',
        'refund_instructions' => 'Put this much back on the gift vouchers used on this order, the last one spent first. Refund any card payment with Refund.',
        'refunded' => '{1} :amount refunded to 1 gift voucher.|[2,*] :amount refunded to :count gift vouchers.',
        'note' => 'Note',
        'email_needed' => 'Enter the recipient’s email address to send the voucher.',
        'created' => 'Voucher created.',
        'saved' => 'Saved.',
        'adjusted' => 'Balance changed. It’s now :balance.',
        'disabled' => 'Voucher disabled. It can’t be spent until it’s enabled again.',
        'enabled' => 'Voucher enabled.',
        'sent' => 'Voucher emailed to :email.',
        'deleted_user' => 'Deleted user',
    ],

];
