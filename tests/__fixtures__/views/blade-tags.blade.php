<s:gift_vouchers:form product="voucher-25">
    <span>Pay {!! $price !!}</span>
</s:gift_vouchers:form>
<s:gift_vouchers:redeem><input name="code"></s:gift_vouchers:redeem>
<s:gift_vouchers:applied>{!! $code !!}={!! $amount_formatted !!}</s:gift_vouchers:applied>
<s:gift_vouchers:issued :order="$orderId">[{!! $recipient_name !!}]</s:gift_vouchers:issued>
Total {!! Statamic::tag('gift_vouchers:total')->fetch() !!}
