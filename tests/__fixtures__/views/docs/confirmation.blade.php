<s:gift_vouchers:applied :order="$id">
    <div>Paid with gift voucher {!! $code !!} −{!! $amount_formatted !!}</div>
</s:gift_vouchers:applied>

<s:gift_vouchers:issued :order="$id">
    <p>
        {!! $amount_formatted !!} for {!! $recipient_name ?? $recipient_email !!}:
        @if ($is_scheduled) we'll email it on {!! $send_on_formatted !!} @else emailed @endif
    </p>
</s:gift_vouchers:issued>
