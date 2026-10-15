{{--
    The voucher card: on the printable page, in the PDF, and as the live
    preview on a shop's own page ({{ gift_vouchers:preview }}). Classes start
    "gv-" so it sits inside any site's styles.

    $forPdf    Dompdf draws it: sizes in points, literal colours.
    $preview   The shop page's preview: hooks for the script that fills it in.
--}}
@php
    $forPdf ??= false;
    $preview ??= false;
    $id = $preview ? 'gv-preview' : 'gv-card';
    // Font sizes: points in the PDF, a share of the card's width (cqw) on screen.
    $u = fn (float $cqw, float $pt) => $forPdf ? $pt.'pt' : $cqw.'cqw';
    // The design's colour: a CSS variable on screen (so a preview can change
    // design), the colour itself in the PDF.
    $c = $forPdf ? $colour : 'var(--gv-colour)';
@endphp
<style>
    .gv-card {
        --gv-colour: {{ $colour }};
        position: relative; overflow: hidden; background: #fffdf8; color: #1f2937; text-align: left;
        font-family: 'DejaVu Sans', 'Helvetica Neue', Arial, sans-serif; line-height: 1.5;
        @if($forPdf)
            width: 178mm; height: 81.9mm;
        @else
            width: 100%; aspect-ratio: 1000 / 460; container-type: inline-size;
            box-shadow: 0 24px 48px -28px rgba(31, 41, 55, .55), 0 2px 6px rgba(31, 41, 55, .08);
        @endif
    }
    .gv-card * { box-sizing: border-box; }
    .gv-card.gv-plain { border: 2px solid {{ $c }}; }
    .gv-art { position: absolute; top: 0; left: 0; width: 100%; height: 100%; display: block; }
    .gv-plain .gv-art, .gv-plain .gv-panel { display: none; }
    .gv-panel { display: none; position: absolute; top: 8%; left: 4.5%; width: 58%; height: 84%; background: #fffdf8; opacity: .93; }
    .gv-image .gv-panel { display: block; }
    .gv-mark { display: none; position: absolute; top: 26%; left: 72%; width: 22%; height: 48%; border: 1px solid {{ $c }}; border-radius: 50%; }
    .gv-plain .gv-mark { display: block; }
    .gv-image .gv-serial { display: none; }
    .gv-v { position: absolute; color: {{ $c }}; margin: 0; }
    .gv-brand { top: 12.5%; left: 7.5%; width: 52%; font-family: 'DejaVu Serif', Georgia, 'Times New Roman', serif; font-size: {{ $u(2.6, 13) }}; line-height: 1.1; }
    .gv-brand img { height: {{ $forPdf ? '9mm' : '4.6cqw' }}; width: auto; max-width: 100%; display: block; }
    .gv-eyebrow { top: 22%; left: 7.5%; font-size: {{ $u(1.25, 6.5) }}; letter-spacing: .32em; text-transform: uppercase; }
    .gv-amount { top: 29%; left: 7%; font-family: 'DejaVu Serif', Georgia, 'Times New Roman', serif; font-size: {{ $u(8.4, 40) }}; line-height: 1; letter-spacing: -.01em; white-space: nowrap; }
    .gv-people { top: 50.5%; left: 7.5%; width: 52%; font-size: {{ $u(1.7, 8.5) }}; line-height: 1.45; color: #374151; }
    .gv-people strong { color: {{ $c }}; font-weight: 600; }
    .gv-people span:empty, .gv-people span:empty + br { display: none; }
    .gv-code {
        bottom: 11.5%; left: 7.5%; padding: {{ $forPdf ? '2mm 3.5mm' : '1.1cqw 1.8cqw' }};
        background: #fffdf8; border: 1px dashed {{ $c }}; color: {{ $c }};
        font-family: 'DejaVu Sans Mono', Menlo, Consolas, monospace; font-size: {{ $u(2.15, 11) }}; font-weight: bold; letter-spacing: .08em;
    }
    .gv-code small { display: block; font-family: 'DejaVu Sans', Arial, sans-serif; font-weight: normal; font-size: {{ $u(.95, 5) }}; letter-spacing: .24em; text-transform: uppercase; color: #6b7280; margin-bottom: {{ $forPdf ? '.6mm' : '.3cqw' }}; }
    .gv-expires { bottom: 13%; left: 47%; font-size: {{ $u(1.25, 6.5) }}; color: #4b5563; line-height: 1.35; }
    .gv-expires span { display: block; letter-spacing: .24em; text-transform: uppercase; font-size: {{ $u(.95, 5) }}; color: #6b7280; }
    .gv-serial { top: 85.5%; left: 73%; width: 20%; text-align: center; font-size: {{ $u(.95, 5) }}; letter-spacing: .3em; text-transform: uppercase; }

    @unless($forPdf)
        /* Too narrow for the card's layout (a phone): a simple bordered block. */
        @container (max-width: 420px) {
            .gv-v { position: static; width: auto; margin: 0 0 8px; }
        }
        @media (max-width: 520px) {
            .gv-card { aspect-ratio: auto; height: auto; padding: 22px 20px 24px; container-type: normal; border: 2px solid {{ $c }}; }
            .gv-art, .gv-panel, .gv-mark { display: none !important; }
            .gv-v { position: static; display: block; width: auto; margin: 0 0 10px; }
            .gv-brand { font-size: 20px; } .gv-brand img { height: 32px; }
            .gv-eyebrow { font-size: 11px; } .gv-amount { font-size: 44px; }
            .gv-people { font-size: 15px; }
            .gv-code { font-size: 17px; padding: 10px 12px; display: inline-block; } .gv-code small { font-size: 9px; }
            .gv-expires, .gv-serial { font-size: 12px; text-align: left; letter-spacing: .14em; }
            .gv-expires span { font-size: 9px; }
        }
        @media print { .gv-card { box-shadow: none; } }
    @endunless
</style>

<section
    class="gv-card gv-{{ $artworkKind ?? 'plain' }}"
    id="{{ $id }}"
    @if($preview) data-gift-vouchers-preview aria-hidden="true" @else aria-label="{{ $title }}" @endif
>
    <img class="gv-art" src="{{ $artwork ?? '' }}" alt="" @if(! $artwork) hidden @endif @if($preview) data-preview-art @endif>
    <div class="gv-panel"></div>
    <div class="gv-mark"></div>

    <div class="gv-v gv-brand">
        @if($logo)
            <img src="{{ $logo }}" alt="{{ $logoAlt }}">
        @else
            {{ $siteName }}
        @endif
    </div>
    <div class="gv-v gv-eyebrow" @if($preview) data-preview="title" @endif>{{ $title }}</div>
    <div class="gv-v gv-amount" @if($preview) data-preview="amount" @endif>{{ $amount }}</div>

    @if($preview || $recipientName || $senderName)
        <div class="gv-v gv-people">
            <span @if($preview) data-preview="to" @endif>@if($recipientName){{ __('gift-vouchers::messages.document.for_label') }} <strong>{{ $recipientName }}</strong>@endif</span>@if($preview || ($recipientName && $senderName))<br>@endif
            <span @if($preview) data-preview="from" @endif>@if($senderName){{ __('gift-vouchers::messages.document.from_label') }} <strong>{{ $senderName }}</strong>@endif</span>
        </div>
    @endif

    <div class="gv-v gv-code"><small>{{ __('gift-vouchers::messages.document.code') }}</small>{{ $code }}</div>

    <div class="gv-v gv-expires">
        <span>{{ __('gift-vouchers::messages.document.expires') }}</span>
        {{ $expires ?? __('gift-vouchers::messages.document.never') }}
    </div>

    <div class="gv-v gv-serial">Nº {{ $serial }}</div>
</section>
