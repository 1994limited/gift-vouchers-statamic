<?php

namespace NineteenNinetyFour\GiftVouchers\Http;

use Illuminate\Http\Request;

/**
 * Reading what a visitor sent. A field can arrive as anything (an array,
 * say, from name="list[]" or an edited URL), so only plain text counts;
 * anything else is read as missing.
 */
trait ReadsText
{
    protected function text(Request $request, string $key, int $limit = 2000): ?string
    {
        $value = $request->input($key);

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $value = (string) $value;

        return $value === '' || strlen($value) > $limit ? null : $value;
    }
}
