<?php

namespace NineteenNinetyFour\GiftVouchers\Tags;

use Carbon\CarbonImmutable;
use DuncanMcClean\Cargo\Contracts\Cart\Cart as CartContract;
use DuncanMcClean\Cargo\Facades\Cart;
use DuncanMcClean\Cargo\Facades\Order;
use DuncanMcClean\Cargo\Facades\Product;
use Illuminate\Support\MessageBag;
use NineteenNinetyFour\GiftVouchers\Cargo\CartVouchers;
use NineteenNinetyFour\GiftVouchers\Cargo\OrderVouchers;
use NineteenNinetyFour\GiftVouchers\Cargo\Products;
use NineteenNinetyFour\GiftVouchers\Details;
use NineteenNinetyFour\GiftVouchers\Money;
use NineteenNinetyFour\GiftVouchers\Voucher;
use Statamic\Tags\Concerns\RendersForms;
use Statamic\Tags\Tags;

/**
 * {{ gift_vouchers:* }}
 *
 * Forms work without JavaScript: each posts to /!/gift-vouchers/... and
 * comes back with {{ gift_vouchers:success }} or errors. Everything a
 * visitor typed is escaped before it's handed to Antlers.
 */
class GiftVoucherTags extends Tags
{
    use RendersForms;

    protected static $handle = 'gift_vouchers';

    /**
     * {{ gift_vouchers:form product="{{ id }}" }} … {{ /gift_vouchers:form }}
     *
     * The form to buy a voucher: amount (if the shopper chooses it),
     * recipient, message and send date. Inside: is_open, min, max,
     * suggested, old, errors, today, latest_send_on.
     */
    public function form(): string
    {
        $product = $this->product();

        if (! $product) {
            return '';
        }

        $currency = Money::currency();
        $products = app(Products::class);
        $open = $products->isOpen($product);

        $data = [
            'product' => $product->id(),
            'is_open' => $open,
            'is_fixed' => ! $open,
            'currency' => $currency,
            'min' => $open ? Money::toDecimal($products->min($product), $currency) : null,
            'max' => $open ? Money::toDecimal($products->max($product), $currency) : null,
            'min_formatted' => $open ? Money::format($products->min($product), $currency) : null,
            'max_formatted' => $open ? Money::format($products->max($product), $currency) : null,
            'step' => Money::decimals($currency) > 0 ? '0.'.str_repeat('0', Money::decimals($currency) - 1).'1' : '1',
            'price' => $open ? null : Money::format((int) $product->price(), $currency),
            'suggested' => $open ? collect($products->suggested($product, $currency))->map(fn (int $amount) => [
                'value' => Money::toDecimal($amount, $currency),
                'formatted' => Money::format($amount, $currency),
            ])->all() : [],
            'message_limit' => Details::MESSAGE_LIMIT,
            'name_limit' => Details::NAME_LIMIT,
            'today' => CarbonImmutable::today()->toDateString(),
            'earliest_send_on' => CarbonImmutable::tomorrow()->toDateString(),
            'latest_send_on' => CarbonImmutable::today()->addYear()->toDateString(),
            'old' => $this->old(['amount', 'variant', 'quantity', 'recipient_name', 'recipient_email', 'sender_name', 'message', 'send_on']),
            'errors' => $this->errorMessages(),
        ];

        return $this->actionForm('cart/add', ['product' => $product->id()], $data);
    }

    /**
     * {{ gift_vouchers:redeem }} <input name="code"> <button>Apply</button> {{ /gift_vouchers:redeem }}
     */
    public function redeem(): string
    {
        return $this->actionForm('cart/redeem', [], ['errors' => $this->errorMessages()]);
    }

    /**
     * {{ gift_vouchers:remove voucher="{{ id }}" }} <button>Remove</button> {{ /gift_vouchers:remove }}
     */
    public function remove(): string
    {
        return $this->actionForm('cart/remove', ['voucher' => (string) $this->params->get('voucher')]);
    }

    /**
     * {{ gift_vouchers:check }} <input name="code"> <button>Check balance</button> {{ /gift_vouchers:check }}
     */
    public function check(): string
    {
        return $this->actionForm('check', [], ['errors' => $this->errorMessages()]);
    }

    /**
     * {{ gift_vouchers:balance }} {{ balance_formatted }} left {{ /gift_vouchers:balance }}
     *
     * After a balance check: the voucher's balance, status and expiry.
     */
    public function balance(): string|array|null
    {
        $result = session('gift_vouchers_check');

        if (! is_array($result)) {
            return $this->isPair ? '' : null;
        }

        return $this->isPair ? $this->parse($result) : $result['balance_formatted'];
    }

    /**
     * {{ gift_vouchers:applied }} {{ code }} {{ amount_formatted }} {{ /gift_vouchers:applied }}
     *
     * The vouchers used on the cart (or on an order, with order="…"), and
     * what each covers.
     */
    public function applied(): array|string
    {
        $rows = $this->appliedRows();

        // An empty loop draws nothing (Antlers would draw the contents once).
        return $rows === [] && $this->isPair ? '' : $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function appliedRows(): array
    {
        $data = $this->target();

        if (! $data) {
            return [];
        }

        $currency = Money::currency($data->site());

        return collect((array) $data->get(CartVouchers::BREAKDOWN, []))
            ->filter(fn ($line) => is_array($line) && isset($line['id']))
            ->map(fn (array $line) => [
                'id' => e((string) $line['id']),
                'code' => e('•••• '.($line['code'] ?? '')),
                'amount' => (int) ($line['amount'] ?? 0),
                'amount_formatted' => e(Money::format((int) ($line['amount'] ?? 0), $currency)),
            ])
            ->values()
            ->all();
    }

    /**
     * {{ gift_vouchers:total }}: how much of the cart (or order) vouchers cover, formatted.
     */
    public function total(): string
    {
        $data = $this->target();
        $total = (int) ($data?->get(CartVouchers::TOTAL, 0) ?? 0);

        if ($this->params->bool('raw')) {
            return (string) $total;
        }

        return e(Money::format($total, Money::currency($data?->site())));
    }

    /**
     * {{ gift_vouchers:has_applied }}: whether any vouchers are used on the cart.
     */
    public function hasApplied(): bool
    {
        return count($this->appliedRows()) > 0;
    }

    /**
     * {{ gift_vouchers:issued order="{{ id }}" }} {{ recipient }} {{ amount_formatted }} {{ /gift_vouchers:issued }}
     *
     * The vouchers bought on an order, for its confirmation page. Codes
     * aren't shown: they're emailed to the recipient.
     */
    public function issued(): array|string
    {
        $order = ($id = $this->params->get('order', $this->context->get('id'))) ? Order::find((string) $id) : null;
        $vouchers = $order ? app(OrderVouchers::class)->issuedFor($order) : collect();

        if ($vouchers->isEmpty()) {
            return $this->isPair ? '' : [];
        }

        return $vouchers->map(fn (Voucher $voucher) => [
            'code' => e($voucher->maskedCode()),
            'amount' => $voucher->initial_amount,
            'amount_formatted' => e($voucher->formatted($voucher->initial_amount)),
            'recipient_name' => $voucher->recipient_name ? e($voucher->recipient_name) : null,
            'recipient_email' => $voucher->recipient_email ? e($voucher->recipient_email) : null,
            'send_on' => $voucher->send_on?->toDateString(),
            'send_on_formatted' => $voucher->send_on ? e($voucher->send_on->toFormattedDayDateString()) : null,
            'is_scheduled' => $voucher->isScheduled(),
            'is_sent' => $voucher->sent_at !== null,
        ])->all();
    }

    /**
     * {{ if {gift_vouchers:is_voucher :product="id"} }}: whether a product is a gift voucher.
     */
    public function isVoucher(): bool
    {
        return $this->product() !== null;
    }

    /**
     * {{ gift_vouchers:success }}: the message after a form post, if any.
     */
    public function success(): ?string
    {
        $message = session('gift_vouchers_success');

        return is_string($message) ? e($message) : null;
    }

    /**
     * {{ gift_vouchers:error }} or {{ gift_vouchers:error field="amount" }}: what went wrong.
     */
    public function error(): ?string
    {
        $field = (string) $this->params->get('field', 'gift_vouchers');
        $message = $this->errorBag()?->first($field);

        return $message ? e($message) : null;
    }

    /**
     * A form posting to an action, with hidden fields, and the tag's
     * contents parsed with $data.
     */
    protected function actionForm(string $action, array $fields, array $data = []): string
    {
        $known = ['product', 'voucher', 'redirect', 'error_redirect', 'order'];

        $html = $this->formOpen(url("!/gift-vouchers/{$action}"), 'POST', $known);

        $fields += array_filter([
            '_redirect' => $this->params->get('redirect'),
            '_error_redirect' => $this->params->get('error_redirect'),
        ]);

        foreach ($fields as $name => $value) {
            $html .= '<input type="hidden" name="'.e($name).'" value="'.e((string) $value).'">';
        }

        $html .= $this->isPair ? $this->parse($data) : '';

        return $html.$this->formClose();
    }

    /**
     * The voucher product the tag is about: product="…", or the entry in context.
     */
    protected function product(): ?\DuncanMcClean\Cargo\Contracts\Products\Product
    {
        $id = $this->params->get('product', $this->context->get('id'));
        $id = is_object($id) && method_exists($id, 'value') ? $id->value() : $id;

        $product = is_string($id) && $id !== '' ? Product::find($id) : null;

        return $product && app(Products::class)->isVoucher($product) ? $product : null;
    }

    /**
     * The cart, or an order if order="…" is given.
     */
    protected function target(): CartContract|\DuncanMcClean\Cargo\Contracts\Orders\Order|null
    {
        if ($order = $this->params->get('order')) {
            return Order::find((string) $order);
        }

        return Cart::hasCurrentCart() ? Cart::current() : null;
    }

    /**
     * What was typed before the form came back with errors, escaped.
     *
     * @param  array<int, string>  $keys
     * @return array<string, string|null>
     */
    protected function old(array $keys): array
    {
        return collect($keys)->mapWithKeys(function (string $key) {
            $value = session()->getOldInput($key);

            return [$key => is_scalar($value) ? e((string) $value) : null];
        })->all();
    }

    /**
     * @return array<string, string>
     */
    protected function errorMessages(): array
    {
        return collect($this->errorBag()?->messages() ?? [])
            ->map(fn (array $messages) => e((string) ($messages[0] ?? '')))
            ->all();
    }

    protected function errorBag(): ?MessageBag
    {
        $errors = session('errors');

        return $errors ? $errors->getBag('gift_vouchers') : null;
    }
}
