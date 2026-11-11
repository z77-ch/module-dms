<?php

namespace Z77\Module\Dms\Content\Renderer;

use Z77\Shared\Content\InlineMarkdown;

/**
 * One image from the DMS.
 * { "type": "media-image", "documentId": 42, "alt": "…", "caption": "…"?, "link": "/…"? }
 * → <figure class="media-image"> with a responsive <img> (optionally inside a link) and a
 * <figcaption>. A document that is missing, deleted, not an image or not publicly
 * delivered renders '' (see {@see MediaBlockRenderer}). Alt text is required: the
 * customer is the author and the page is public (bauplan decision 7).
 */
final class MediaImageRenderer extends MediaBlockRenderer
{
    /** One image across the content column: the full viewport on small screens. */
    private const SIZES = '(max-width: 1024px) 100vw, 1024px';

    public function type(): string
    {
        return 'media-image';
    }

    public function schema(): array
    {
        return [
            self::documentField('Bild'),
            ['key' => 'alt', 'kind' => 'text', 'label' => 'Alt-Text (beschreibt das Bild)', 'required' => true, 'maxLength' => 250],
            ['key' => 'caption', 'kind' => 'text', 'label' => 'Bildunterschrift (optional)', 'maxLength' => 300],
            ['key' => 'link', 'kind' => 'url', 'label' => 'Link (optional, z. B. /contact)', 'maxLength' => 500],
        ];
    }

    public function render(array $block, InlineMarkdown $inline): string
    {
        $img = $this->img(
            $block['documentId'] ?? null,
            (string)($block['alt'] ?? ''),
            self::SIZES,
            1024,
            'media-image__img',
        );
        if ($img === '') {
            return '';
        }

        $href = $this->safeHref((string)($block['link'] ?? ''));
        if ($href !== '') {
            $img = '<a class="media-image__link" href="' . $href . '">' . $img . '</a>';
        }

        $caption = trim((string)($block['caption'] ?? ''));
        $caption = $caption !== '' ? '<figcaption class="media-image__caption">' . $this->attr($caption) . '</figcaption>' : '';

        return '<figure class="media-image">' . $img . $caption . '</figure>';
    }
}
