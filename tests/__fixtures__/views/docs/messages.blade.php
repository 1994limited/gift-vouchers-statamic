@if ($success = Statamic::tag('gift_vouchers:success')->fetch())
    <p class="notice">{!! $success !!}</p>
@endif
@if ($error = Statamic::tag('gift_vouchers:error')->fetch())
    <p class="error">{!! $error !!}</p>
@endif
