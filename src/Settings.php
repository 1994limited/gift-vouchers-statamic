<?php

namespace NineteenNinetyFour\GiftVouchers;

use Statamic\Contracts\Assets\Asset;
use Statamic\Facades\Addon;
use Statamic\Facades\Site;
use Statamic\Fields\Value;
use Throwable;

/**
 * The wording and look of vouchers and their emails, edited in the Control
 * Panel (Tools → Addons → Gift Vouchers → Settings). Placeholders are
 * written :like_this, as Statamic reads settings through Antlers.
 */
class Settings
{
    public const ADDON = '1994/gift-vouchers-statamic';

    public static function get(string $key, ?string $default = null): ?string
    {
        try {
            $value = Addon::get(self::ADDON)?->settings()->get($key);
        } catch (Throwable) {
            $value = null;
        }

        $value = is_string($value) ? trim($value) : null;

        return $value === '' || $value === null ? $default : $value;
    }

    /**
     * A setting with its placeholders filled in (and nothing else read from them).
     *
     * @param  array<string, string|null>  $replace
     */
    public static function text(string $key, array $replace = []): string
    {
        $text = self::get($key) ?? (string) __('gift-vouchers::messages.defaults.'.$key);

        $replace += ['site' => Site::current()?->name() ?? config('app.name')];

        // Longest first, so :sender_name isn't read as :sender.
        uksort($replace, fn ($a, $b) => strlen($b) <=> strlen($a));

        foreach ($replace as $key => $value) {
            $text = str_replace(':'.$key, (string) $value, $text);
        }

        return $text;
    }

    /**
     * The brand colour, as a CSS hex colour.
     */
    public static function colour(): string
    {
        return self::validColour(self::get('accent_colour')) ?? '#1f2937';
    }

    /**
     * A CSS hex colour, or null if it isn't one (so it can't break out of CSS).
     */
    public static function validColour(mixed $colour): ?string
    {
        return is_string($colour) && preg_match('/^#(?:[0-9a-fA-F]{3}){1,2}$/', $colour) ? $colour : null;
    }

    /**
     * Dark or white text, whichever reads better on the brand colour.
     */
    public static function onColour(): string
    {
        return self::contrastFor(self::colour());
    }

    /**
     * Dark or white text, whichever has more contrast with a colour.
     */
    public static function contrastFor(string $colour): string
    {
        $hex = ltrim(self::validColour($colour) ?? '#1f2937', '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        [$r, $g, $b] = array_map(function (string $pair) {
            $c = hexdec($pair) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, str_split($hex, 2));

        $luminance = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;

        // Whichever of white and near-black has the higher contrast ratio.
        return (1.05 / ($luminance + 0.05)) >= (($luminance + 0.05) / 0.0607) ? '#ffffff' : '#111827';
    }

    public static function logo(): ?Asset
    {
        try {
            $addon = Addon::get(self::ADDON);
            $value = $addon?->settings()->raw()['logo'] ?? null;

            if (! $value || ! $field = $addon->settingsBlueprint()?->field('logo')) {
                return null;
            }

            // The settings' own field knows which asset container it uses.
            $asset = $field->setValue($value)->augment()->value();
            $asset = $asset instanceof Value ? $asset->value() : $asset;
            $asset = is_iterable($asset) ? collect($asset)->first() : $asset;

            return $asset instanceof Asset ? $asset : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The logo as a data: URI, for PDFs (which don't fetch anything from the web).
     */
    public static function logoDataUri(): ?string
    {
        $logo = self::logo();

        if (! $logo || ! $logo->isImage() || $logo->size() > 2_000_000) {
            return null;
        }

        try {
            return 'data:'.$logo->mimeType().';base64,'.base64_encode($logo->contents());
        } catch (Throwable) {
            return null;
        }
    }
}
