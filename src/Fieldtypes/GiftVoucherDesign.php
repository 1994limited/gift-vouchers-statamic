<?php

namespace NineteenNinetyFour\GiftVouchers\Fieldtypes;

use NineteenNinetyFour\GiftVouchers\Designs;
use Statamic\Fieldtypes\Text;

/**
 * A voucher's design on its line item (in the order's receipt and on packing
 * slips), shown by name ("Birthday") rather than by handle.
 */
class GiftVoucherDesign extends Text
{
    protected static $handle = 'gift_voucher_design';

    protected $component = 'text';

    protected $selectable = false;

    public function preProcessIndex($value)
    {
        $designs = app(Designs::class);

        return is_string($value) && $designs->exists($value) ? $designs->find($value)->name : $value;
    }
}
