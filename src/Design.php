<?php

namespace NineteenNinetyFour\GiftVouchers;

use Statamic\Contracts\Assets\Asset;
use Throwable;

/**
 * How a voucher looks: an engraved pattern, the shop's own image, or plain,
 * in a colour. Shops set one in the addon's settings, or several for
 * shoppers to choose from (Birthday, Christmas, Thank you…).
 */
class Design
{
    public const ENGRAVED = 'engraved';

    public const IMAGE = 'image';

    public const PLAIN = 'plain';

    /** The largest image put into a PDF. */
    public const MAX_IMAGE_BYTES = 5_000_000;

    public function __construct(
        public readonly string $handle,
        public readonly string $name,
        public readonly string $style = self::ENGRAVED,
        public readonly string $colour = '#1f2937',
        public readonly ?Asset $image = null,
        public readonly ?string $title = null,
        public readonly ?string $motif = null,
    ) {}

    /**
     * What's drawn behind the card: 'engraved', 'image', or null for plain.
     * An image design without a usable image is drawn engraved.
     */
    public function kind(): ?string
    {
        return match (true) {
            $this->style === self::PLAIN => null,
            $this->style === self::IMAGE && $this->usableImage() => self::IMAGE,
            default => self::ENGRAVED,
        };
    }

    /**
     * The artwork for a voucher's card, as a data: URI (Dompdf fetches
     * nothing, and it works the same on the printable page).
     */
    public function artwork(string $seed): ?string
    {
        return match ($this->kind()) {
            self::IMAGE => $this->imageDataUri(),
            self::ENGRAVED => Artwork::dataUri($this->artworkFor($seed)->card()),
            default => null,
        };
    }

    /**
     * A small picture of the design, for choosing between them.
     */
    public function preview(): string
    {
        if ($this->kind() === self::IMAGE) {
            return (string) $this->image->absoluteUrl();
        }

        $artwork = $this->artworkFor($this->handle);

        // The rosette with its motif: the card is too wide to read when small.
        return Artwork::dataUri($this->kind() === self::ENGRAVED ? $artwork->emblem(240) : $artwork->frame(240, 240));
    }

    /**
     * A link to the design's card for the live preview. The version changes
     * with the design, so a browser never shows an old one from its cache.
     */
    public function previewArtworkUrl(): ?string
    {
        if ($this->kind() === null) {
            return null;
        }

        return route('statamic.gift-vouchers.design.artwork', [
            'design' => $this->handle,
            'v' => substr(sha1(json_encode([$this->style, $this->colour, $this->motif, $this->image?->id(), $this->image?->lastModified()?->timestamp])), 0, 8),
        ]);
    }

    /**
     * The engraved artwork for this design, from a seed.
     */
    public function artworkFor(string $seed): Artwork
    {
        return app(Artwork::class, ['seed' => $seed, 'colour' => $this->colour, 'motif' => $this->motif]);
    }

    /**
     * The design's emblem (its rosette and motif) as a small PNG, for emails,
     * which can't show SVG. Needs PHP's Imagick extension; null without it.
     * Kept in the cache, as drawing it takes a moment.
     */
    public function emblemPng(): ?string
    {
        if ($this->kind() !== self::ENGRAVED || ! extension_loaded('imagick')) {
            return null;
        }

        $key = 'gift-vouchers:emblem:'.sha1(json_encode([$this->handle, $this->colour, $this->motif]));

        $png = rescue(fn () => cache()->rememberForever($key, function () {
            $image = new \Imagick;
            $image->setBackgroundColor(new \ImagickPixel('transparent'));
            $image->setResolution(144, 144);
            $image->readImageBlob($this->artworkFor($this->handle)->emblem(240));
            $image->quantizeImage(32, \Imagick::COLORSPACE_RGB, 0, false, false);
            $image->setImageFormat('png8');
            $image->stripImage();

            return base64_encode($image->getImageBlob());
        }), null, false);

        return is_string($png) ? base64_decode($png) : null;
    }

    /**
     * Dark or white text, whichever reads better on the colour.
     */
    public function onColour(): string
    {
        return Settings::contrastFor($this->colour);
    }

    protected function usableImage(): bool
    {
        return $this->image !== null && $this->image->isImage() && $this->image->size() <= self::MAX_IMAGE_BYTES;
    }

    protected function imageDataUri(): ?string
    {
        try {
            return 'data:'.$this->image->mimeType().';base64,'.base64_encode($this->image->contents());
        } catch (Throwable) {
            return null;
        }
    }
}
