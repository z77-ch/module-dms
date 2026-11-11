<?php

namespace Z77\Module\Dms\Content\Renderer;

use Z77\Shared\Content\InlineMarkdown;

/**
 * A grid of DMS images.
 * { "type": "media-gallery", "items": [ { "documentId": 42, "alt": "…", "caption": "…"? }, … ] }
 * → <div class="media-gallery"> of <figure class="media-gallery__item">. An item whose
 * document is missing, deleted, not an image or not publicly delivered is skipped; with
 * no item left the block renders '' (see {@see MediaBlockRenderer}). Order = list order.
 */
final class MediaGalleryRenderer extends MediaBlockRenderer
{
    /** One to three columns (_media.scss: min 14rem per tile). */
    private const SIZES = '(max-width: 600px) 100vw, (max-width: 1024px) 50vw, 33vw';

    public function type(): string
    {
        return 'media-gallery';
    }

    public function schema(): array
    {
        return [
            ['key' => 'items', 'kind' => 'list', 'label' => 'Bilder', 'min' => 1, 'item' => [
                self::documentField('Bild'),
                ['key' => 'alt', 'kind' => 'text', 'label' => 'Alt-Text (beschreibt das Bild)', 'required' => true, 'maxLength' => 250],
                ['key' => 'caption', 'kind' => 'text', 'label' => 'Bildunterschrift (optional)', 'maxLength' => 300],
            ]],
        ];
    }

    public function render(array $block, InlineMarkdown $inline): string
    {
        $items = '';
        foreach (is_array($block['items'] ?? null) ? $block['items'] : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $img = $this->img(
                $item['documentId'] ?? null,
                (string)($item['alt'] ?? ''),
                self::SIZES,
                480,
                'media-gallery__img',
            );
            if ($img === '') {
                continue;
            }
            $caption = trim((string)($item['caption'] ?? ''));
            $items  .= '<figure class="media-gallery__item">' . $img
                . ($caption !== '' ? '<figcaption class="media-gallery__caption">' . $this->attr($caption) . '</figcaption>' : '')
                . '</figure>';
        }

        return $items === '' ? '' : '<div class="media-gallery">' . $items . '</div>';
    }
}
