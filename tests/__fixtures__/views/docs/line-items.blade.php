<s:cart:line_items>
    <strong>{{ $product->title }}</strong> {{ $sub_total }}
    @if ($voucher_recipient_name ?? null)<div>For {{ $voucher_recipient_name }}</div>@endif
    @if ($voucher_message ?? null)<div>“{{ $voucher_message }}”</div>@endif
</s:cart:line_items>
