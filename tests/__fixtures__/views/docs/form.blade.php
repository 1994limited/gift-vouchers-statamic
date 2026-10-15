<s:gift_vouchers:form :product="$id" redirect="/basket">
    @if ($is_open)
        <label for="amount">Amount ({!! $min_formatted !!} to {!! $max_formatted !!})</label>
        @foreach ($suggested as $amount)
            <button type="button" data-gift-vouchers-amount="{!! $amount['value'] !!}" hidden>{!! $amount['formatted'] !!}</button>
        @endforeach
        <input type="text" inputmode="decimal" id="amount" name="amount" value="{!! $old['amount'] ?? '50' !!}" required>
        @isset ($errors['amount'])<p class="error">{!! $errors['amount'] !!}</p>@endisset
    @else
        <p>{!! $price !!}</p>
    @endif

    <label for="recipient_name">Their name</label>
    <input type="text" id="recipient_name" name="recipient_name" value="{!! $old['recipient_name'] ?? '' !!}" maxlength="{{ $name_limit }}">

    <label for="recipient_email">Their email (or leave empty to get it yourself)</label>
    <input type="email" id="recipient_email" name="recipient_email" value="{!! $old['recipient_email'] ?? '' !!}">
    @isset ($errors['recipient_email'])<p class="error">{!! $errors['recipient_email'] !!}</p>@endisset

    <label for="sender_name">From</label>
    <input type="text" id="sender_name" name="sender_name" value="{!! $old['sender_name'] ?? '' !!}" maxlength="{{ $name_limit }}">

    <label for="message">Message</label>
    <textarea id="message" name="message" maxlength="{{ $message_limit }}">{!! $old['message'] ?? '' !!}</textarea>

    <label for="send_on">Send it on (leave empty to send it when you pay)</label>
    <input type="date" id="send_on" name="send_on" value="{!! $old['send_on'] ?? '' !!}" min="{{ $earliest_send_on }}" max="{{ $latest_send_on }}">

    <button type="submit">Add to basket</button>
</s:gift_vouchers:form>
