{{--
    The voucher, to print and as a PDF: a card in the voucher's design
    (an engraved pattern unique to the voucher, the shop's own image, or
    plain), with the message, how to spend it and the terms below.

    PDFs are drawn by Dompdf, which reads simple CSS: absolute positions and
    points there, sizes relative to the card on screen. No web fonts.
    Publish to edit: php artisan vendor:publish --tag=gift-vouchers-views
--}}
@php($status = $voucher->status())
<!doctype html>
<html lang="{{ str_replace('_', '-', $voucher->siteObject()?->locale() ?? 'en') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} · {{ $siteName }}</title>
    <style>
        * { box-sizing: border-box; }
        @if($forPdf)
            {{-- Dompdf takes the page's margins from <html>. --}}
            @page { margin: 18mm 18mm; }
            html { margin: 18mm 18mm; }
            body { margin: 0; padding: 0; }
        @else
            html, body { margin: 0; padding: 0; }
        @endif
        body {
            font-family: 'DejaVu Sans', 'Helvetica Neue', Arial, sans-serif;
            color: #1f2937;
            background: {{ $forPdf ? '#ffffff' : '#ece8df' }};
            font-size: 10.5pt;
            line-height: 1.5;
        }
        .page { max-width: 820px; margin: 0 auto; padding: {{ $forPdf ? '0' : '32px 16px 48px' }}; }
        .tools { text-align: right; margin-bottom: 16px; }
        .tools a, .tools button {
            display: inline-block; font: inherit; font-size: 14px; line-height: 1; color: #1f2937; background: #fff;
            border: 1px solid #c9c4b8; border-radius: 6px; padding: 10px 14px; margin-left: 8px; text-decoration: none; cursor: pointer;
        }
        .tools a:focus-visible, .tools button:focus-visible { outline: 3px solid {{ $colour }}; outline-offset: 2px; }

        /* Below the card */
        .notice { background: #fef3c7; color: #78350f; padding: 10px 14px; font-size: 10pt; font-weight: bold; margin-bottom: 14px; border-radius: 6px; }
        .details { margin-top: {{ $forPdf ? '9mm' : '28px' }}; {{ $forPdf ? '' : 'background: #fff; border-radius: 10px; padding: 22px 24px;' }} }
        .message { font-family: 'DejaVu Serif', Georgia, serif; font-style: italic; font-size: 12pt; border-left: 3px solid {{ $colour }}; padding: 2px 0 2px 14px; margin: 0 0 18px; color: #374151; white-space: pre-line; }
        .message cite { display: block; font-style: normal; font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 9pt; color: #6b7280; margin-top: 6px; }
        .how { margin: 0 0 12px; }
        .facts { font-size: 9.5pt; color: #4b5563; margin: 0 0 12px; }
        .facts a { color: {{ $colour }}; text-decoration: underline; }
        .terms { font-size: 8pt; color: #6b7280; margin: 0; white-space: pre-line; border-top: 1px solid #e5e7eb; padding-top: 10px; }

        @media print {
            body { background: #fff; }
            .page { padding: 0; }
            .tools { display: none; }
            .details { background: none; padding: 0; }
        }
    </style>
</head>
<body>
<main class="page">
    @unless($forPdf)
        <div class="tools">
            <a href="{{ $pdfUrl }}">{{ __('gift-vouchers::messages.document.download') }}</a>
            <button type="button" onclick="window.print()">{{ __('gift-vouchers::messages.document.print') }}</button>
        </div>
    @endunless

    @if($status !== \NineteenNinetyFour\GiftVouchers\VoucherStatus::Active)
        <div class="notice" role="status">{{ __('gift-vouchers::messages.document.status_'.$status->value) }}</div>
    @endif

    @include('gift-vouchers::partials.card', ['serial' => $voucher->code_hint])

    <div class="details">
        @if($giftMessage)
            <blockquote class="message">{{ $giftMessage }}@if($senderName)<cite>— {{ $senderName }}</cite>@endif</blockquote>
        @endif

        <p class="how">{{ $howTo }}</p>

        <p class="facts">
            @if($voucher->balance !== $voucher->initial_amount)
                {{ __('gift-vouchers::messages.document.balance') }}: <strong>{{ $balance }}</strong> ·
            @endif
            {{ __('gift-vouchers::messages.document.where') }}: <a href="{{ $siteUrl }}">{{ preg_replace('#^https?://#', '', rtrim($siteUrl, '/')) }}</a>
        </p>

        @if($terms)
            <p class="terms">{{ $terms }}</p>
        @endif
    </div>
</main>
</body>
</html>
