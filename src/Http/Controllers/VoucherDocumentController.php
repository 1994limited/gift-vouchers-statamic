<?php

namespace NineteenNinetyFour\GiftVouchers\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use NineteenNinetyFour\GiftVouchers\Design;
use NineteenNinetyFour\GiftVouchers\Designs;
use NineteenNinetyFour\GiftVouchers\Pdf;
use NineteenNinetyFour\GiftVouchers\Presenter;
use NineteenNinetyFour\GiftVouchers\Voucher;

/**
 * The voucher to print, and as a PDF. Reached by signed links only (the
 * route checks the signature), as they show the code.
 */
class VoucherDocumentController
{
    public function print(string $voucher, Presenter $presenter): Response
    {
        $voucher = Voucher::query()->findOrFail($voucher);

        return response()
            ->view(config('gift-vouchers.views.print', 'gift-vouchers::voucher'), $presenter->forDocument($voucher))
            ->withHeaders($this->privateHeaders());
    }

    public function pdf(string $voucher, Pdf $pdf): Response
    {
        $voucher = Voucher::query()->findOrFail($voucher);

        return response($pdf->render($voucher), 200, array_merge($this->privateHeaders(), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$pdf->filename($voucher).'"',
        ]));
    }

    /**
     * The live preview's script ({{ gift_vouchers:preview }} adds it).
     */
    public function script(): Response
    {
        return response(file_get_contents(__DIR__.'/../../../resources/js/frontend/preview.js'), 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * A design's card, for the preview: the engraved pattern (drawn from the
     * design, not a voucher, so it's the same for everyone) or its image.
     */
    public function designArtwork(string $design, Designs $designs): Response|RedirectResponse
    {
        abort_unless($designs->exists($design), 404);

        $design = $designs->find($design);

        return match ($design->kind()) {
            Design::IMAGE => redirect()->away($design->image->absoluteUrl()),
            Design::ENGRAVED => response($design->artworkFor($design->handle)->card(), 200, [
                'Content-Type' => 'image/svg+xml',
                'Cache-Control' => 'public, max-age=3600',
                'X-Content-Type-Options' => 'nosniff',
            ]),
            default => abort(404),
        };
    }

    /**
     * Never cached or indexed, and the link isn't passed on to other sites.
     *
     * @return array<string, string>
     */
    protected function privateHeaders(): array
    {
        return [
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
        ];
    }
}
