<?php

namespace NineteenNinetyFour\GiftVouchers;

use Carbon\CarbonInterface;
use DuncanMcClean\Cargo\Facades\Order;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\URL;
use Statamic\Sites\Site;

/**
 * A gift voucher: a code with a balance, in one currency.
 *
 * Balances only change through the Ledger, which records each change and
 * makes sure a balance can't go below zero or be spent twice at once.
 *
 * @property string $id
 * @property string $code_hash
 * @property string $code_encrypted
 * @property string $code_hint
 * @property string $currency
 * @property int $initial_amount
 * @property int $balance
 * @property ?string $site
 * @property ?CarbonInterface $expires_at
 * @property ?CarbonInterface $disabled_at
 * @property ?string $recipient_name
 * @property ?string $recipient_email
 * @property ?string $sender_name
 * @property ?string $message
 * @property ?CarbonInterface $send_on
 * @property ?CarbonInterface $sent_at
 * @property ?string $order_id
 * @property ?string $line_item_id
 * @property ?int $sequence
 * @property ?string $product_id
 * @property ?string $created_by
 * @property ?string $notes
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class Voucher extends Model
{
    use HasUlids;

    protected $table = 'gift_vouchers';

    protected $guarded = ['id', 'balance'];

    protected $hidden = ['code_hash', 'code_encrypted'];

    protected function casts(): array
    {
        return [
            'initial_amount' => 'integer',
            'balance' => 'integer',
            'sequence' => 'integer',
            'expires_at' => 'datetime',
            'disabled_at' => 'datetime',
            'send_on' => 'date',
            'sent_at' => 'datetime',
        ];
    }

    public function getConnectionName()
    {
        return config('gift-vouchers.database.connection') ?? parent::getConnectionName();
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'voucher_id')->orderBy('id');
    }

    /**
     * The code, formatted: "ABCD-EFGH-JKMN-PQRS".
     */
    public function code(): ?string
    {
        try {
            return Codes::format(Crypt::decryptString($this->code_encrypted));
        } catch (DecryptException) {
            // The app key has changed since the voucher was made. The code
            // still works (it's found by its hash), it just can't be shown.
            return null;
        }
    }

    /**
     * The code with all but the last four characters hidden: "•••• PQRS".
     */
    public function maskedCode(): string
    {
        return '•••• '.$this->code_hint;
    }

    public function status(): VoucherStatus
    {
        return match (true) {
            $this->disabled_at !== null => VoucherStatus::Disabled,
            $this->isExpired() => VoucherStatus::Expired,
            $this->balance <= 0 => VoucherStatus::Spent,
            default => VoucherStatus::Active,
        };
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Whether it can be spent right now: enabled, in date, with a balance.
     */
    public function isUsable(): bool
    {
        return $this->status() === VoucherStatus::Active;
    }

    /**
     * Whether it's waiting for its send date before it's emailed.
     */
    public function isScheduled(): bool
    {
        return $this->sent_at === null && $this->send_on !== null && $this->send_on->isFuture();
    }

    public function spent(): int
    {
        return max(0, $this->initial_amount - $this->balance);
    }

    public function formatted(int $amount): string
    {
        return Money::format($amount, $this->currency, $this->siteObject()?->locale());
    }

    /**
     * The Statamic site the voucher was bought on (or the default site).
     */
    public function siteObject(): ?Site
    {
        return ($this->site ? \Statamic\Facades\Site::get($this->site) : null) ?? \Statamic\Facades\Site::default();
    }

    /**
     * Who the voucher is emailed to: its recipient, or the customer who bought it.
     */
    public function emailAddress(): ?string
    {
        if ($this->recipient_email) {
            return $this->recipient_email;
        }

        return $this->order()?->customer()?->email();
    }

    /**
     * The Cargo order it was bought on, if any.
     */
    public function order(): ?\DuncanMcClean\Cargo\Contracts\Orders\Order
    {
        return $this->order_id ? Order::find($this->order_id) : null;
    }

    /**
     * A link to the printable voucher. It shows the code, so it's signed,
     * and only sent to the recipient (or opened from the Control Panel).
     */
    public function printUrl(): string
    {
        return URL::signedRoute('statamic.gift-vouchers.print', ['voucher' => $this->id]);
    }

    public function pdfUrl(): string
    {
        return URL::signedRoute('statamic.gift-vouchers.pdf', ['voucher' => $this->id]);
    }

    public function editUrl(): string
    {
        return cp_route('gift-vouchers.show', $this->id);
    }

    /**
     * Vouchers whose code is the one typed (spaces, dashes and case ignored).
     */
    public function scopeWhereCode(Builder $query, string $code): Builder
    {
        $candidates = Codes::candidates($code);

        return $candidates ? $query->whereIn('code_hash', $candidates) : $query->whereRaw('1 = 0');
    }

    public function scopeWhereStatus(Builder $query, VoucherStatus $status): Builder
    {
        $now = now();

        return match ($status) {
            VoucherStatus::Disabled => $query->whereNotNull('disabled_at'),
            VoucherStatus::Expired => $query->whereNull('disabled_at')->whereNotNull('expires_at')->where('expires_at', '<=', $now),
            VoucherStatus::Spent => $query->whereNull('disabled_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now))->where('balance', '<=', 0),
            VoucherStatus::Active => $query->usable(),
        };
    }

    /**
     * Vouchers that can be spent now.
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('disabled_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where('balance', '>', 0);
    }

    public static function findByCode(string $code): ?self
    {
        return static::query()->whereCode($code)->first();
    }
}
