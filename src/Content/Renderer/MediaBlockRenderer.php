<?php

namespace Z77\Module\Dms\Content\Renderer;

use Z77\Module\Dms\Entities\Document;
use Z77\Module\Dms\Images\DocumentKind;
use Z77\Module\Dms\Services\DocumentService;
use Z77\Shared\Content\BlockRenderer;
use Z77\Shared\Content\BlockSchemaValidator;

/**
 * Base of the content blocks that show DMS images (`media-image`, `media-gallery`,
 * media-picker bauplan P2). A block stores the document by `id` (rename/move-safe), never
 * by a `/media` URL; the URL is built at render time through {@see DocumentService::publicUrl()},
 * so it carries the version token and follows a rename.
 *
 * What renders: an image document that `/media` hands to anybody
 * ({@see DocumentService::isPubliclyDelivered()}: not deleted, effectively `public`, active
 * chain). Anything else — no id, an unknown or deleted document, not an image, a protected
 * or inactive one — renders NOTHING: a public page never points at a URL that answers 404.
 *
 * The block lives here and not in `Z77\Shared`: `shared → module-dms` is a forbidden
 * coupling (content.md).
 */
abstract class MediaBlockRenderer implements BlockRenderer
{
    /** The picker scope a `document` field of these blocks asks for (bauplan decision 3). */
    protected const ACCEPT = 'image';

    /** The proportional size ladder of every image (ImageProfileRegistry `admin`). */
    private const LADDER = ['s', 'm', 'l', 'xl'];

    /** Below this width the variants are no content image: the original joins the srcset. */
    private const CONTENT_WIDTH = 1024;

    /**
     * Renderers are stateless and built with no arguments (BlockRegistry::assemble()), and
     * DocumentService is no DI service (built on demand, decision B) — so the service is
     * built once per request on first use, like the `mediaUrl()` helper does it.
     */
    private static ?DocumentService $documents = null;

    protected static function documents(): DocumentService
    {
        return self::$documents ??= DocumentService::create();
    }

    /** The `document` field descriptor of these blocks. */
    protected static function documentField(string $label): array
    {
        return ['key' => 'documentId', 'kind' => 'document', 'label' => $label, 'required' => true, 'accept' => self::ACCEPT];
    }

    /**
     * `<img>` of a DMS image, or '' when the id does not name a publicly delivered image.
     * `srcset` over the variants of the same proportions (the `s/m/l/xl` ladder and a
     * project profile's width-only sizes; a crop never), `sizes` from the caller,
     * `width`/`height` of the `src` variant (reserves the box, no layout shift), lazy.
     *
     * @param int $target the width the plain `src` should have (a browser without srcset)
     */
    protected function img(mixed $documentId, string $alt, string $sizes, int $target, string $class): string
    {
        $id = BlockSchemaValidator::documentId($documentId);
        if ($id === null) {
            return '';
        }
        $docs = self::documents();
        $doc  = $docs->get($id);
        if ($doc === null || $doc->getKind() !== DocumentKind::Image->value || !$docs->isPubliclyDelivered($doc)) {
            return '';
        }

        $candidates = $this->candidates($docs, $doc);
        if ($candidates === []) {
            // No measured size (an SVG, a legacy record): the original alone.
            $url = $docs->publicUrl($doc);
            if ($url === null) {
                return '';
            }
            return '<img class="' . $class . '" src="' . $this->attr($url) . '"'
                . $this->dimensions($doc->getWidth(), $doc->getHeight())
                . ' alt="' . $this->attr($alt) . '" loading="lazy" decoding="async">';
        }

        $src = end($candidates);
        foreach ($candidates as $candidate) {
            if ($candidate['w'] >= $target) {
                $src = $candidate;
                break;
            }
        }
        $srcset = implode(', ', array_map(
            fn(array $c): string => $this->attr($c['url']) . ' ' . $c['w'] . 'w',
            $candidates
        ));

        return '<img class="' . $class . '" src="' . $this->attr($src['url']) . '"'
            . ' srcset="' . $srcset . '" sizes="' . $this->attr($sizes) . '"'
            . $this->dimensions($src['w'], $src['h'])
            . ' alt="' . $this->attr($alt) . '" loading="lazy" decoding="async">';
    }

    /**
     * The srcset candidates, ascending by width, one per width: every variant with the
     * original's proportions — a `cover` crop of a project profile would show another
     * picture at another width. When those stop short of a content image while the
     * original is larger (original preserved: only the thumbnail exists), the original
     * joins them.
     *
     * @return list<array{url: string, w: int, h: int}>
     */
    private function candidates(DocumentService $docs, Document $doc): array
    {
        $width  = (int) $doc->getWidth();
        $height = (int) $doc->getHeight();
        $byWidth = [];

        foreach ($doc->getVariants() as $name => $meta) {
            $w = is_array($meta) ? (int) ($meta['w'] ?? 0) : 0;
            $h = is_array($meta) ? (int) ($meta['h'] ?? 0) : 0;
            if ($w <= 0 || $h <= 0 || isset($byWidth[$w])) {
                continue;
            }
            $sameShape = $width > 0 && $height > 0
                ? abs($w / $h - $width / $height) <= 0.02 * ($width / $height)
                : in_array((string) $name, self::LADDER, true);
            if (!$sameShape) {
                continue;
            }
            $url = $docs->publicUrl($doc, (string) $name);
            if ($url !== null) {
                $byWidth[$w] = ['url' => $url, 'w' => $w, 'h' => $h];
            }
        }

        $largest = $byWidth === [] ? 0 : max(array_keys($byWidth));
        if ($byWidth !== [] && $largest < self::CONTENT_WIDTH && $width > $largest && $height > 0) {
            $url = $docs->publicUrl($doc);
            if ($url !== null) {
                $byWidth[$width] = ['url' => $url, 'w' => $width, 'h' => $height];
            }
        }

        ksort($byWidth);
        return array_values($byWidth);
    }

    private function dimensions(?int $width, ?int $height): string
    {
        return ($width ?? 0) > 0 && ($height ?? 0) > 0
            ? ' width="' . $width . '" height="' . $height . '"'
            : '';
    }

    /**
     * A link target: site-relative (not `//`), `#`, http(s), mailto — the gate of a
     * `kind: url` field (BlockSchemaValidator), applied again at render. '' = no link.
     */
    protected function safeHref(string $href): string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '//')) {
            return '';
        }
        if ($href[0] === '/' || $href[0] === '#' || preg_match('~^(https?://|mailto:)~i', $href) === 1) {
            return $this->attr($href);
        }

        return '';
    }

    protected function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
