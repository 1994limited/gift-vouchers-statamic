<s:gift_vouchers:balance>
    <p>{!! $balance_formatted !!} left{!! $expires_formatted ? ', to spend by '.$expires_formatted : '' !!}.</p>
</s:gift_vouchers:balance>

<s:gift_vouchers:check>
    <label for="code">Voucher code</label>
    <input type="text" id="code" name="code" required>
    <button type="submit">Check</button>
    @isset ($errors['code'])<p class="error">{!! $errors['code'] !!}</p>@endisset
</s:gift_vouchers:check>
