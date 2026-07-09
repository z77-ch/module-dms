<?php
namespace Z77\Module\Dms\Ui\Controllers\Media;

use Z77\Core\Controller\AbstractBaseController,
    Z77\Core\DI,
    Z77\Core\Exception\NotFoundException,
    Z77\Core\Http\Response\FileResponse,
    Z77\Module\Dms\Blob\BlobStorage,
    Z77\Module\Dms\Services\AclService,
    Z77\Module\Dms\Services\DocumentService,
    Z77\Module\Dms\ValueObjects\Principal
;

/**
 * Authorized document delivery (DMS, ADR-017 §8 / R4c) — the GUEST endpoint behind the
 * `/media` reserved route. Replaces the former `MediaController` (module-frontend) and its
 * `publicPath`/visibility lookup with the structural-URL + `deliveryMode` + ACL model.
 *
 * URL: `/media/{root-slug}/{folder-slug…}/{file}` — captured as content slugs by the
 * reserved route (`Request::getSlugs()`, ADR-017 R3). The first slug is the partition
 * root's slug (a real folder, ADR-020), the rest are the structural document path
 * (`<slug>.<ext>` or `<slug>.<variant>.<ext>`).
 *
 * Delivery (ADR-017 §3):
 *  - `public`            → served openly (no ACL); the web server normally serves the
 *                          materialized static copy and only an un-materialized public file
 *                          reaches PHP here (materialization = R5).
 *  - `protected`/`sealed` → `AclService::canRead()` (effective `READ` + the full `active`
 *                          chain) MUST pass BEFORE any byte; otherwise **404** (existence is
 *                          never leaked — same response as "not found").
 *
 * Byte transfer is always the portable PHP range-stream (`FileResponse`); web-server
 * acceleration was rejected (ADR-017).
 */
class OutputController extends AbstractBaseController
{
    /**
     * @throws NotFoundException when nothing resolves or the ACL/active gate fails
     */
    protected function serveAction(): FileResponse
    {
        $slugs = DI::getRequest()->getSlugs();

        // Minimum shape: /media/<root-slug>/<file> — the root is a real folder (ADR-020).
        if (count($slugs) < 2) {
            throw new NotFoundException('Media not found.');
        }

        $service  = DocumentService::create();
        $resolved = $service->resolve($slugs);
        if ($resolved === null) {
            throw new NotFoundException('Media not found.');
        }

        /** @var \Z77\Module\Dms\Entities\Document $doc */
        $doc     = $resolved['document'];
        $variant = $resolved['variant'];

        if ($service->effectiveDeliveryMode($doc) === 'public') {
            // Open delivery — no ACL. The full active chain (doc + all ancestor folders)
            // must hold — same predicate the materialization uses, so this PHP fallback
            // never serves what the static copy would not (R6).
            if (!$service->isActiveChain($doc)) {
                throw new NotFoundException('Media not found.');
            }
            $cacheControl = 'public, max-age=31536000, immutable';
        } else {
            // protected / sealed — effective READ + the full active chain, BEFORE bytes.
            $principal = Principal::fromAuthUser(DI::getAuthService()->getCurrentUser());
            if (!AclService::create()->canRead($principal, $doc)) {
                throw new NotFoundException('Media not found.');
            }
            $cacheControl = 'private, no-cache';
        }

        $variantArg = $variant === BlobStorage::ORIGINAL ? null : $variant;

        return $service->serve($doc, $variantArg, download: false, cacheControl: $cacheControl);
    }
}
