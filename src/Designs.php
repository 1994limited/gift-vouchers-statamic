<?php

namespace NineteenNinetyFour\GiftVouchers;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Statamic\Contracts\Assets\Asset;
use Statamic\Facades\Addon;
use Throwable;

/**
 * The voucher designs set in the addon's settings. With none set, there's
 * one: the Voucher tab's style and colour.
 */
class Designs
{
    /** @var Collection<string, Design>|null */
    protected ?Collection $designs = null;

    /**
     * @return Collection<string, Design> Keyed by handle, in the order they were set.
     */
    public function all(): Collection
    {
        return $this->designs ??= $this->load();
    }

    /**
     * Whether shoppers have a choice of design.
     */
    public function hasChoice(): bool
    {
        return $this->all()->count() > 1;
    }

    /**
     * A design by its handle, or the first one.
     */
    public function find(?string $handle): Design
    {
        return ($handle !== null ? $this->all()->get($handle) : null) ?? $this->all()->first();
    }

    public function exists(?string $handle): bool
    {
        return $handle !== null && $this->all()->has($handle);
    }

    /**
     * Read them again (after the settings change).
     */
    public function forget(): void
    {
        $this->designs = null;
    }

    /**
     * @return Collection<string, Design>
     */
    protected function load(): Collection
    {
        $designs = collect($this->rows())
            ->filter(fn ($row) => is_array($row) && filled($row['name'] ?? null))
            ->mapWithKeys(function (array $row, int $index) {
                $handle = Str::slug((string) $row['name']) ?: 'design-'.($index + 1);

                return [$handle => new Design(
                    handle: $handle,
                    name: (string) $row['name'],
                    style: in_array($row['style'] ?? null, [Design::ENGRAVED, Design::IMAGE, Design::PLAIN], true) ? $row['style'] : Design::ENGRAVED,
                    colour: Settings::validColour($row['colour'] ?? null) ?? Settings::colour(),
                    image: $row['image'] ?? null,
                    title: filled($row['title'] ?? null) ? mb_substr(trim((string) $row['title']), 0, 60) : null,
                    motif: in_array($row['motif'] ?? null, Artwork::MOTIFS, true) ? $row['motif'] : null,
                )];
            });

        if ($designs->isNotEmpty()) {
            return $designs;
        }

        $style = Settings::get('style', Design::ENGRAVED);

        return collect(['default' => new Design(
            handle: 'default',
            name: (string) __('gift-vouchers::messages.cp.default_design'),
            style: in_array($style, [Design::ENGRAVED, Design::PLAIN], true) ? $style : Design::ENGRAVED,
            colour: Settings::colour(),
        )]);
    }

    /**
     * The designs grid from the settings, as saved, with each row's image
     * found. Rows are read as they are, so one bad image (or a site with no
     * asset container) can't lose the other designs.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function rows(): array
    {
        try {
            $addon = Addon::get(Settings::ADDON);
            $raw = $addon?->settings()->raw()['designs'] ?? [];
        } catch (Throwable) {
            return [];
        }

        if (! is_array($raw)) {
            return [];
        }

        $imageField = rescue(fn () => $addon->settingsBlueprint()?->field('designs')?->fieldtype()->fields()->get('image'), null, false);

        return collect($raw)
            ->filter(fn ($row) => is_array($row))
            ->map(fn (array $row) => array_merge($row, ['image' => $this->image($imageField, $row['image'] ?? null)]))
            ->values()
            ->all();
    }

    protected function image(mixed $field, mixed $value): ?Asset
    {
        if (! $field || empty($value)) {
            return null;
        }

        return rescue(function () use ($field, $value) {
            $asset = $field->setValue($value)->augment()->value();
            $asset = is_iterable($asset) ? collect($asset)->first() : $asset;

            return $asset instanceof Asset ? $asset : null;
        }, null, false);
    }
}
