{{-- Plain text, so nothing is HTML-escaped here. --}}
{!! $heading !!}

{!! $intro !!}
@if($message)

"{!! $message !!}"
@endif

{!! $title !!}: {!! $amount !!}
{!! __('gift-vouchers::messages.document.code') !!}: {!! $code !!}
{!! __('gift-vouchers::messages.document.expires') !!}: {!! $expires ?? __('gift-vouchers::messages.document.never') !!}

{!! $howTo !!}

{!! __('gift-vouchers::messages.document.open') !!}: {!! $printUrl !!}
@if($terms)

{!! $terms !!}
@endif

{!! $siteName !!} · {!! $siteUrl !!}
