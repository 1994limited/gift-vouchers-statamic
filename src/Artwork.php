<?php

namespace NineteenNinetyFour\GiftVouchers;

/**
 * The engraved look of a voucher, drawn as SVG: guilloché rosettes (the
 * fine looping patterns on banknotes and share certificates), a border of
 * interlaced waves, corner rosettes and a ground of fine lines.
 *
 * Everything is worked out from a seed (the voucher's ID), so each voucher's
 * pattern is a little different, and the same voucher always looks the same.
 * Lines are drawn in one colour at different strengths, so it suits any
 * brand colour and prints well. Plain SVG paths only, so Dompdf draws it too.
 */
class Artwork
{
    /** @var array<int, int> */
    protected array $numbers = [];

    protected int $next = 0;

    /** Motifs that can sit at the heart of the rosette and in the corners. */
    public const MOTIFS = ['heart', 'star', 'flower', 'cake', 'gift'];

    public function __construct(protected string $seed, protected string $colour = '#1f2937', protected ?string $motif = null)
    {
        $this->motif = in_array($motif, self::MOTIFS, true) ? $motif : null;
        $this->numbers = array_values(unpack('C*', hash('sha256', $seed, true)));
    }

    /**
     * The background of a voucher card: frame, wave border, corner rosettes,
     * fine ground and a large rosette at ($rosetteX, $rosetteY) as fractions.
     */
    public function card(int $width = 1000, int $height = 460, float $rosetteX = 0.83, float $rosetteY = 0.5): string
    {
        $c = $this->colour;
        $inset = 18;

        $parts = [
            $this->ground($width, $height),
            sprintf('<rect x="%d" y="%d" width="%d" height="%d" fill="none" stroke="%s" stroke-width="1.6"/>', $inset, $inset, $width - $inset * 2, $height - $inset * 2, $c),
            sprintf('<rect x="%d" y="%d" width="%d" height="%d" fill="none" stroke="%s" stroke-width="0.6"/>', $inset + 5, $inset + 5, $width - ($inset + 5) * 2, $height - ($inset + 5) * 2, $c),
            $this->waveBorder($width, $height, $inset + 10, 14),
            sprintf('<rect x="%d" y="%d" width="%d" height="%d" fill="none" stroke="%s" stroke-width="0.6"/>', $inset + 29, $inset + 29, $width - ($inset + 29) * 2, $height - ($inset + 29) * 2, $c),
        ];

        foreach ([[$inset + 17, $inset + 17], [$width - $inset - 17, $inset + 17], [$inset + 17, $height - $inset - 17], [$width - $inset - 17, $height - $inset - 17]] as [$x, $y]) {
            $parts[] = sprintf('<circle cx="%d" cy="%d" r="14" fill="#fffdf8" stroke="%s" stroke-width="0.6"/>', $x, $y, $c);
            $parts[] = $this->motif ? $this->motifAt($x, $y, 9) : $this->rosette($x, $y, 11, layers: 1, density: 0.3);
        }

        $radius = min($height * 0.33, $width * 0.15);
        $parts[] = $this->rosette($width * $rosetteX, $height * $rosetteY, $radius, layers: 5);

        if ($this->motif) {
            $parts[] = sprintf('<circle cx="%s" cy="%s" r="%s" fill="#fffdf8" stroke="%s" stroke-width="1"/>', $this->n($width * $rosetteX), $this->n($height * $rosetteY), $this->n($radius * 0.36), $c);
            $parts[] = sprintf('<circle cx="%s" cy="%s" r="%s" fill="none" stroke="%s" stroke-width="0.5"/>', $this->n($width * $rosetteX), $this->n($height * $rosetteY), $this->n($radius * 0.32), $c);
            $parts[] = $this->motifAt($width * $rosetteX, $height * $rosetteY, $radius * 0.24);
        }

        return $this->svg($width, $height, implode('', $parts));
    }

    /**
     * Just the double frame, for plain designs' previews.
     */
    public function frame(int $width = 1000, int $height = 460): string
    {
        return $this->svg($width, $height, sprintf(
            '<rect width="%1$d" height="%2$d" fill="#fffdf8"/><rect x="18" y="18" width="%3$d" height="%4$d" fill="none" stroke="%5$s" stroke-width="2"/>',
            $width, $height, $width - 36, $height - 36, $this->colour
        ));
    }

    /**
     * A rosette on its own, for a site's own pages ({{ gift_vouchers:artwork }}).
     */
    public function emblem(int $size = 240): string
    {
        $content = $this->rosette($size / 2, $size / 2, $size * 0.47, layers: 5);

        if ($this->motif) {
            $content .= sprintf('<circle cx="%1$s" cy="%1$s" r="%2$s" fill="#fffdf8" stroke="%3$s"/>', $this->n($size / 2), $this->n($size * 0.17), $this->colour);
            $content .= $this->motifAt($size / 2, $size / 2, $size * 0.11);
        }

        return $this->svg($size, $size, $content);
    }

    /**
     * A motif drawn as engraving: an outline, a finer outline inside it,
     * and hatching, centred on ($cx, $cy) and $size from the centre to the edge.
     */
    public function motifAt(float $cx, float $cy, float $size): string
    {
        $outline = $this->motifPath($size);
        $inner = $this->motifPath($size * 0.8);

        return sprintf(
            '<g transform="translate(%s %s)" fill="none" stroke="%s" stroke-linejoin="round" stroke-linecap="round">'.
            '<path d="%s" stroke-width="%s"/><path d="%s" stroke-width="%s" stroke-opacity="0.6"/></g>',
            $this->n($cx), $this->n($cy), $this->colour, $outline, $this->n(max(0.6, $size / 18)), $inner, $this->n(max(0.35, $size / 40))
        );
    }

    /**
     * A motif's outline as path data, centred on 0,0.
     */
    protected function motifPath(float $s): string
    {
        $p = fn (float $x, float $y) => $this->n($x * $s).' '.$this->n($y * $s);

        return match ($this->motif) {
            'heart' => 'M'.$p(0, 0.9).'C'.$p(-1.1, 0.1).' '.$p(-1, -0.95).' '.$p(-0.48, -0.95).'C'.$p(-0.2, -0.95).' '.$p(0, -0.72).' '.$p(0, -0.55)
                .'C'.$p(0, -0.72).' '.$p(0.2, -0.95).' '.$p(0.48, -0.95).'C'.$p(1, -0.95).' '.$p(1.1, 0.1).' '.$p(0, 0.9).'Z',
            'star' => 'M'.collect(range(0, 9))->map(function (int $i) use ($p) {
                $angle = -M_PI / 2 + $i * M_PI / 5;
                $r = $i % 2 ? 0.42 : 1;

                return $p($r * cos($angle), $r * sin($angle));
            })->implode('L').'Z',
            'flower' => collect(range(0, 5))->map(function (int $i) use ($p, $s) {
                $angle = $i * M_PI / 3;
                [$x, $y] = [0.52 * cos($angle), 0.52 * sin($angle)];

                return 'M'.$p($x + 0.42, $y).'A'.$this->n(0.42 * $s).' '.$this->n(0.42 * $s).' 0 1 0 '.$p($x - 0.42, $y).'A'.$this->n(0.42 * $s).' '.$this->n(0.42 * $s).' 0 1 0 '.$p($x + 0.42, $y);
            })->implode('').'M'.$p(0.22, 0).'A'.$this->n(0.22 * $s).' '.$this->n(0.22 * $s).' 0 1 0 '.$p(-0.22, 0).'A'.$this->n(0.22 * $s).' '.$this->n(0.22 * $s).' 0 1 0 '.$p(0.22, 0),
            'cake' => 'M'.$p(-0.85, 0.9).'H'.$this->n(0.85 * $s).'V'.$this->n(0.15 * $s).'H'.$this->n(-0.85 * $s).'Z'
                .'M'.$p(-0.85, 0.42).'Q'.$p(-0.57, 0.62).' '.$p(-0.28, 0.42).'Q'.$p(0, 0.62).' '.$p(0.28, 0.42).'Q'.$p(0.57, 0.62).' '.$p(0.85, 0.42)
                .'M'.$p(-0.6, 0.15).'V'.$this->n(-0.25 * $s).'H'.$this->n(0.6 * $s).'V'.$this->n(0.15 * $s)
                .collect([-0.35, 0, 0.35])->map(fn ($x) => 'M'.$p($x, -0.25).'V'.$this->n(-0.62 * $s).'M'.$p($x, -0.72).'Q'.$p($x + 0.1, -0.85).' '.$p($x, -1).'Q'.$p($x - 0.1, -0.85).' '.$p($x, -0.72))->implode(''),
            'gift' => 'M'.$p(-0.8, -0.2).'H'.$this->n(0.8 * $s).'V'.$this->n(0.9 * $s).'H'.$this->n(-0.8 * $s).'Z'
                .'M'.$p(-0.95, -0.5).'H'.$this->n(0.95 * $s).'V'.$this->n(-0.2 * $s).'H'.$this->n(-0.95 * $s).'Z'
                .'M'.$p(0, -0.5).'V'.$this->n(0.9 * $s)
                .'M'.$p(0, -0.5).'C'.$p(-0.2, -1).' '.$p(-0.75, -0.95).' '.$p(-0.55, -0.65).'C'.$p(-0.45, -0.52).' '.$p(-0.2, -0.5).' '.$p(0, -0.5)
                .'M'.$p(0, -0.5).'C'.$p(0.2, -1).' '.$p(0.75, -0.95).' '.$p(0.55, -0.65).'C'.$p(0.45, -0.52).' '.$p(0.2, -0.5).' '.$p(0, -0.5),
            default => '',
        };
    }

    /**
     * As a data: URI, for an <img> (and for Dompdf, which won't fetch anything).
     */
    public static function dataUri(string $svg): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * Layers of smooth closed curves (each a ring of petals), every one drawn
     * many times, turned a fraction of a petal each time: the dense woven
     * loops of engine-turned engraving. Each curve is drawn once and reused
     * with <use>, which keeps the SVG small.
     */
    public function rosette(float $cx, float $cy, float $radius, int $layers = 4, float $density = 1.0): string
    {
        $id = 'r'.substr(hash('crc32b', $this->seed.$cx.$cy.$radius), 0, 6);
        $defs = '';
        $uses = '';
        $petals = 5 + $this->pick(0, 7);        // 5 to 12 petals
        $steps = (int) max(180, 480 * $density);

        for ($layer = 0; $layer < $layers; $layer++) {
            $outer = $radius * (1 - $layer * 0.19);
            $depth = $outer * (0.22 + $this->pick(0, 4) * 0.03);
            $lobes = $petals + $layer * $this->pick(0, 2);
            $twist = $this->pick(0, 1) ? 2 : 0;   // some layers lean, like a turned shell
            $base = $outer - $depth;

            $d = '';
            for ($i = 0; $i <= $steps; $i++) {
                $t = 2 * M_PI * $i / $steps;
                $r = $base + $depth * (0.5 + 0.5 * sin($lobes * $t + $twist * sin($t * $lobes) * 0.35));
                $d .= ($i === 0 ? 'M' : 'L').$this->n($r * cos($t)).' '.$this->n($r * sin($t));
            }

            // Opacity on the path itself: Dompdf ignores it on <use>.
            $opacity = $this->n(0.75 - $layer * 0.12);
            $defs .= sprintf('<path id="%s-%d" d="%sZ" stroke-opacity="%s"/>', $id, $layer, $d, $opacity);

            $copies = (int) max(3, round((14 - $layer * 2) * $density));

            for ($copy = 0; $copy < $copies; $copy++) {
                $angle = $this->n(360 / $lobes * $copy / $copies);
                $uses .= sprintf('<use xlink:href="#%1$s-%2$d" href="#%1$s-%2$d" transform="translate(%3$s %4$s) rotate(%5$s)"/>', $id, $layer, $this->n($cx), $this->n($cy), $angle);
            }
        }

        return sprintf(
            '<defs>%s</defs><g fill="none" stroke="%s" stroke-width="%s">%s</g><circle cx="%s" cy="%s" r="%s" fill="none" stroke="%s" stroke-width="0.8"/>',
            $defs, $this->colour, $this->n(max(0.3, 0.5 * $density)), $uses, $this->n($cx), $this->n($cy), $this->n($radius * 0.16), $this->colour
        );
    }

    /**
     * Interlaced sine waves running round the card between two frames.
     */
    protected function waveBorder(int $width, int $height, int $inset, int $band): string
    {
        $paths = '';
        $period = 18 + $this->pick(0, 4) * 2;
        $amp = $band / 2 - 1;

        foreach ([0, M_PI / 2, M_PI, 3 * M_PI / 2] as $k => $phase) {
            $opacity = $k % 2 ? 0.55 : 0.9;

            // Top and bottom.
            foreach ([$inset + $band / 2, $height - $inset - $band / 2] as $y) {
                $d = '';
                for ($x = $inset; $x <= $width - $inset; $x += 2) {
                    $d .= ($x === $inset ? 'M' : 'L').$this->n($x).' '.$this->n($y + $amp * sin(2 * M_PI * $x / $period + $phase));
                }
                $paths .= sprintf('<path d="%s" fill="none" stroke="%s" stroke-width="0.5" stroke-opacity="%s"/>', $d, $this->colour, $opacity);
            }

            // Left and right.
            foreach ([$inset + $band / 2, $width - $inset - $band / 2] as $x) {
                $d = '';
                for ($y = $inset + $band; $y <= $height - $inset - $band; $y += 2) {
                    $d .= ($y === $inset + $band ? 'M' : 'L').$this->n($x + $amp * sin(2 * M_PI * $y / $period + $phase)).' '.$this->n($y);
                }
                $paths .= sprintf('<path d="%s" fill="none" stroke="%s" stroke-width="0.5" stroke-opacity="%s"/>', $d, $this->colour, $opacity);
            }
        }

        return '<g>'.$paths.'</g>';
    }

    /**
     * Very fine, faint wavy lines across the whole card.
     */
    protected function ground(int $width, int $height): string
    {
        $period = 120 + $this->pick(0, 5) * 20;
        $id = 'g'.substr(hash('crc32b', $this->seed.$width.$height), 0, 6);

        // One wave, long enough to be shifted along, reused for every line.
        $d = '';
        for ($x = -$period; $x <= $width + $period; $x += 8) {
            $d .= ($x === -$period ? 'M' : 'L').$x.' '.$this->n(2.2 * sin(2 * M_PI * $x / $period));
        }

        $uses = '';
        for ($y = 10; $y < $height; $y += 7) {
            $uses .= sprintf('<use xlink:href="#%1$s" href="#%1$s" transform="translate(%2$s %3$d)"/>', $id, $this->n(-fmod($y * 5.3, $period)), $y);
        }

        return sprintf('<defs><path id="%s" d="%s"/></defs><g fill="none" stroke="%s" stroke-width="0.35" stroke-opacity="0.16">%s</g>', $id, $d, $this->colour, $uses);
    }

    protected function svg(float $width, float $height, string $content): string
    {
        return sprintf('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 %s %s" width="%s" height="%s">%s</svg>', $this->n($width), $this->n($height), $this->n($width), $this->n($height), $content);
    }

    /**
     * The next number from the seed, from $min to $max.
     */
    protected function pick(int $min, int $max): int
    {
        $byte = $this->numbers[$this->next++ % count($this->numbers)];

        return $min + $byte % ($max - $min + 1);
    }

    protected function n(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
