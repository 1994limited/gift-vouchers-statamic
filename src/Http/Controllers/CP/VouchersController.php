<?php

namespace NineteenNinetyFour\GiftVouchers\Http\Controllers\CP;

use Carbon\CarbonImmutable;
use DuncanMcClean\Cargo\Facades\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use NineteenNinetyFour\GiftVouchers\Cargo\PriceHook;
use NineteenNinetyFour\GiftVouchers\Details;
use NineteenNinetyFour\GiftVouchers\Events\VoucherUpdated;
use NineteenNinetyFour\GiftVouchers\GiftVouchers;
use NineteenNinetyFour\GiftVouchers\Money;
use NineteenNinetyFour\GiftVouchers\Presenter;
use NineteenNinetyFour\GiftVouchers\Transaction;
use NineteenNinetyFour\GiftVouchers\Voucher;
use NineteenNinetyFour\GiftVouchers\VoucherException;
use NineteenNinetyFour\GiftVouchers\VoucherStatus;
use Statamic\Facades\CP\Toast;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Store → Gift Vouchers: every voucher, its balance and history, and
 * making and changing them by hand.
 */
class VouchersController extends CpController
{
    public function __construct(Request $request, protected GiftVouchers $vouchers, protected Presenter $presenter)
    {
        parent::__construct($request);
    }

    public function index(Request $request): Response
    {
        $this->authorize('view gift vouchers');

        [$search, $status] = $this->filters($request);
        $paginator = $this->query($search, $status)->latest()->latest('id')->paginate(25)->withQueryString();

        return Inertia::render('gift-vouchers::Index', [
            'search' => $search,
            'status' => $status?->value,
            'statuses' => collect(VoucherStatus::cases())->map(fn (VoucherStatus $s) => ['value' => $s->value, 'label' => $s->label()])->all(),
            'vouchers' => collect($paginator->items())->map(fn (Voucher $voucher) => $this->presenter->row($voucher))->all(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
            'outstanding' => $this->outstanding(),
            'priceHookInstalled' => PriceHook::isInstalled(),
            'canCreate' => User::current()->can('create gift vouchers'),
            'urls' => [
                'index' => cp_route('gift-vouchers.index'),
                'create' => cp_route('gift-vouchers.create'),
                'export' => cp_route('gift-vouchers.export'),
            ],
        ]);
    }

    /**
     * The vouchers shown, as a spreadsheet (CSV).
     */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('view gift vouchers');

        [$search, $status] = $this->filters($request);
        $query = $this->query($search, $status)->latest();

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Code', 'Status', 'Currency', 'Amount', 'Balance', 'Recipient', 'Recipient email', 'Order', 'Created', 'Expires'], escape: '');

            $query->chunk(500, function (Collection $vouchers) use ($out) {
                foreach ($vouchers as $voucher) {
                    fputcsv($out, array_map(fn ($value) => $this->csvCell($value), [
                        $voucher->maskedCode(),
                        $voucher->status()->value,
                        $voucher->currency,
                        Money::toDecimal($voucher->initial_amount, $voucher->currency),
                        Money::toDecimal($voucher->balance, $voucher->currency),
                        $voucher->recipient_name,
                        $voucher->recipient_email,
                        $voucher->order_id ? Order::find($voucher->order_id)?->orderNumber() : null,
                        $voucher->created_at?->toDateTimeString(),
                        $voucher->expires_at?->toDateString(),
                    ]), escape: '');
                }
            });

            fclose($out);
        }, 'gift-vouchers-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function create(): Response
    {
        $this->authorize('create gift vouchers');

        $months = config('gift-vouchers.expiry_months');

        return Inertia::render('gift-vouchers::Create', [
            'sites' => $this->sites(),
            'defaultExpiry' => is_numeric($months) && $months > 0 ? now()->addMonths((int) $months)->toDateString() : null,
            'today' => now()->toDateString(),
            'limits' => ['name' => Details::NAME_LIMIT, 'message' => Details::MESSAGE_LIMIT],
            'urls' => [
                'index' => cp_route('gift-vouchers.index'),
                'store' => cp_route('gift-vouchers.store'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $this->authorize('create gift vouchers');

        $site = Site::get((string) $request->input('site')) ?? Site::default();
        $currency = Money::currency($site);

        $request->validate([
            'amount' => ['required', 'string', 'max:20'],
            'site' => ['nullable', 'string'],
            'recipient_name' => ['nullable', 'string', 'max:'.Details::NAME_LIMIT],
            'recipient_email' => ['nullable', 'string', 'email:filter', 'max:254'],
            'sender_name' => ['nullable', 'string', 'max:'.Details::NAME_LIMIT],
            'message' => ['nullable', 'string', 'max:'.Details::MESSAGE_LIMIT],
            'send_on' => ['nullable', 'date_format:Y-m-d', 'after:today', 'before_or_equal:'.now()->addYear()->toDateString()],
            'expires_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'code' => ['nullable', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:500'],
            'send' => ['nullable', 'boolean'],
        ]);

        $amount = Money::parse($request->input('amount'), $currency);

        if ($amount === null || $amount <= 0) {
            throw ValidationException::withMessages(['amount' => __('gift-vouchers::messages.errors.invalid_amount')]);
        }

        $send = $request->boolean('send');

        if ($send && ! Details::email($request->input('recipient_email'))) {
            throw ValidationException::withMessages(['recipient_email' => __('gift-vouchers::messages.cp.email_needed')]);
        }

        try {
            $voucher = $this->vouchers->create(
                amount: $amount,
                currency: $currency,
                details: $request->only(['recipient_name', 'recipient_email', 'sender_name', 'message', 'send_on']),
                code: filled($request->input('code')) ? (string) $request->input('code') : null,
                expiresAt: filled($request->input('expires_at')) ? CarbonImmutable::createFromFormat('!Y-m-d', $request->input('expires_at'))->endOfDay() : null,
                site: $site->handle(),
                note: $request->input('note'),
                send: $send,
            );
        } catch (VoucherException $e) {
            throw ValidationException::withMessages([in_array($e->reason, ['invalid_custom_code', 'duplicate_code']) ? 'code' : 'amount' => $e->getMessage()]);
        }

        Toast::success(__('gift-vouchers::messages.cp.created'));

        return $request->wantsJson()
            ? response()->json(['redirect' => $voucher->editUrl()])
            : redirect()->to($voucher->editUrl());
    }

    public function show(string $voucher): Response
    {
        $this->authorize('view gift vouchers');

        $voucher = $this->find($voucher);
        $order = $voucher->order();
        $canEdit = User::current()->can('edit gift vouchers');

        return Inertia::render('gift-vouchers::Show', [
            'voucher' => array_merge($this->presenter->row($voucher), [
                'sender_name' => $voucher->sender_name,
                'message' => $voucher->message,
                'notes' => $voucher->notes,
                'balance_decimal' => Money::toDecimal($voucher->balance, $voucher->currency),
                'spent' => $voucher->formatted($voucher->spent()),
                'expires_on' => $voucher->expires_at?->toDateString(),
                'send_on' => $voucher->send_on?->toFormattedDayDateString(),
                'sent_at' => $voucher->sent_at?->toDayDateTimeString(),
                'email_to' => $voucher->emailAddress(),
                'created_at_formatted' => $voucher->created_at?->toDayDateTimeString(),
                'created_by' => $this->userName($voucher->created_by),
                'order' => $order ? ['number' => $order->orderNumber(), 'url' => $order->editUrl()] : ($voucher->order_id ? ['number' => null, 'url' => null] : null),
                'is_disabled' => $voucher->disabled_at !== null,
            ]),
            'transactions' => $voucher->transactions()->get()->reverse()->values()->map(fn (Transaction $t) => $this->transaction($voucher, $t))->all(),
            'canEdit' => $canEdit,
            'limits' => ['name' => Details::NAME_LIMIT, 'message' => Details::MESSAGE_LIMIT],
            'urls' => [
                'index' => cp_route('gift-vouchers.index'),
                'update' => cp_route('gift-vouchers.update', $voucher->id),
                'code' => cp_route('gift-vouchers.code', $voucher->id),
                'adjust' => cp_route('gift-vouchers.adjust', $voucher->id),
                'disable' => cp_route('gift-vouchers.disable', $voucher->id),
                'enable' => cp_route('gift-vouchers.enable', $voucher->id),
                'send' => cp_route('gift-vouchers.send', $voucher->id),
                'print' => $canEdit ? $voucher->printUrl() : null,
                'pdf' => $canEdit ? $voucher->pdfUrl() : null,
            ],
        ]);
    }

    /**
     * Change who a voucher is for, its message, expiry and notes.
     */
    public function update(Request $request, string $voucher): RedirectResponse
    {
        $this->authorize('edit gift vouchers');

        $voucher = $this->find($voucher);

        $request->validate([
            'recipient_name' => ['nullable', 'string', 'max:'.Details::NAME_LIMIT],
            'recipient_email' => ['nullable', 'string', 'email:filter', 'max:254'],
            'sender_name' => ['nullable', 'string', 'max:'.Details::NAME_LIMIT],
            'message' => ['nullable', 'string', 'max:'.Details::MESSAGE_LIMIT],
            'expires_at' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $voucher->fill([
            'recipient_name' => Details::line($request->input('recipient_name'), Details::NAME_LIMIT),
            'recipient_email' => Details::email($request->input('recipient_email')),
            'sender_name' => Details::line($request->input('sender_name'), Details::NAME_LIMIT),
            'message' => Details::text($request->input('message'), Details::MESSAGE_LIMIT),
            'expires_at' => filled($request->input('expires_at')) ? CarbonImmutable::createFromFormat('!Y-m-d', $request->input('expires_at'))->endOfDay() : null,
            'notes' => Details::text($request->input('notes'), 2000),
        ])->save();

        event(new VoucherUpdated($voucher));

        Toast::success(__('gift-vouchers::messages.cp.saved'));

        return back();
    }

    /**
     * The full code, to read out to a customer. Fetched only when asked for.
     */
    public function code(string $voucher): JsonResponse
    {
        $this->authorize('edit gift vouchers');

        $voucher = $this->find($voucher);

        return response()->json(['code' => $voucher->code()])->header('Cache-Control', 'no-store, private');
    }

    /**
     * Add to or take from a balance, with a note saying why.
     */
    public function adjust(Request $request, string $voucher): RedirectResponse
    {
        $this->authorize('edit gift vouchers');

        $voucher = $this->find($voucher);

        $request->validate([
            'direction' => ['required', 'in:add,remove'],
            'amount' => ['required', 'string', 'max:20'],
            'note' => ['required', 'string', 'max:500'],
        ]);

        $amount = Money::parse($request->input('amount'), $voucher->currency);

        if ($amount === null || $amount <= 0) {
            throw ValidationException::withMessages(['amount' => __('gift-vouchers::messages.errors.invalid_amount')]);
        }

        try {
            $this->vouchers->adjust($voucher, $request->input('direction') === 'add' ? $amount : -$amount, (string) $request->input('note'));
        } catch (VoucherException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        Toast::success(__('gift-vouchers::messages.cp.adjusted', ['balance' => $voucher->formatted($voucher->balance)]));

        return back();
    }

    public function disable(string $voucher): RedirectResponse
    {
        $this->authorize('edit gift vouchers');

        $this->vouchers->disable($this->find($voucher));

        Toast::success(__('gift-vouchers::messages.cp.disabled'));

        return back();
    }

    public function enable(string $voucher): RedirectResponse
    {
        $this->authorize('edit gift vouchers');

        $this->vouchers->enable($this->find($voucher));

        Toast::success(__('gift-vouchers::messages.cp.enabled'));

        return back();
    }

    /**
     * Email the voucher again (or now, if it was waiting for its send date).
     */
    public function send(string $voucher): RedirectResponse
    {
        $this->authorize('edit gift vouchers');

        $voucher = $this->find($voucher);

        try {
            $this->vouchers->send($voucher);
        } catch (VoucherException $e) {
            Toast::error($e->getMessage());

            return back();
        }

        Toast::success(__('gift-vouchers::messages.cp.sent', ['email' => $voucher->emailAddress()]));

        return back();
    }

    protected function find(string $id): Voucher
    {
        return Voucher::query()->find($id) ?? abort(404);
    }

    /**
     * @return array{0: ?string, 1: ?VoucherStatus}
     */
    protected function filters(Request $request): array
    {
        $search = $request->query('search');
        $search = is_string($search) ? trim(mb_substr($search, 0, 100)) : '';
        $status = $request->query('status');

        return [$search !== '' ? $search : null, is_string($status) ? VoucherStatus::tryFrom($status) : null];
    }

    protected function query(?string $search, ?VoucherStatus $status): Builder
    {
        return Voucher::query()
            ->when($status, fn (Builder $query) => $query->whereStatus($status))
            ->when($search, function (Builder $query) use ($search) {
                // A full code finds its voucher; otherwise the last four
                // characters, a recipient, or an order number.
                $orderIds = ctype_digit($search) ? Order::query()->where('order_number', (int) $search)->get()->map->id()->all() : [];
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';

                $query->where(fn (Builder $q) => $q
                    ->whereCode($search)
                    ->orWhere('code_hint', strtoupper($search))
                    ->orWhere('recipient_name', 'like', $like)
                    ->orWhere('recipient_email', 'like', $like)
                    ->when($orderIds, fn (Builder $q) => $q->orWhereIn('order_id', $orderIds)));
            });
    }

    /**
     * What's still to be spent on active vouchers, per currency: money the
     * shop owes its customers.
     *
     * @return array<int, array{currency: string, amount: string, count: int}>
     */
    protected function outstanding(): array
    {
        return Voucher::query()->usable()
            ->selectRaw('currency, sum(balance) as total, count(*) as vouchers')
            ->groupBy('currency')
            ->get()
            ->map(fn ($row) => [
                'currency' => $row->currency,
                'amount' => Money::format((int) $row->total, $row->currency),
                'count' => (int) $row->vouchers,
            ])
            ->all();
    }

    /**
     * @return array<int, array{handle: string, name: string, currency: string}>
     */
    protected function sites(): array
    {
        return Site::all()->map(fn ($site) => [
            'handle' => $site->handle(),
            'name' => $site->name(),
            'currency' => Money::currency($site),
        ])->values()->all();
    }

    protected function transaction(Voucher $voucher, Transaction $transaction): array
    {
        $order = $transaction->order_id ? Order::find($transaction->order_id) : null;

        return [
            'id' => $transaction->id,
            'type' => $transaction->type->value,
            'label' => $transaction->type->label(),
            'amount' => ($transaction->amount > 0 ? '+' : '−').$voucher->formatted(abs($transaction->amount)),
            'is_credit' => $transaction->amount > 0,
            'balance_after' => $voucher->formatted($transaction->balance_after),
            'note' => $transaction->note,
            'user' => $this->userName($transaction->user_id),
            'order' => $order ? ['number' => $order->orderNumber(), 'url' => $order->editUrl()] : null,
            'date' => $transaction->created_at?->toDayDateTimeString(),
            'date_iso' => $transaction->created_at?->toIso8601String(),
        ];
    }

    protected function userName(?string $id): ?string
    {
        if (! $id) {
            return null;
        }

        $user = User::find($id);

        return $user ? ($user->name() ?: $user->email()) : __('gift-vouchers::messages.cp.deleted_user');
    }

    /**
     * A spreadsheet cell that can't be read as a formula.
     */
    protected function csvCell(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
