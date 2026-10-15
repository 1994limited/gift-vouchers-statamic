<?php

namespace NineteenNinetyFour\GiftVouchers\Http\Controllers;

use Illuminate\Http\Response;
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
            ->view('gift-vouchers::voucher', $presenter->forDocument($voucher))
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
