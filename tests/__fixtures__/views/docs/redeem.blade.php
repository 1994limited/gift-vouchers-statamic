<s:gift_vouchers:redeem>
    <label for="code">Gift voucher code</label>
    <input type="text" id="code" name="code" autocomplete="off" autocapitalize="characters" spellcheck="false">
    <button type="submit">Apply</button>
    @isset ($errors['code'])<p class="error">{!! $errors['code'] !!}</p>@endisset
</s:gift_vouchers:redeem>
