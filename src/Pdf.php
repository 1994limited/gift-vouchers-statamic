<?php

namespace NineteenNinetyFour\GiftVouchers;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * The voucher as a PDF, drawn from the same view as the printable page.
 * Nothing is fetched from the web while it's drawn: the logo is embedded.
 */
class Pdf
{
    public function render(Voucher $voucher): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot([realpath(__DIR__.'/../resources')]);
        $options->setTempDir(sys_get_temp_dir());

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view(config('gift-vouchers.views.pdf', 'gift-vouchers::voucher'), array_merge(
            app(Presenter::class)->forDocument($voucher),
            ['forPdf' => true],
        ))->render());
        $dompdf->setPaper(config('gift-vouchers.pdf.paper', 'a4'), config('gift-vouchers.pdf.orientation', 'portrait'));
        $dompdf->render();

        return (string) $dompdf->output();
    }

    public function filename(Voucher $voucher): string
    {
        return 'gift-voucher-'.strtolower($voucher->code_hint).'.pdf';
    }
}
