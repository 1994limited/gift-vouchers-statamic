{{--
    The voucher, to print and as a PDF. PDFs are drawn by Dompdf, which
    reads simple CSS: tables for layout, no flexbox or grid, no web fonts.
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
        @page { margin: 18mm; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: 'DejaVu Sans', 'Helvetica Neue', Arial, sans-serif;
            color: #1f2937;
            background: {{ $forPdf ? '#ffffff' : '#f3f4f6' }};
            font-size: 11pt;
            line-height: 1.5;
        }
        .page { max-width: 720px; margin: 0 auto; padding: {{ $forPdf ? '0' : '32px 16px' }}; }
        .tools { text-align: right; margin-bottom: 16px; }
        .tools a, .tools button {
            display: inline-block; font: inherit; font-size: 14px; line-height: 1; color: #1f2937; background: #fff;
            border: 1px solid #d1d5db; border-radius: 6px; padding: 10px 14px; margin-left: 8px; text-decoration: none; cursor: pointer;
        }
        .tools a:focus-visible, .tools button:focus-visible { outline: 3px solid {{ $colour }}; outline-offset: 2px; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; }
        .band { background: {{ $colour }}; color: {{ $onColour }}; padding: 22px 28px; }
        .band table { width: 100%; border-collapse: collapse; }
        .band td { vertical-align: middle; padding: 0; }
        .logo { max-height: 44px; max-width: 180px; }
        .eyebrow { font-size: 9pt; letter-spacing: 0.14em; text-transform: uppercase; opacity: 0.85; }
        .site { font-size: 13pt; font-weight: bold; }
        .body { padding: 28px; }
        .title { font-size: 12pt; color: #6b7280; margin: 0 0 4px; }
        .amount { font-size: 34pt; font-weight: bold; line-height: 1.1; margin: 0 0 20px; color: #111827; }
        .to { margin: 0 0 18px; font-size: 12pt; }
        .message { border-left: 3px solid {{ $colour }}; padding: 4px 0 4px 14px; margin: 0 0 22px; font-style: italic; color: #374151; white-space: pre-line; }
        .code-label { font-size: 9pt; letter-spacing: 0.14em; text-transform: uppercase; color: #6b7280; margin: 0 0 6px; }
        .code {
            font-family: 'DejaVu Sans Mono', 'SFMono-Regular', Menlo, Consolas, monospace; font-size: 18pt; font-weight: bold;
            letter-spacing: 0.06em; border: 2px dashed #d1d5db; border-radius: 8px; padding: 12px 16px; margin: 0 0 18px;
            text-align: center; color: #111827; word-break: break-all;
        }
        .facts { width: 100%; border-collapse: collapse; margin: 0 0 18px; font-size: 10pt; }
        .facts td { padding: 6px 0; border-top: 1px solid #f3f4f6; }
        .facts td.label { color: #6b7280; width: 40%; }
        .how { font-size: 10pt; margin: 0 0 14px; }
        .terms { font-size: 8.5pt; color: #6b7280; margin: 0; white-space: pre-line; }
        .notice { background: #fef3c7; color: #78350f; padding: 12px 28px; font-size: 10pt; font-weight: bold; }
        @media (max-width: 480px) {
            .band, .body { padding: 20px; }
            .amount { font-size: 28pt; }
            .code { font-size: 14pt; }
        }
        @media print {
            body { background: #fff; }
            .page { padding: 0; }
            .tools { display: none; }
            .card { border: 0; }
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

    <div class="card">
        <div class="band">
            <table role="presentation">
                <tr>
                    <td>
                        @if($logo)
                            <img class="logo" src="{{ $logo }}" alt="{{ $logoAlt }}">
                        @else
                            <div class="site">{{ $siteName }}</div>
                        @endif
                    </td>
                    <td style="text-align: right;"><span class="eyebrow">{{ $title }}</span></td>
                </tr>
            </table>
        </div>

        @if($status !== \NineteenNinetyFour\GiftVouchers\VoucherStatus::Active)
            <div class="notice" role="status">{{ __('gift-vouchers::messages.document.status_'.$status->value) }}</div>
        @endif

        <div class="body">
            <p class="title">{{ $title }}</p>
            <p class="amount">{{ $amount }}</p>

            @if($recipientName || $senderName)
                <p class="to">
                    @if($recipientName){{ __('gift-vouchers::messages.document.for', ['name' => $recipientName]) }}@endif
                    @if($recipientName && $senderName)<br>@endif
                    @if($senderName){{ __('gift-vouchers::messages.document.from', ['name' => $senderName]) }}@endif
                </p>
            @endif

            @if($message)
                <p class="message">{{ $message }}</p>
            @endif

            <p class="code-label">{{ __('gift-vouchers::messages.document.code') }}</p>
            <p class="code">{{ $code }}</p>

            <table class="facts" role="presentation">
                @if($voucher->balance !== $voucher->initial_amount)
                    <tr><td class="label">{{ __('gift-vouchers::messages.document.balance') }}</td><td>{{ $balance }}</td></tr>
                @endif
                <tr>
                    <td class="label">{{ __('gift-vouchers::messages.document.expires') }}</td>
                    <td>{{ $expires ?? __('gift-vouchers::messages.document.never') }}</td>
                </tr>
                <tr><td class="label">{{ __('gift-vouchers::messages.document.where') }}</td><td>{{ preg_replace('#^https?://#', '', rtrim($siteUrl, '/')) }}</td></tr>
            </table>

            <p class="how">{{ $howTo }}</p>

            @if($terms)
                <p class="terms">{{ $terms }}</p>
            @endif
        </div>
    </div>
</main>
</body>
</html>
