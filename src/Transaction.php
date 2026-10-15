<?php

namespace NineteenNinetyFour\GiftVouchers;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to a voucher's balance. Spending is negative, putting money
 * back is positive. Transactions are never edited or deleted.
 *
 * @property int $id
 * @property string $voucher_id
 * @property TransactionType $type
 * @property int $amount
 * @property int $balance_after
 * @property ?string $order_id
 * @property ?string $user_id
 * @property ?string $note
 * @property CarbonInterface $created_at
 */
class Transaction extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'gift_voucher_transactions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'amount' => 'integer',
            'balance_after' => 'integer',
        ];
    }

    public function getConnectionName()
    {
        return config('gift-vouchers.database.connection') ?? parent::getConnectionName();
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'voucher_id');
    }
}
