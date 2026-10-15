<div>Subtotal {{ Statamic::tag('cart:sub_total')->fetch() }}</div>
<s:gift_vouchers:applied>
    <div>
        Gift voucher {!! $code !!} −{!! $amount_formatted !!}
        <s:gift_vouchers:remove :voucher="$id"><button>Remove</button></s:gift_vouchers:remove>
    </div>
</s:gift_vouchers:applied>
<div>To pay {{ Statamic::tag('cart:grand_total')->fetch() }}</div>
