<?php
namespace Z77\Module\Dms\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\FileResponse,
    Z77\Shared\Attributes\HttpMethod,
    Z77\Module\Dms\Services\DocumentService
;

/**
 * Authenticated document byte-delivery for the DMS Drive fragment (ADR-018 / ADR-019).
 * The management UI is the Drive ({@see DriveControllerTrait}); this trait only streams
 * bytes for the Drive's inline preview / thumbnail (`preview`) and attachment download
 * (`download`). Mounted by a host controller (`use DocumentDeliveryTrait`) under the
 * host's route + auth.
 *
 * The per-document gate lives in the DOMAIN (RF-4a): {@see DocumentService::serveFor}
 * requires effective `read` on the session principal before any byte — the host's
 * role config is only the coarse first line, never the actual protection (a UI/config
 * gate is bypassable; see dms-authz-bauplan). This is the management counterpart to the
 * public `/media` {@see \Z77\Module\Dms\Ui\Controllers\Media\OutputController}.
 *
 * URL: /{host}/{group}/document/{preview,download}?id=&variant=
 */
trait DocumentDeliveryTrait
{
    /** Inline preview (browser-renderable kinds + image variants). */
    #[HttpMethod('GET', 'HEAD')]
    protected function previewAction(): FileResponse
    {
        return $this->serveBytes(download: false);
    }

    /** Force a download (attachment). */
    #[HttpMethod('GET', 'HEAD')]
    protected function downloadAction(): FileResponse
    {
        return $this->serveBytes(download: true);
    }

    private function serveBytes(bool $download): FileResponse
    {
        $id = (int) DI::getRequest()->getGetParameter('id');

        $variant = DI::getRequest()->getGetParameter('variant');
        $variant = is_string($variant) && $variant !== '' ? $variant : null;

        // Domain-gated: effective `read` for the session principal, 404 otherwise.
        return DocumentService::create()->serveFor($id, $variant, $download);
    }
}
