<?php

use Z77\Module\Dms\Services\DocumentService;

if (!function_exists('mediaUrl')) {
    /**
     * Public `/media` URL for a DMS-managed document addressed by its structural slug
     * path: the partition-root slug, the folder slugs, then `<doc-slug>.<ext>`
     * (e.g. `'front/imgs/logo.png'`). Global template convenience — defined ONLY when
     * module-dms is installed (composer `autoload.files`), so `kernel/core` stays free
     * of DMS knowledge.
     *
     *   <img src="<?= e(mediaUrl('front/imgs/logo.png')) ?>" alt="…">
     *   mediaUrl('front/imgs/logo.png', 'l')   // image variant l
     *
     * Returns null when the path resolves to nothing (document not uploaded / wrong
     * slug / ambiguous) — guard it in the template. The document must be delivered as
     * `public`; this only builds the URL (with the `?v=<checksum>` cache token), it does
     * NOT gate delivery. The `DocumentService` is built once per request.
     */
    function mediaUrl(string $path, ?string $variant = null): ?string
    {
        static $svc = null;
        $svc ??= DocumentService::create();

        return $svc->urlForPath($path, $variant);
    }
}

if (!function_exists('mediaImage')) {
    /**
     * Full image bundle for a DMS-managed document addressed by its structural slug path:
     * the public URL plus the localized `alt` / `caption` (current request language, i18n
     * default fallback) and intrinsic pixel dimensions. Companion to {@see mediaUrl()} — use
     * it when a template renders `<figure><img …><figcaption></figure>`.
     *
     *   <?php if ($img = mediaImage('front/imgs/logo.png')): ?>
     *   <img src="<?= e($img['url']) ?>" alt="<?= e($img['alt']) ?>"
     *        <?= $img['width'] ? 'width="' . e((string) $img['width']) . '"' : '' ?>>
     *   <?php if ($img['caption'] !== ''): ?><figcaption><?= e($img['caption']) ?></figcaption><?php endif; ?>
     *   <?php endif; ?>
     *
     * Returns null when the path resolves to nothing — guard it. Still `e()`-escape every value.
     *
     * @return array{url: string, alt: string, caption: string, width: ?int, height: ?int}|null
     */
    function mediaImage(string $path, ?string $variant = null): ?array
    {
        static $svc = null;
        $svc ??= DocumentService::create();

        return $svc->imageForPath($path, $variant);
    }
}
