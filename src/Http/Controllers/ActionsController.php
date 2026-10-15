<?php

namespace NineteenNinetyFour\GiftVouchers\Http\Controllers;

use DuncanMcClean\Cargo\Contracts\Cart\Cart as CartContract;
use DuncanMcClean\Cargo\Facades\Cart;
use DuncanMcClean\Cargo\Facades\Product;
use DuncanMcClean\Cargo\Products\Actions\ValidateStock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use NineteenNinetyFour\GiftVouchers\Cargo\CartVouchers;
use NineteenNinetyFour\GiftVouchers\Cargo\Products;
use NineteenNinetyFour\GiftVouchers\Designs;
use NineteenNinetyFour\GiftVouchers\Details;
use NineteenNinetyFour\GiftVouchers\Http\ReadsText;
use NineteenNinetyFour\GiftVouchers\Money;
use NineteenNinetyFour\GiftVouchers\Presenter;
use NineteenNinetyFour\GiftVouchers\Voucher;
use NineteenNinetyFour\GiftVouchers\VoucherException;

/**
 * The front-end actions, at /!/gift-vouchers/... Each answers JSON to
 * fetch() (Accept: application/json) and redirects back to a plain form
 * post, with a message flashed as "gift_vouchers_success", or errors in
 * the "gift_vouchers" error bag.
 */
class ActionsController
{
    use ReadsText;

    public const MAX_QUANTITY = 20;

    public function __construct(
        protected CartVouchers $carts,
        protected Products $products,
        protected Presenter $presenter,
    ) {}

    /**
     * Add a voucher product to the cart, with its amount and recipient.
     * Each voucher gets its own line item, so two vouchers for different
     * people stay apart.
     */
    public function add(Request $request): JsonResponse|RedirectResponse
    {
        $product = ($id = $this->text($request, 'product', 255)) ? Product::find($id) : null;

        if (! $product || ! $this->products->isVoucher($product) || ! $product->published()) {
            return $this->failed($request, VoucherException::because('not_a_voucher'), 'product');
        }

        $cart = Cart::current();
        $currency = Money::currency($cart->site());
        $data = [];
        $errors = [];

        if ($this->products->isOpen($product)) {
            $amount = Money::parse($this->text($request, 'amount', 30), $currency);
            $min = $this->products->min($product);
            $max = $this->products->max($product);

            if ($amount === null || $amount < $min || $amount > $max) {
                $errors['amount'] = __('gift-vouchers::messages.errors.amount_range', [
                    'min' => Money::format($min, $currency),
                    'max' => Money::format($max, $currency),
                ]);
            } else {
                $data[Products::AMOUNT] = $amount;
            }
        }

        $variant = null;

        if ($product->isVariantProduct()) {
            $variant = $product->variant((string) $this->text($request, 'variant', 255));

            if (! $variant) {
                $errors['variant'] = __('gift-vouchers::messages.errors.choose_amount');
            }
        }

        $quantity = $this->text($request, 'quantity', 4) ?? '1';

        if (! ctype_digit($quantity) || (int) $quantity < 1 || (int) $quantity > self::MAX_QUANTITY) {
            $errors['quantity'] = __('gift-vouchers::messages.errors.quantity', ['max' => self::MAX_QUANTITY]);
        }

        $input = [
            'recipient_name' => $this->text($request, 'recipient_name'),
            'recipient_email' => $this->text($request, 'recipient_email'),
            'sender_name' => $this->text($request, 'sender_name'),
            'message' => $this->text($request, 'message', 5000),
            'send_on' => $this->text($request, 'send_on', 20),
        ];

        if (Details::isBadEmail($input['recipient_email'])) {
            $errors['recipient_email'] = __('gift-vouchers::messages.errors.bad_email');
        }

        $design = $this->text($request, 'design', 100);

        if ($design !== null && ! app(Designs::class)->exists($design)) {
            $errors['design'] = __('gift-vouchers::messages.errors.bad_design');
        }

        if (Details::isBadDate($input['send_on'])) {
            $errors['send_on'] = __('gift-vouchers::messages.errors.bad_date');
        }

        if ($errors) {
            return $this->invalid($request, $errors);
        }

        try {
            app(ValidateStock::class)->handle(product: $product, variant: $variant, quantity: (int) $quantity);
        } catch (ValidationException) {
            return $this->failed($request, VoucherException::because('out_of_stock'), 'product');
        }

        $details = Details::from($input);

        $cart->lineItems()->create(array_filter(array_merge($data, [
            Products::RECIPIENT_NAME => $details->recipientName,
            Products::RECIPIENT_EMAIL => $details->recipientEmail,
            Products::SENDER_NAME => $details->senderName,
            Products::MESSAGE => $details->message,
            Products::SEND_ON => $details->sendOn?->toDateString(),
            Products::DESIGN => $design,
            'product' => $product->id(),
            'variant' => $variant?->key(),
            'quantity' => (int) $quantity,
        ]), fn ($value) => $value !== null));

        $cart->save();

        return $this->done($request, __('gift-vouchers::messages.added'), $cart);
    }

    /**
     * Use a voucher's code on the cart.
     */
    public function redeem(Request $request): JsonResponse|RedirectResponse
    {
        $code = $this->text($request, 'code', 100);

        if ($code === null) {
            return $this->failed($request, VoucherException::because('enter_code'), 'code');
        }

        if (! Cart::hasCurrentCart()) {
            return $this->failed($request, VoucherException::because('no_cart'), 'code');
        }

        $cart = Cart::current();

        try {
            $voucher = $this->carts->add($cart, $code);
        } catch (VoucherException $e) {
            return $this->failed($request, $e, 'code');
        }

        return $this->done($request, __('gift-vouchers::messages.redeemed', [
            'amount' => $voucher->formatted($this->carts->amountFor($cart, $voucher->id)),
        ]), $cart);
    }

    /**
     * Stop using a voucher on the cart.
     */
    public function remove(Request $request): JsonResponse|RedirectResponse
    {
        $id = $this->text($request, 'voucher', 64);

        if ($id === null || ! Cart::hasCurrentCart() || ! $this->carts->remove($cart = Cart::current(), $id)) {
            return $this->failed($request, VoucherException::because('not_on_cart'), 'voucher');
        }

        return $this->done($request, __('gift-vouchers::messages.removed'), $cart);
    }

    /**
     * Check a voucher's balance. The answer is flashed as
     * "gift_vouchers_check", for {{ gift_vouchers:balance }}.
     */
    public function check(Request $request): JsonResponse|RedirectResponse
    {
        $code = $this->text($request, 'code', 100);
        $voucher = $code !== null ? Voucher::findByCode($code) : null;

        if (! $voucher) {
            return $this->failed($request, VoucherException::because($code === null ? 'enter_code' : 'not_found'), 'code');
        }

        $result = $this->presenter->forTag($voucher);

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'voucher' => $result]);
        }

        return $this->back($request, '_redirect')->with('gift_vouchers_check', $result);
    }

    /**
     * The vouchers on the current cart, for scripts (Cargo's prebuilt
     * checkout, say). Read only.
     */
    public function cart(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'cart' => Cart::hasCurrentCart() ? $this->cartJson(Cart::current()) : null,
        ])->header('Cache-Control', 'no-store, private');
    }

    /**
     * @return array<string, mixed>
     */
    protected function cartJson(CartContract $cart): array
    {
        $currency = Money::currency($cart->site());

        return [
            'grand_total' => $cart->grandTotal(),
            'grand_total_formatted' => Money::format($cart->grandTotal(), $currency),
            'gift_voucher_total' => (int) $cart->get(CartVouchers::TOTAL, 0),
            'gift_voucher_total_formatted' => Money::format((int) $cart->get(CartVouchers::TOTAL, 0), $currency),
            'gift_vouchers' => collect((array) $cart->get(CartVouchers::BREAKDOWN, []))
                ->filter(fn ($line) => is_array($line) && isset($line['id']))
                ->map(fn (array $line) => [
                    'id' => (string) $line['id'],
                    'code' => '•••• '.($line['code'] ?? ''),
                    'amount' => (int) ($line['amount'] ?? 0),
                    'amount_formatted' => Money::format((int) ($line['amount'] ?? 0), $currency),
                ])
                ->values()
                ->all(),
        ];
    }

    protected function done(Request $request, string $message, CartContract $cart): JsonResponse|RedirectResponse
    {
        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'cart' => $this->cartJson($cart),
            ]);
        }

        return $this->back($request, '_redirect')->with('gift_vouchers_success', $message);
    }

    protected function failed(Request $request, VoucherException $e, string $field = 'gift_vouchers'): JsonResponse|RedirectResponse
    {
        return $this->invalid($request, [$field => $e->getMessage()], $e->status);
    }

    /**
     * @param  array<string, string>  $errors
     */
    protected function invalid(Request $request, array $errors, int $status = 422): JsonResponse|RedirectResponse
    {
        if ($request->wantsJson()) {
            return response()->json(['success' => false, 'message' => reset($errors), 'errors' => $errors], $status);
        }

        return $this->back($request, '_error_redirect')
            ->withErrors($errors + ['gift_vouchers' => reset($errors)], 'gift_vouchers')
            ->withInput($request->except(['code', '_token']));
    }

    /**
     * Back to where the form was, or to a "_redirect" on this site.
     */
    protected function back(Request $request, string $field): RedirectResponse
    {
        $to = (string) $this->text($request, $field);

        $local = $to !== '' && (str_starts_with($to, '/') && ! str_starts_with($to, '//') && ! str_starts_with($to, '/\\')
            || parse_url($to, PHP_URL_HOST) === $request->getHost());

        return $local ? redirect($to) : back();
    }
}
