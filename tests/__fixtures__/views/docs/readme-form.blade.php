{!! Statamic::tag('gift_vouchers:preview')->param('product', $id)->fetch() !!}

<s:gift_vouchers:form :product="$id" redirect="/basket">
    <input type="text" name="amount" value="50">
    <input type="text" name="recipient_name" placeholder="Their name">
    <input type="email" name="recipient_email" placeholder="Their email">
    <textarea name="message"></textarea>
    <button>Add to basket</button>
</s:gift_vouchers:form>
