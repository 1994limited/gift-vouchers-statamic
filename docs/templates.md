# Templates

Gift Vouchers gives you tags, not a theme: the markup and styles are yours. Every form works without JavaScript, posting to `/!/gift-vouchers/…` and coming back to the page with a message. The live preview adds a little JavaScript, and works without it too (it just doesn't change as the shopper types).

The examples are from [the demo shop](demo.md), trimmed down.

> **Escaping.** The `{{ gift_vouchers:* }}` tags escape everything shoppers typed. Values you read through Cargo's own tags (`{{ cart:line_items }}`'s `voucher_recipient_name`, say) aren't escaped: add `| sanitize`.

## Antlers or Blade

Every example below is Antlers, with the same thing in Blade under **Blade**: click it to open. Blade uses Statamic's tag syntax, for example:

```blade
<s:gift_vouchers:form product="voucher-25" redirect="/basket">
    <input type="text" name="recipient_name" value="{!! $old['recipient_name'] ?? '' !!}">
    <button type="submit">Add to basket for {!! $price !!}</button>
</s:gift_vouchers:form>

<s:gift_vouchers:applied>
    Gift voucher {!! $code !!} −{!! $amount_formatted !!}
</s:gift_vouchers:applied>

{!! Statamic::tag('gift_vouchers:preview')->param('product', $id)->fetch() !!}
```

Inside a tag pair, the tag's variables are Blade variables (`$price`, `$min_formatted`, `$old`…). For error messages in Blade, use `{{ Statamic::tag('gift_vouchers:error')->param('field', 'amount')->fetch() }}`, as `$errors` is Laravel's own. The tags escape everything they give you (Antlers doesn't), so print their values with `{!! !!}`: with `{{ }}` they'd be escaped twice, and an apostrophe would show as `&#039;`. Your own values still go in `{{ }}` as usual.

The voucher itself (the printable page, PDF and emails) is drawn with Blade views, which you can publish or swap for Antlers templates: see [Designs](designs.md). The partials for [Cargo's prebuilt checkout](prebuilt-checkout.md) are Antlers, as that checkout is.

## The voucher form

On the voucher product's page (the entry is in context, or pass `product="…"`):

```antlers
{{ gift_vouchers:form redirect="/basket" }}
    {{ if is_open }}
        <label for="amount">Amount ({{ min_formatted }} to {{ max_formatted }})</label>
        {{ suggested }}
            <button type="button" data-gift-vouchers-amount="{{ value }}" hidden>{{ formatted }}</button>
        {{ /suggested }}
        <input type="text" inputmode="decimal" id="amount" name="amount" value="{{ old:amount ?? '50' }}" required>
        {{ if errors:amount }}<p class="error">{{ errors:amount }}</p>{{ /if }}
    {{ else }}
        <p>{{ price }}</p>
    {{ /if }}

    <label for="recipient_name">Their name</label>
    <input type="text" id="recipient_name" name="recipient_name" value="{{ old:recipient_name }}" maxlength="{{ name_limit }}">

    <label for="recipient_email">Their email (or leave empty to get it yourself)</label>
    <input type="email" id="recipient_email" name="recipient_email" value="{{ old:recipient_email }}">
    {{ if errors:recipient_email }}<p class="error">{{ errors:recipient_email }}</p>{{ /if }}

    <label for="sender_name">From</label>
    <input type="text" id="sender_name" name="sender_name" value="{{ old:sender_name }}" maxlength="{{ name_limit }}">

    <label for="message">Message</label>
    <textarea id="message" name="message" maxlength="{{ message_limit }}">{{ old:message }}</textarea>

    <label for="send_on">Send it on (leave empty to send it when you pay)</label>
    <input type="date" id="send_on" name="send_on" value="{{ old:send_on }}" min="{{ earliest_send_on }}" max="{{ latest_send_on }}">

    <button type="submit">Add to basket</button>
{{ /gift_vouchers:form }}
```

<details>
<summary>Blade</summary>

```blade
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
```

</details>

Every field but the amount (for shopper-chosen amounts) is optional. Without a recipient's email, the voucher is emailed to the customer. Fields:

| Field | Is |
| --- | --- |
| `amount` | The amount, for vouchers where the shopper chooses (`25`, `25.50` or `£25.50`) |
| `variant` | The variant, if the product has Cargo's variants |
| `quantity` | 1 to 20. Each makes its own voucher with the same details |
| `recipient_name`, `sender_name` | Up to 100 characters |
| `recipient_email` | A valid address |
| `message` | Up to 500 characters; line breaks are kept |
| `send_on` | `YYYY-MM-DD`, from tomorrow up to a year ahead. Empty (or today) sends it when the order's paid |
| `design` | A design's handle, if there's a choice |

Buttons with `data-gift-vouchers-amount` fill in the amount box when the [preview's script](#the-live-preview) is on the page. They're `hidden` until it is, so they don't show as buttons that do nothing.

### A choice of designs

If you've set [designs](designs.md#designs), let the shopper choose one:

```antlers
{{ if has_designs }}
    <fieldset>
        <legend>Design</legend>
        {{ designs }}
            <label>
                <input type="radio" name="design" value="{{ handle }}" {{ if is_selected }}checked{{ /if }}>
                <img src="{{ preview }}" alt="" width="240" height="240">
                {{ name }}
            </label>
        {{ /designs }}
    </fieldset>
{{ /if }}
```

<details>
<summary>Blade</summary>

```blade
@if ($has_designs)
    <fieldset>
        <legend>Design</legend>
        @foreach ($designs as $design)
            <label>
                <input type="radio" name="design" value="{!! $design['handle'] !!}" @checked($design['is_selected'])>
                <img src="{!! $design['preview'] !!}" alt="" width="240" height="240">
                {!! $design['name'] !!}
            </label>
        @endforeach
    </fieldset>
@endif
```

</details>

`preview` is a small picture of each design: its engraved emblem, its image, or a plain frame.

## The live preview

```antlers
{{ gift_vouchers:preview }}
```

<details>
<summary>Blade</summary>

```blade
{!! Statamic::tag('gift_vouchers:preview')->param('product', $id)->fetch() !!}
```

</details>

This draws the voucher card exactly as it'll be printed, with a placeholder code, and fills it in from the voucher form as the shopper types: the amount, who it's for and from, and the design (with its title, colour and pattern). It adds its own small script; the card is drawn either way.

With more than one voucher form on a page, give the form an `id` and pass it: `{{ gift_vouchers:preview form="buy-voucher" }}`. To place the script yourself (at the end of `<body>`, say), use `scripts="false"` and `{{ gift_vouchers:preview_script }}`.

The card's classes start `gv-`, so your styles won't reach it by accident. Give its container a width; the card keeps its shape. On narrow screens it becomes a simple bordered block.

## Messages

After a form posts:

```antlers
{{ if {gift_vouchers:success} }}
    <p class="notice">{{ gift_vouchers:success }}</p>
{{ /if }}
{{ if {gift_vouchers:error} }}
    <p class="error">{{ gift_vouchers:error }}</p>
{{ /if }}
```

<details>
<summary>Blade</summary>

```blade
@if ($success = Statamic::tag('gift_vouchers:success')->fetch())
    <p class="notice">{!! $success !!}</p>
@endif
@if ($error = Statamic::tag('gift_vouchers:error')->fetch())
    <p class="error">{!! $error !!}</p>
@endif
```

</details>

Inside the forms, `{{ errors:amount }}`, `{{ errors:code }}` and so on are each field's message.

## Using a code (the basket)

```antlers
{{ gift_vouchers:redeem }}
    <label for="code">Gift voucher code</label>
    <input type="text" id="code" name="code" autocomplete="off" autocapitalize="characters" spellcheck="false">
    <button type="submit">Apply</button>
    {{ if errors:code }}<p class="error">{{ errors:code }}</p>{{ /if }}
{{ /gift_vouchers:redeem }}
```

<details>
<summary>Blade</summary>

```blade
<s:gift_vouchers:redeem>
    <label for="code">Gift voucher code</label>
    <input type="text" id="code" name="code" autocomplete="off" autocapitalize="characters" spellcheck="false">
    <button type="submit">Apply</button>
    @isset ($errors['code'])<p class="error">{!! $errors['code'] !!}</p>@endisset
</s:gift_vouchers:redeem>
```

</details>

Codes can be typed in any case, with or without spaces and dashes. Using a code is rate limited (10 a minute per visitor by default) so codes can't be guessed.

Then show what each voucher covers with the totals:

```antlers
<div>Subtotal {{ cart:sub_total }}</div>
{{ gift_vouchers:applied }}
    <div>
        Gift voucher {{ code }} −{{ amount_formatted }}
        {{ gift_vouchers:remove :voucher="id" }}<button>Remove</button>{{ /gift_vouchers:remove }}
    </div>
{{ /gift_vouchers:applied }}
<div>To pay {{ cart:grand_total }}</div>
```

<details>
<summary>Blade</summary>

```blade
<div>Subtotal {{ Statamic::tag('cart:sub_total')->fetch() }}</div>
<s:gift_vouchers:applied>
    <div>
        Gift voucher {!! $code !!} −{!! $amount_formatted !!}
        <s:gift_vouchers:remove :voucher="$id"><button>Remove</button></s:gift_vouchers:remove>
    </div>
</s:gift_vouchers:applied>
<div>To pay {{ Statamic::tag('cart:grand_total')->fetch() }}</div>
```

</details>

`{{ cart:grand_total }}` already has the vouchers taken off. `{{ gift_vouchers:total }}` is the total they cover.

Showing a voucher's details in the basket, from Cargo's line items (escape them):

```antlers
{{ cart:line_items }}
    <strong>{{ product:title }}</strong> {{ sub_total }}
    {{ if voucher_recipient_name }}<div>For {{ voucher_recipient_name | sanitize }}</div>{{ /if }}
    {{ if voucher_message }}<div>“{{ voucher_message | sanitize }}”</div>{{ /if }}
{{ /cart:line_items }}
```

<details>
<summary>Blade</summary>

```blade
<s:cart:line_items>
    <strong>{{ $product->title }}</strong> {{ $sub_total }}
    @if ($voucher_recipient_name ?? null)<div>For {{ $voucher_recipient_name }}</div>@endif
    @if ($voucher_message ?? null)<div>“{{ $voucher_message }}”</div>@endif
</s:cart:line_items>
```

</details>

## Checking a balance

```antlers
{{ gift_vouchers:balance }}
    <p>{{ balance_formatted }} left{{ if expires_formatted }}, to spend by {{ expires_formatted }}{{ /if }}.</p>
{{ /gift_vouchers:balance }}

{{ gift_vouchers:check }}
    <label for="code">Voucher code</label>
    <input type="text" id="code" name="code" required>
    <button type="submit">Check</button>
    {{ if errors:code }}<p class="error">{{ errors:code }}</p>{{ /if }}
{{ /gift_vouchers:check }}
```

<details>
<summary>Blade</summary>

```blade
<s:gift_vouchers:balance>
    <p>{!! $balance_formatted !!} left{!! $expires_formatted ? ', to spend by '.$expires_formatted : '' !!}.</p>
</s:gift_vouchers:balance>

<s:gift_vouchers:check>
    <label for="code">Voucher code</label>
    <input type="text" id="code" name="code" required>
    <button type="submit">Check</button>
    @isset ($errors['code'])<p class="error">{!! $errors['code'] !!}</p>@endisset
</s:gift_vouchers:check>
```

</details>

Only the last four characters of the code are ever shown back. Checking is rate limited with using a code.

## The order confirmation page

```antlers
{{ gift_vouchers:applied :order="id" }}
    <div>Paid with gift voucher {{ code }} −{{ amount_formatted }}</div>
{{ /gift_vouchers:applied }}

{{ gift_vouchers:issued :order="id" }}
    <p>
        {{ amount_formatted }} for {{ recipient_name ?? recipient_email }}:
        {{ if is_scheduled }}we'll email it on {{ send_on_formatted }}{{ else }}emailed{{ /if }}
    </p>
{{ /gift_vouchers:issued }}
```

<details>
<summary>Blade</summary>

```blade
<s:gift_vouchers:applied :order="$id">
    <div>Paid with gift voucher {!! $code !!} −{!! $amount_formatted !!}</div>
</s:gift_vouchers:applied>

<s:gift_vouchers:issued :order="$id">
    <p>
        {!! $amount_formatted !!} for {!! $recipient_name ?? $recipient_email !!}:
        @if ($is_scheduled) we'll email it on {!! $send_on_formatted !!} @else emailed @endif
    </p>
</s:gift_vouchers:issued>
```

</details>

Codes aren't shown on the confirmation page: they go to the recipient by email.
