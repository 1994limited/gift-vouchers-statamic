{{--
    The voucher email. Tables and inline styles, so it looks right in
    every mail app. Publish to edit: php artisan vendor:publish --tag=gift-vouchers-views
--}}
<!doctype html>
<html lang="{{ str_replace('_', '-', $voucher->siteObject()?->locale() ?? 'en') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $heading }}</title>
</head>
<body style="margin:0; padding:0; background:#f3f4f6; font-family:-apple-system, 'Segoe UI', Helvetica, Arial, sans-serif; color:#1f2937;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background:#ffffff; border-radius:12px; overflow:hidden; border:1px solid #e5e7eb;">
                <tr>
                    <td style="background:{{ $colour }}; padding:20px 28px; color:{{ $onColour }};">
                        @if($logo)
                            <img src="{{ $logo }}" alt="{{ $logoAlt }}" style="max-height:44px; max-width:180px; display:block; border:0;">
                        @else
                            <strong style="font-size:17px;">{{ $siteName }}</strong>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td style="padding:28px;">
                        <h1 style="margin:0 0 12px; font-size:22px; line-height:1.3; color:#111827;">{{ $heading }}</h1>
                        <p style="margin:0 0 20px; font-size:16px; line-height:1.5;">{{ $intro }}</p>

                        @if($giftMessage)
                            <p style="margin:0 0 24px; padding:4px 0 4px 14px; border-left:3px solid {{ $colour }}; font-size:16px; line-height:1.5; font-style:italic; color:#374151;">{!! nl2br(e($giftMessage)) !!}</p>
                        @endif

                        <p style="margin:0 0 4px; font-size:13px; color:#6b7280;">{{ $title }}</p>
                        <p style="margin:0 0 20px; font-size:34px; font-weight:bold; line-height:1.1; color:#111827;">{{ $amount }}</p>

                        <p style="margin:0 0 6px; font-size:12px; letter-spacing:0.12em; text-transform:uppercase; color:#6b7280;">{{ __('gift-vouchers::messages.document.code') }}</p>
                        <p style="margin:0 0 20px; padding:12px 16px; border:2px dashed #d1d5db; border-radius:8px; text-align:center; font-family:Menlo, Consolas, monospace; font-size:20px; font-weight:bold; letter-spacing:0.06em; color:#111827;">{{ $code }}</p>

                        <p style="margin:0 0 6px; font-size:15px; line-height:1.5;">{{ $howTo }}</p>
                        <p style="margin:0 0 24px; font-size:15px; line-height:1.5;">
                            {{ __('gift-vouchers::messages.document.expires') }}: {{ $expires ?? __('gift-vouchers::messages.document.never') }}
                        </p>

                        <table role="presentation" cellpadding="0" cellspacing="0">
                            <tr>
                                <td style="border-radius:6px; background:{{ $colour }};">
                                    <a href="{{ $printUrl }}" style="display:inline-block; padding:12px 18px; color:{{ $onColour }}; font-size:15px; font-weight:bold; text-decoration:none;">{{ __('gift-vouchers::messages.document.open') }}</a>
                                </td>
                            </tr>
                        </table>

                        @if($terms)
                            <p style="margin:24px 0 0; font-size:12px; line-height:1.5; color:#6b7280;">{!! nl2br(e($terms)) !!}</p>
                        @endif
                    </td>
                </tr>
            </table>
            <p style="margin:16px 0 0; font-size:12px; color:#9ca3af;"><a href="{{ $siteUrl }}" style="color:#6b7280;">{{ $siteName }}</a></p>
        </td>
    </tr>
</table>
</body>
</html>
