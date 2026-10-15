# Events

Listen for these as for any Laravel event:

```php
use Illuminate\Support\Facades\Event;
use NineteenNinetyFour\GiftVouchers\Events\VoucherRedeemed;

Event::listen(function (VoucherRedeemed $event) {
    // $event->voucher, $event->transaction
});
```

All are in `NineteenNinetyFour\GiftVouchers\Events`, and dispatched once the change has been written to the database (if you call the PHP API inside a database transaction of your own, that's before your transaction commits).

| Event | Properties | When |
| --- | --- | --- |
| `VoucherIssued` | `voucher` | A voucher is made: bought on an order, or created in the Control Panel or PHP |
| `VoucherSent` | `voucher` | A voucher is emailed (or queued) |
| `VoucherRedeemed` | `voucher`, `transaction` | Spent on an order. `transaction->order_id` is the order |
| `VoucherCredited` | `voucher`, `transaction` | Money put back: an order failed or was cancelled (`type` is `restored`), or a refund (`refunded`) |
| `VoucherAdjusted` | `voucher`, `transaction` | A balance changed by hand |
| `VoucherUpdated` | `voucher` | Details changed, disabled or enabled |

A `transaction` has `type` (a `TransactionType`), `amount` (negative for spending), `balance_after`, `order_id`, `user_id` and `note`.

## Cargo's events

Gift Vouchers listens to Cargo's own events (`CartRecalculated`, `OrderCreated`, `OrderPaymentReceived`, `OrderStatusUpdated`, `OrderDeleted`, `OrderRefunded`, `OrderBlueprintFound` and `LineItemBlueprintFound`) and Statamic's `EntrySaving` (to save voucher products as digital). Listeners of your own on those run alongside it.
