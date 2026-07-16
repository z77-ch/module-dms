<?php
namespace Z77\Module\Dms\Ui;

use Z77\Core\Config\AuthRole,
    Z77\Core\DI,
    Z77\Core\Exception\NotFoundException,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Services\TemplateRenderer,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod,
    Z77\Module\Dms\Entities\Document,
    Z77\Module\Dms\Entities\Folder,
    Z77\Module\Dms\Images\ImageProfileRegistry,
    Z77\Module\Dms\Repositories\FolderRepository,
    Z77\Module\Dms\Services\Authz,
    Z77\Module\Dms\Services\DocumentService,
    Z77\Module\Dms\Services\DuplicateUploadException,
    Z77\Module\Dms\Services\FolderService,
    Z77\Module\Dms\Services\NameConflictException,
    Z77\Module\Dms\Services\UploadService,
    Z77\Shared\Tree\TreeService
;

/**
 * The DMS Drive — a three-pane document surface (R6b): left folder hierarchy,
 * middle document list (thumbnails / kind icons), right preview. Rendered as the
 * embedded `.dms` fragment (ADR-018): a host controller (`extends {Host}AbstractController`,
 * `use DriveControllerTrait`) mounts it under the host's route + auth + shell, while all
 * templates/JS/CSS resolve from `module-dms` ({@see DMS_NS}).
 *
 * Scope is the tree + ACL (ADR-020, decision (b)): the Drive shows every partition root
 * the session principal can `read` (admin bypass = all) — there is no host-fixed area.
 * Every per-id request (pane, modal, bytes) is READ-gated deny-by-default (RF-4a); the
 * tree/list filtering on top is presentation, never the protection.
 *
 * Data comes from {@see DocumentService} (host-independent). URLs are built host-relative
 * from the current route ({@see groupBase()}); delivery-mode is shown per document via
 * {@see DocumentService::effectiveDeliveryMode}. Image thumbnails/preview reuse the
 * sibling `document/preview` byte-delivery endpoint (DocumentDeliveryTrait — domain-gated).
 *
 * The using class MUST provide (via its host base): `html()`, `fetch()`, `fetchError()`,
 * `em()`, `$layoutManager`, `$messageService`.
 */
trait DriveControllerTrait
{
    /** Namespace that owns the fragment's templates/JS/CSS (ADR-018). */
    private const DMS_NS = 'Z77\\Module\\Dms';

    /** Session slot holding the Drive's presentation-scope root id (option A / delivery (b)). */
    private const DRIVE_SCOPE_SESSION_KEY = 'dmsDriveRoot';

    private bool $mountRootResolved = false;
    private ?int $mountRootId = null;

    /**
     * Presentation scope (option A, session-sticky (b)): the folder id this Drive is rooted at,
     * or null for the full readable forest (the default). The tree top, breadcrumb home, default
     * selection, and upload/move target selects are confined to that subtree.
     *
     * The scope is a SESSION slot driven by a `?key=<root-key>` SWITCH TRIGGER — a nav entry
     * carries `?key=front` ({@see \Z77\Shared\Entities\Navigation::$param}); the first request
     * stores the resolved root, every following pane/modal request reads the session (no URL
     * threading). An explicit blank `?key=` (present but empty) resets to the full view; `?key`
     * absent leaves the stored scope. The value is resolved by module key first, else by root
     * SLUG (`findRootByKey ?? findRootBySlug`, both find-only) — so a human-created root (`key =
     * null`, e.g. `front`) is addressable by its slug without a code key. NEVER `rootFolder`
     * (get-or-create) — a crafted `?key=` can NOT create a partition (S2 root-squatting); and a
     * root `key` is deliberately code-only, there is NO UI action to set it.
     *
     * Presentation ONLY: access is unchanged — an admin still bypasses ACL and a crafted
     * `?folder=` outside the subtree is still served (per-id reads stay ACL-gated, RF-4a; the
     * tree/breadcrumb filtering is presentation, never the gate). Memoized per request. A host
     * MAY override this to hard-pin a mount to one root.
     */
    protected function mountRoot(): ?int
    {
        if ($this->mountRootResolved) {
            return $this->mountRootId;
        }
        $this->mountRootResolved = true;

        $session = DI::getSessionManager();
        $key     = DI::getRequest()->getGetParameter('key');

        if ($key !== null) { // present (even blank) = switch trigger
            $key  = trim((string) $key);
            // Resolve by module key first, else by root slug — a human-created root (key = null)
            // is addressable by its slug (set via the normal create/rename flow); the key stays
            // code-only (S2). Both are find-only — never `rootFolder` (get-or-create).
            $root = $key !== ''
                ? ($this->folderRepo()->findRootByKey($key) ?? $this->folderRepo()->findRootBySlug($key))
                : null;
            $this->mountRootId = $root instanceof Folder ? (int) $root->getId() : null;
            $session->set(self::DRIVE_SCOPE_SESSION_KEY, $this->mountRootId);
            return $this->mountRootId;
        }

        $stored = $session->get(self::DRIVE_SCOPE_SESSION_KEY);
        $id     = is_int($stored) ? $stored : null;
        // A stored scope must still resolve (a re-seed can retire the id) — a stale id
        // would mount the Drive on nothing and render an empty tree. Fall back to the
        // full view and clear the slot.
        if ($id !== null && !$this->folderRepo()->find($id) instanceof Folder) {
            $id = null;
            $session->set(self::DRIVE_SCOPE_SESSION_KEY, null);
        }
        $this->mountRootId = $id;
        return $this->mountRootId;
    }

    /**
     * A live document the session principal may `read`, or null (RF-4a — the requested
     * resource is denied, not just unlinked; a denial is indistinguishable from a miss).
     */
    private function readableDoc(int $id): ?Document
    {
        $doc = $id > 0 ? $this->docService()->get($id) : null;

        return ($doc instanceof Document && Authz::create()->allows('document', $id, 'read'))
            ? $doc
            : null;
    }

    /** A folder the session principal may `read`, or null (RF-4a). */
    private function readableFolder(int $id): ?Folder
    {
        $folder = $id > 0 ? $this->folderRepo()->find($id) : null;

        return ($folder instanceof Folder && Authz::create()->allows('folder', $id, 'read'))
            ? $folder
            : null;
    }

    /** Base URL of the host mount (`/{module}/{group}`), for host-relative action/sibling URLs. */
    private function groupBase(): string
    {
        $req = DI::getRequest();
        return '/' . $req->getModule() . '/' . $req->getGroup();
    }

    /** kind → [thumb modifier, icon sprite id] for the list/preview UI. */
    private const KIND_UI = [
        'image'   => ['image',    'i-image'],
        'pdf'     => ['document', 'i-doc'],
        'word'    => ['document', 'i-doc'],
        'excel'   => ['document', 'i-doc'],
        'text'    => ['text',     'i-text'],
        'json'    => ['text',     'i-text'],
        'html'    => ['text',     'i-text'],
        'archive' => ['archive',  'i-archive'],
        'audio'   => ['audio',    'i-audio'],
        'video'   => ['video',    'i-audio'],
    ];

    private function folderRepo(): FolderRepository
    {
        return $this->em()->getRepository(Folder::class);
    }

    protected function listAction(): HtmlResponse
    {
        $response = $this->html($this->buildViewModel(
            $this->folderParam(),
            $this->docParam(),
        ) + ['activeSection' => 'backend-main']);
        // Page-specific: load the embedded `.dms` bundle + the Drive interaction
        // script only on the Drive (ADR-018: the host decides; scoped to this action
        // rather than the whole backend). `drive.js` turns the server-rendered
        // folder/file links into in-place fetch-pane updates (it intercepts the
        // `<a href>` and posts to {@see paneAction}); without JS the `href` is a
        // full-reload fallback — CSS/server-first (conventions.md#javascript).
        $this->layoutManager->addCss('dms', 'Z77\\Module\\Dms');
        $this->layoutManager->addJs('documents/drive', self::DMS_NS);
        return $response;
    }

    /**
     * In-place pane refresh (Fetch GET): re-renders the four Drive panes for the
     * selected folder/document and returns them as `replace-html` commands, so a
     * folder/file click updates the columns without a full page reload. Same view
     * model as {@see listAction} — the panes are the exact R6b partials.
     */
    #[Fetch, HttpMethod('GET')]
    protected function paneAction(): FetchResponse
    {
        return $this->panes($this->buildViewModel($this->folderParam(), $this->docParam()));
    }

    /**
     * Builds a Fetch envelope that replaces the four Drive panes with the given view
     * model (`replace-html`). Shared by the click-driven {@see paneAction} and the
     * post-action {@see paneRefresh} — the single place that knows the pane targets.
     */
    private function panes(array $vm): FetchResponse
    {
        return $this->fetch()
            ->addCommand('replace-html', ['target' => '.dms-drive__tree',       'html' => $this->renderPane('_tree', $vm)])
            ->addCommand('replace-html', ['target' => '.dms-drive__breadcrumb', 'html' => $this->renderPane('_breadcrumb', $vm)])
            ->addCommand('replace-html', ['target' => '.dms-drive__list',       'html' => $this->renderPane('_list', $vm)])
            ->addCommand('replace-html', ['target' => '.dms-drive__preview',    'html' => $this->renderPane('_preview', $vm)]);
    }

    /**
     * Closes the modal and refreshes the Drive panes for the given folder/document —
     * the success response of an in-place document action (rename/move/delete). No page
     * reload, so the open folder + selection are preserved.
     */
    private function paneRefresh(?int $folderId, ?int $docId): FetchResponse
    {
        return $this->panes($this->buildViewModel($folderId, $docId))->addCommand('close-modal');
    }

    private function docService(): DocumentService
    {
        return DocumentService::create();
    }

    private function folderService(): FolderService
    {
        return FolderService::create();
    }

    // ── upload ──────────────────────────────────────────────────────────────────

    /**
     * The Drive's upload modal (file picker + target-folder select, defaulting to the
     * folder currently open in the Drive). Reuses the shared multipart client
     * `documents/upload.js` (lazy-loaded into the popup) — the same building block as the
     * legacy `documents` tool; only the endpoint differs so success can return to the
     * Drive (see {@see uploadAction}).
     */
    protected function addAction(): HtmlResponse
    {
        $response = $this->html([
            'folderId'      => $this->folderParam(),
            'folderOptions' => $this->folderOptions(),
            'maxBytes'      => UploadService::serverMaxBytes(),          // transport cap — all files
            'maxImageBytes' => UploadService::effectiveMaxUploadBytes(), // memory cap — images only
            'base'          => $this->groupBase(),
        ]);
        $this->layoutManager->addPartials('_upload', 'Documents/DriveController', self::DMS_NS);
        $response->addCommand('load-script', [
            'src'   => $this->layoutManager->resolveJsPath('documents/upload', self::DMS_NS),
            'init'  => 'documents-upload',
            'scope' => '[data-z77-popup-body]',
        ]);
        return $response;
    }

    /**
     * Per-file multipart upload endpoint (ONE file per POST, under `files[]`). The client
     * ({@see documents/upload.js}) drives a sequential XHR queue and one request per file, so
     * this endpoint saves exactly one file and returns a PER-FILE result — it MUST NOT
     * redirect or aggregate; the client collects the outcomes and refreshes the Drive once.
     *
     * Bytes go through {@see UploadService} (allowlist + finfo sniff, `write` gate on the
     * target folder); the folder chain's effective `deliveryMode` applies (default `protected`).
     *
     * Envelope (never a redirect):
     *  - `success`   `{ id, name }`       — saved.
     *  - `duplicate` `{ name }`           — identical bytes already present (checksum dedupe).
     *  - `conflict`  `{ name }`           — same name, different bytes, no overwrite consent;
     *                                       the client asks and retries this file with `overwrite=1`.
     *  - `error`     `{ name, message }`  — validation / size / memory / type / other.
     */
    #[Fetch, HttpMethod('POST')]
    protected function uploadAction(): FetchResponse
    {
        $files = DI::getRequest()->getUploadedFiles('files');
        if ($files === []) {
            return $this->fetchError('Keine Datei empfangen.');
        }
        $file = $files[0]; // per-file endpoint: exactly one file per request (P1)

        // Documents always live in a folder (roots are folders, ADR-020).
        $folderId = (int) DI::getRequest()->getPostParameter('folder_id') ?: null;
        if ($folderId === null) {
            return $this->fetchError('Bitte einen Ziel-Ordner wählen.');
        }
        if ($this->readableFolder($folderId) === null) {
            return $this->fetchError('Ziel-Ordner nicht gefunden.');
        }

        $showOriginal = (bool) DI::getRequest()->getPostParameter('show_original');
        $overwrite    = (bool) DI::getRequest()->getPostParameter('overwrite');
        $createdBy    = DI::getAuthService()->getCurrentUser()?->getId();
        $poster       = DI::getRequest()->getUploadedFile('poster'); // videos only (P4); else null
        $name         = $file->originalName;

        try {
            // The domain gates `write` on the target folder (UploadService, R-authz-1).
            $doc = UploadService::create()->save(
                file: $file,
                folderId: $folderId,
                profile: null, // null = AUTO: folder-assigned/inherited profile ?? partition 'default' (SaveService)
                showOriginal: $showOriginal,
                createdBy: $createdBy,
                overwrite: $overwrite,
                poster: $poster,
            );
            $this->docService()->rebuildMaterialization(); // a new file may land in a public folder

            return $this->fetch()
                ->setStatus('success')
                ->setData(['id' => (int) $doc->getId(), 'name' => $name]);
        } catch (DuplicateUploadException) {
            return $this->fetch()->setStatus('duplicate')->setData(['name' => $name]);
        } catch (NameConflictException) {
            return $this->fetch()->setStatus('conflict')->setData(['name' => $name]);
        } catch (\RuntimeException $e) {
            return $this->fetch()->setStatus('error')->setData(['name' => $name, 'message' => $e->getMessage()]);
        }
    }

    // ── document actions (rename / move / delete) ────────────────────────────────
    // Reuse the legacy `documents` modals (edit/move/confirmDelete templates) and the
    // DocumentService; only the success path differs — an in-place pane refresh instead
    // of a full reload, so the open folder + selection stay put.

    /** Combined edit modal for a document (name / delivery mode / ACL) — see {@see combinedEdit}. */
    protected function editAction(): HtmlResponse|FetchResponse
    {
        return $this->combinedEdit('document');
    }

    /**
     * The combined edit surface (2026-07-03, replaces the separate rename/mode/ACL
     * modals): ONE modal with the form fields (name; folder: + `key` when editable;
     * delivery mode) and the embedded ACL section. Opening requires effective `manage`
     * (denial reads like a miss, RF-4a); every operation stays domain-gated on top
     * (partition lifecycle / root-partition grants / `setKey` = SUPER_USER, ADR-021 —
     * a crafted POST hits the domain wall, the field rendering is presentation).
     *
     * POST ops (source-url): `save` (default — name/key/mode; only CHANGED values are
     * applied, so locked-but-unchanged fields never trip a domain lock), `grant`/`revoke`
     * (ACL rows — the response re-renders this modal in place, like the old ACL panel,
     * so rules are managed without closing).
     */
    private function combinedEdit(string $type): HtmlResponse|FetchResponse
    {
        $id      = (int) DI::getRequest()->getGetParameter('id');
        $missing = ($type === 'folder' ? 'Ordner' : 'Dokument') . ' nicht gefunden';
        $exists  = $type === 'folder'
            ? $this->folderRepo()->find($id) instanceof Folder
            : $this->docService()->get($id) instanceof Document;
        if ($id <= 0 || !$exists || !Authz::create()->allows($type, $id, 'manage')) {
            return $this->fetchError($missing);
        }

        if (DI::getRequest()->isPost()) {
            $body = DI::getRequest()->getJsonBody();
            if (!DI::getCsrfService()->validateEntityToken(trim($body['entity_csrf'] ?? ''), $type, $id)) {
                return $this->fetchError('Invalid token');
            }
            $op = $body['op'] ?? 'save';
            if ($op === 'grant' || $op === 'revoke') {
                $this->applyAclOp($type, $id, $body); // pushes its own flash; fall through to re-render
            } else {
                try {
                    $this->applyEditSave($type, $id, $body);
                } catch (\InvalidArgumentException | \RuntimeException | NotFoundException $e) {
                    return $this->fetchError($e->getMessage());
                }
                $this->messageService->pushFlash('success', ($type === 'folder' ? 'Ordner' : 'Dokument') . ' gespeichert');
                $folderId = $type === 'folder' ? $id : $this->docService()->get($id)?->getFolderId();
                return $this->paneRefresh($folderId, $type === 'document' ? $id : null);
            }
        }

        $response = $this->html($this->editVm($type, $id));
        $this->layoutManager->addPartials('_edit', 'Documents/DriveController', self::DMS_NS);
        return $response;
    }

    /** Apply the `save` op of the combined edit — only values that actually changed. */
    private function applyEditSave(string $type, int $id, array $body): void
    {
        $svc = $this->docService();

        if ($type === 'document') {
            $doc  = $svc->get($id);
            $name = trim($body['display_name'] ?? '');
            if ($name === '') {
                throw new \InvalidArgumentException('Bitte einen Namen angeben.');
            }
            if ($doc !== null && $name !== $doc->getDisplayName()) {
                $svc->rename($id, $name);
            }
            $mode = $this->modeParam($body);
            if ($doc !== null && $mode !== $doc->getDeliveryMode()) {
                $svc->setDeliveryMode($id, $mode);
            }
            if ($doc !== null && $doc->getKind() === 'image') {
                $alt = $caption = [];
                foreach (DI::getI18n()->getLanguages() as $lang) {
                    if (($v = trim((string) ($body['alt_' . $lang] ?? ''))) !== '') {
                        $alt[$lang] = $v;
                    }
                    if (($v = trim((string) ($body['caption_' . $lang] ?? ''))) !== '') {
                        $caption[$lang] = $v;
                    }
                }
                if ($alt !== $doc->getAltMap() || $caption !== $doc->getCaptionMap()) {
                    $svc->setImageText($id, $alt, $caption);
                }
            }
            return;
        }

        $folder = $this->folderRepo()->find($id);
        if (!$folder instanceof Folder) {
            throw new NotFoundException('Ordner nicht gefunden');
        }
        if ($folder->getParentId() === null) {
            return; // drive root: name/key/mode are fixed (ADR-021) — only the ACL section applies
        }
        $name = trim($body['name'] ?? '');
        if ($name === '') {
            throw new \InvalidArgumentException('Bitte einen Ordnernamen angeben.');
        }
        if ($name !== $folder->getName()) {
            $this->folderService()->rename($id, $name);
        }
        if (array_key_exists('key', $body)) { // field is only rendered when editable; the domain re-gates
            $key = trim((string) $body['key']);
            $key = $key === '' ? null : $key;
            if ($key !== $folder->getKey()) {
                $this->folderService()->setKey($id, $key);
            }
        }
        $mode = $this->modeParam($body);
        if ($mode !== $folder->getDeliveryMode()) {
            $svc->setFolderDeliveryMode($id, $mode);
        }
        if (array_key_exists('profile', $body)) { // field is only rendered when the partition has profiles
            $profile = trim((string) $body['profile']);
            $profile = $profile === '' ? null : $profile;
            if ($profile !== $folder->getProfile()) {
                $this->folderService()->setProfile($id, $profile);
            }
        }
    }

    /**
     * View model of the combined edit modal. The lock flags are PRESENTATION (the domain
     * gates are authoritative): the drive root fixes name/key/mode; a partition's name is
     * SUPER_USER lifecycle (locked for a delegated manage-holder) and a module (`system`)
     * partition locks name+key entirely (S4 — slug/address stability).
     *
     * @return array<string, mixed>
     */
    private function editVm(string $type, int $id): array
    {
        $svc  = $this->docService();
        $csrf = DI::getCsrfService()->generateEntityToken($type, $id);

        if ($type === 'document') {
            $doc = $svc->get($id);
            return [
                'type'          => 'document',
                'id'            => $id,
                'name'          => $doc->getDisplayName(),
                'originalName'  => $doc->getOriginalName(),
                'ownMode'       => $doc->getDeliveryMode(),
                'effectiveMode' => $svc->effectiveDeliveryMode($doc),
                'sealedAbove'   => $svc->hasSealedAncestor('document', $id),
                'nameLocked'    => false,
                'modeLocked'    => false,
                'keyEditable'   => false,
                'key'           => null,
                'isImage'       => $doc->getKind() === 'image',
                'languages'     => DI::getI18n()->getLanguages(),
                'altMap'        => $doc->getAltMap(),
                'captionMap'    => $doc->getCaptionMap(),
                'aces'          => $this->aceRows('document', $id),
                'entityCsrf'    => $csrf,
            ];
        }

        $folder      = $this->folderRepo()->find($id);
        $isDriveRoot = $folder->getParentId() === null;
        $isPartition = !$isDriveRoot
            && $this->folderRepo()->find($folder->getParentId())?->getParentId() === null;
        $isSuper     = Authz::create()->isSuperUser();

        // Image profile (folder-assigned, inherited down): offer the field only when the
        // folder's partition has project profiles at all — no dead dropdown.
        $profileOptions = [];
        if (!$isDriveRoot) {
            $ident          = $svc->partitionIdentOf($id);
            $profileOptions = $ident !== null ? ImageProfileRegistry::fromConfig()->names($ident) : [];
        }

        return [
            'type'             => 'folder',
            'id'               => $id,
            'name'             => $folder->getName(),
            'originalName'     => null,
            'ownMode'          => $folder->getDeliveryMode(),
            'effectiveMode'    => $svc->effectiveFolderDeliveryMode($folder),
            'sealedAbove'      => $svc->hasSealedAncestor('folder', $id),
            'nameLocked'       => $isDriveRoot || ($isPartition && ($folder->isSystem() || !$isSuper)),
            'modeLocked'       => $isDriveRoot,
            'keyEditable'      => $isSuper && $isPartition && !$folder->isSystem(),
            'key'              => $folder->getKey(),
            'ownProfile'       => $folder->getProfile(),
            'effectiveProfile' => $svc->effectiveFolderProfile($folder),
            'profileOptions'   => $profileOptions,
            'aces'             => $this->aceRows('folder', $id),
            'entityCsrf'       => $csrf,
        ];
    }

    /** Move modal (GET) + handler (POST). The move form posts to this endpoint (explicit URL). */
    protected function moveAction(): HtmlResponse|FetchResponse
    {
        if (DI::getRequest()->isPost()) {
            $body = DI::getRequest()->getJsonBody();
            $id   = (int) ($body['id'] ?? 0);
            $doc  = $this->readableDoc($id);
            if ($doc === null) {
                return $this->fetchError('Dokument nicht gefunden');
            }
            if (!DI::getCsrfService()->validateEntityToken(trim($body['entity_csrf'] ?? ''), 'document', $id)) {
                return $this->fetchError('Invalid token');
            }
            $target = (int) ($body['folder_id'] ?? 0) ?: null;
            $this->docService()->move($id, $target); // domain-gated: manage on doc + write on target
            $this->messageService->pushFlash('success', 'Dokument verschoben');
            return $this->paneRefresh($target, $id); // follow the document into its new folder
        }

        $id  = (int) DI::getRequest()->getGetParameter('id');
        $doc = $this->readableDoc($id);
        if ($doc === null) {
            return $this->fetchError('Dokument nicht gefunden');
        }
        $response = $this->html([
            'doc'           => $doc,
            'folderOptions' => $this->folderOptions(),
            'entityCsrf'    => DI::getCsrfService()->generateEntityToken('document', $id),
            'postUrl'       => $this->groupBase() . '/drive/move',
            'base'          => $this->groupBase(),
        ]);
        $this->layoutManager->addPartials('move', 'Documents/DocumentController', self::DMS_NS);
        return $response;
    }

    /** Delete confirmation modal (GET). The remove form posts to {@see removeAction}. */
    protected function confirmDeleteAction(): HtmlResponse
    {
        $id  = (int) DI::getRequest()->getGetParameter('id');
        $doc = $this->readableDoc($id);

        $response = $this->html([
            'doc'        => $doc,
            'entityCsrf' => $doc ? DI::getCsrfService()->generateEntityToken('document', $id) : '',
            'removeUrl'  => $this->groupBase() . '/drive/remove',
            'base'       => $this->groupBase(),
        ]);
        $this->layoutManager->addPartials('confirmDelete', 'Documents/DocumentController', self::DMS_NS);
        return $response;
    }

    /** Soft-delete handler (POST). Keeps the bytes; refreshes the panes without the document. */
    #[Fetch, HttpMethod('POST')]
    protected function removeAction(): FetchResponse
    {
        $body = DI::getRequest()->getJsonBody();
        $id   = (int) ($body['id'] ?? 0);
        if ($id <= 0) {
            return $this->fetchError('Missing id');
        }
        if (!DI::getCsrfService()->validateEntityToken(trim($body['entity_csrf'] ?? ''), 'document', $id)) {
            return $this->fetchError('Invalid token');
        }
        $doc = $this->readableDoc($id);
        if ($doc === null) {
            return $this->fetchError('Dokument nicht gefunden');
        }
        $folderId = $doc->getFolderId();
        $this->docService()->delete($id); // soft-delete (keeps the bytes); domain-gated manage
        $this->messageService->pushFlash('success', 'Dokument gelöscht');
        return $this->paneRefresh($folderId, null);
    }

    // ── bulk actions (v1: documents only — delete / move) ────────────────────────
    // The list pane's selection bar collects checked row ids client-side and opens these
    // modals with `?ids=<id,id,…>` (the modal base URL is server-built, drive.js only
    // appends the selection). The POSTs carry no per-entity token (n documents), so both
    // actions are `#[Fetch]` — the global AccessGuard CSRF gate is the authority
    // (DMS-SEC-001 rule, pattern {@see folderAddAction}). Authorization stays in the
    // domain: the loop calls the EXISTING gated per-document service methods; a denied
    // or missing id is counted as skipped, never an error (partial success is reported
    // in ONE flash).

    /** Cap per bulk request — bounds the ids CSV and the per-request service loop. */
    private const BULK_MAX = 500;

    /**
     * The readable documents behind an `ids` CSV (`"33,35,…"` — GET query or POST body),
     * de-duplicated, capped at {@see BULK_MAX}, each gated through {@see readableDoc}
     * (RF-4a: an unreadable id is dropped like a miss).
     *
     * @return array<int, Document> keyed by id
     */
    private function bulkDocs(string $csv): array
    {
        $ids = array_filter(array_map('intval', explode(',', $csv)), fn (int $v) => $v > 0);
        $docs = [];
        foreach (array_slice(array_values(array_unique($ids)), 0, self::BULK_MAX) as $id) {
            $doc = $this->readableDoc($id);
            if ($doc !== null) {
                $docs[$id] = $doc;
            }
        }
        return $docs;
    }

    /** "N Dokumente" / "1 Dokument" for the bulk flash/modal texts. */
    private function bulkLabel(int $n): string
    {
        return $n === 1 ? '1 Dokument' : $n . ' Dokumente';
    }

    /**
     * Bulk move modal (GET, `?ids=`) + handler (POST). The selection travels as ONE
     * hidden CSV field (`ids`) — the fetch form serializer collapses repeated hidden
     * fields, only checkbox groups become arrays. Each move is domain-gated per document
     * (`manage` on the doc + `write` on the target); denials are skipped and reported.
     */
    #[Fetch]
    protected function bulkMoveAction(): HtmlResponse|FetchResponse
    {
        if (DI::getRequest()->isPost()) {
            $body = DI::getRequest()->getJsonBody();
            $docs = $this->bulkDocs((string) ($body['ids'] ?? ''));
            if ($docs === []) {
                return $this->fetchError('Keine Dokumente ausgewählt.');
            }
            $target = (int) ($body['folder_id'] ?? 0) ?: null;
            if ($target === null || $this->readableFolder($target) === null) {
                return $this->fetchError('Ziel-Ordner nicht gefunden.');
            }

            $moved = 0;
            foreach (array_keys($docs) as $id) {
                try {
                    $this->docService()->move($id, $target);
                    $moved++;
                } catch (NotFoundException | \InvalidArgumentException | \RuntimeException) {
                    // denied / vanished / invalid target for THIS doc → skipped, loop on
                }
            }
            $skipped = count($docs) - $moved;
            $this->messageService->pushFlash(
                $moved > 0 ? 'success' : 'error',
                $this->bulkLabel($moved) . ' verschoben' . ($skipped > 0 ? ', ' . $skipped . ' übersprungen' : ''),
            );
            return $this->paneRefresh($target, null); // follow the selection into its new folder
        }

        $docs = $this->bulkDocs((string) DI::getRequest()->getGetParameter('ids'));
        if ($docs === []) {
            return $this->fetchError('Keine Dokumente ausgewählt.');
        }
        $response = $this->html([
            'count'         => count($docs),
            'countLabel'    => $this->bulkLabel(count($docs)),
            'names'         => array_map(fn (Document $d) => $d->getDisplayName(), array_values($docs)),
            'idsCsv'        => implode(',', array_keys($docs)),
            'currentFolder' => $this->folderParam(),
            'folderOptions' => $this->folderOptions(),
            'postUrl'       => $this->groupBase() . '/drive/bulk-move',
            'base'          => $this->groupBase(),
        ]);
        $this->layoutManager->addPartials('_bulkMove', 'Documents/DriveController', self::DMS_NS);
        return $response;
    }

    /** Bulk delete confirmation modal (GET, `?ids=`). The form posts to {@see bulkRemoveAction}. */
    #[Fetch, HttpMethod('GET')]
    protected function bulkConfirmDeleteAction(): HtmlResponse|FetchResponse
    {
        $docs = $this->bulkDocs((string) DI::getRequest()->getGetParameter('ids'));
        if ($docs === []) {
            return $this->fetchError('Keine Dokumente ausgewählt.');
        }
        $response = $this->html([
            'count'      => count($docs),
            'countLabel' => $this->bulkLabel(count($docs)),
            'names'      => array_map(fn (Document $d) => $d->getDisplayName(), array_values($docs)),
            'idsCsv'     => implode(',', array_keys($docs)),
            'folderId'   => $this->folderParam(),
            'removeUrl'  => $this->groupBase() . '/drive/bulk-remove',
            'base'       => $this->groupBase(),
        ]);
        $this->layoutManager->addPartials('_bulkConfirmDelete', 'Documents/DriveController', self::DMS_NS);
        return $response;
    }

    /**
     * Bulk soft-delete handler (POST). Each delete is the existing domain-gated
     * {@see DocumentService::delete} (manage per document, bytes kept — trash).
     */
    #[Fetch, HttpMethod('POST')]
    protected function bulkRemoveAction(): FetchResponse
    {
        $body = DI::getRequest()->getJsonBody();
        $docs = $this->bulkDocs((string) ($body['ids'] ?? ''));
        if ($docs === []) {
            return $this->fetchError('Keine Dokumente ausgewählt.');
        }

        $deleted = 0;
        foreach (array_keys($docs) as $id) {
            try {
                $this->docService()->delete($id);
                $deleted++;
            } catch (NotFoundException | \RuntimeException) {
                // denied / vanished → skipped, loop on
            }
        }
        $skipped = count($docs) - $deleted;
        $this->messageService->pushFlash(
            $deleted > 0 ? 'success' : 'error',
            $this->bulkLabel($deleted) . ' gelöscht' . ($skipped > 0 ? ', ' . $skipped . ' übersprungen' : ''),
        );
        return $this->paneRefresh((int) ($body['folder'] ?? 0) ?: null, null);
    }

    // ── folder actions (new / rename / move / delete) ────────────────────────────
    // The controller keeps the surface concerns (CSRF, modal render, flash, pane refresh);
    // the folder domain logic (slug uniqueness, delete/cycle guards, materialization) lives
    // in {@see FolderService} (ADR-019). Actions call the service and surface its exceptions.

    /**
     * New folder modal (GET, optional `?parent=`) + create handler (POST, source-url).
     *
     * `#[Fetch]` (both verbs): this action is only ever reached via the fetch layer (the
     * modal is loaded with `data-fetch-get`, the form posts with `data-fetch-post`), and the
     * create POST carries no per-entity token (a new folder has no id yet). Requiring Fetch
     * mode makes the global CSRF gate (AccessGuard, Fetch-POST only) the authoritative check
     * — otherwise a cross-site Page-mode POST would skip CSRF entirely.
     */
    #[Fetch]
    protected function folderAddAction(): HtmlResponse|FetchResponse
    {
        $parentId = (int) DI::getRequest()->getGetParameter('parent') ?: null;
        $parent   = $parentId !== null ? $this->readableFolder($parentId) : null;
        if ($parentId !== null && $parent === null) {
            return $this->fetchError('Ziel-Ordner nicht gefunden.');
        }

        if (DI::getRequest()->isPost()) {
            $name = trim(DI::getRequest()->getJsonBody()['name'] ?? '');
            try {
                // Domain-gated: top-level = admin (a new partition), else write on parent.
                $folder = $this->folderService()->add($parentId, $name);
            } catch (\InvalidArgumentException $e) {
                return $this->fetchError($e->getMessage());
            }
            $this->messageService->pushFlash('success', 'Ordner «' . $folder->getName() . '» angelegt');
            return $this->paneRefresh((int) $folder->getId(), null);
        }

        $response = $this->html([
            'folder'     => new Folder(),
            'parent'     => $parent,
            'entityCsrf' => '',
        ]);
        $this->layoutManager->addPartials('edit', 'Documents/FolderController', self::DMS_NS);
        return $response;
    }

    /** Combined edit modal for a folder (name / key / delivery mode / ACL) — see {@see combinedEdit}. */
    protected function folderEditAction(): HtmlResponse|FetchResponse
    {
        return $this->combinedEdit('folder');
    }

    /** Move folder modal (GET, own `_folderMove` partial) + handler (POST). */
    protected function folderMoveAction(): HtmlResponse|FetchResponse
    {
        if (DI::getRequest()->isPost()) {
            $body = DI::getRequest()->getJsonBody();
            $id   = (int) ($body['id'] ?? 0);
            if (!DI::getCsrfService()->validateEntityToken(trim($body['entity_csrf'] ?? ''), 'folder', $id)) {
                return $this->fetchError('Invalid token');
            }
            $target = (int) ($body['folder_id'] ?? 0) ?: null;
            try {
                $this->folderService()->move($id, $target);
            } catch (\InvalidArgumentException | NotFoundException | \RuntimeException $e) {
                return $this->fetchError($e->getMessage());
            }
            $this->messageService->pushFlash('success', 'Ordner verschoben');
            return $this->paneRefresh($id, null);
        }

        $id     = (int) DI::getRequest()->getGetParameter('id');
        $folder = $this->readableFolder($id);
        if ($folder === null) {
            return $this->fetchError('Ordner nicht gefunden');
        }
        $response = $this->html([
            'folder'        => $folder,
            'folderOptions' => $this->folderOptions($id), // self + descendants excluded
            'entityCsrf'    => DI::getCsrfService()->generateEntityToken('folder', $id),
            'base'          => $this->groupBase(),
        ]);
        $this->layoutManager->addPartials('_folderMove', 'Documents/DriveController', self::DMS_NS);
        return $response;
    }

    /** Delete-confirmation modal for a folder (GET). Reuses the FolderController partial. */
    protected function folderConfirmDeleteAction(): HtmlResponse
    {
        $id     = (int) DI::getRequest()->getGetParameter('id');
        $folder = $this->readableFolder($id);
        $response = $this->html([
            'folder'      => $folder,
            'entityCsrf'  => $folder ? DI::getCsrfService()->generateEntityToken('folder', $id) : '',
            'blockReason' => $folder ? $this->folderService()->blockReason($folder) : null,
            'removeUrl'   => $this->groupBase() . '/drive/folder-remove',
            'base'        => $this->groupBase(),
        ]);
        $this->layoutManager->addPartials('confirmDelete', 'Documents/FolderController', self::DMS_NS);
        return $response;
    }

    /** Delete a folder (POST). Only when empty and not a system folder; refreshes the panes. */
    #[Fetch, HttpMethod('POST')]
    protected function folderRemoveAction(): FetchResponse
    {
        $body = DI::getRequest()->getJsonBody();
        $id   = (int) ($body['id'] ?? 0);
        if ($id <= 0) {
            return $this->fetchError('Missing id');
        }
        if (!DI::getCsrfService()->validateEntityToken(trim($body['entity_csrf'] ?? ''), 'folder', $id)) {
            return $this->fetchError('Invalid token');
        }
        $folder = $this->readableFolder($id);
        $name   = $folder instanceof Folder ? $folder->getName() : '';
        try {
            $parentId = $this->folderService()->delete($id);
        } catch (NotFoundException | \RuntimeException $e) {
            return $this->fetchError($e->getMessage());
        }
        $this->messageService->pushFlash('success', 'Ordner «' . $name . '» gelöscht');
        return $this->paneRefresh($parentId, null);
    }

    /** Delivery-mode radio from a request body: '' → null (inherit). */
    private function modeParam(array $body): ?string
    {
        $mode = trim($body['mode'] ?? '');
        return $mode === '' ? null : $mode;
    }

    // ── action hub (per-row ⋮ menu) ──────────────────────────────────────────────

    /**
     * The per-row action hub for a document or folder (`?type=&id=`). The `⋮` in the tree /
     * list opens it; it launches the specific modals (rename/move/mode/acl/delete via
     * `data-fetch-get`) and carries the inline `active` switch. The switch posts via
     * `data-fetch-toggle` (`{value}`, `&op=active`) — a plain success envelope, no re-render
     * (the browser already moved the switch; the tree strikethrough updates on next nav).
     *
     * `#[Fetch]` (both verbs): the hub is opened with `data-fetch-get` and the active toggle
     * posts with `data-fetch-toggle`, so this action is only ever a fetch request. The toggle
     * POST carries no per-entity token (the toggle body is just `{value}`), so requiring Fetch
     * mode makes the global CSRF gate (AccessGuard) authoritative — otherwise a cross-site
     * Page-mode POST would flip the `active` output gate of any resource without any CSRF check.
     */
    #[Fetch]
    protected function actionsAction(): HtmlResponse|FetchResponse
    {
        $type = DI::getRequest()->getGetParameter('type') === 'folder' ? 'folder' : 'document';
        $id   = (int) DI::getRequest()->getGetParameter('id');

        [$name, $isActive] = $this->actionsResource($type, $id);
        if ($name === null) {
            return $this->fetchError(($type === 'folder' ? 'Ordner' : 'Dokument') . ' nicht gefunden');
        }

        if (DI::getRequest()->isPost()) { // the active toggle
            $active = (bool) (DI::getRequest()->getJsonBody()['value'] ?? false);
            try {
                if ($type === 'folder') {
                    $this->docService()->setFolderActive($id, $active);
                } else {
                    $this->docService()->setActive($id, $active);
                }
            } catch (NotFoundException | \RuntimeException) {
                return $this->fetch()->setStatus('error');
            }
            // Refresh the CURRENT drive view (folder/doc carried through the hub) so the
            // strikethrough updates in place — like a rename. The modal stays open; the
            // `data-fetch-toggle` handler dispatches these `replace-html` commands.
            return $this->panes($this->buildViewModel($this->folderParam(), $this->docParam()));
        }

        $response = $this->html([
            'type'     => $type,
            'id'       => $id,
            'name'     => $name,
            'isActive' => $isActive,
            // Current drive selection, so the inline active switch refreshes that view.
            'folder'   => $this->folderParam(),
            'doc'      => $this->docParam(),
            'base'     => $this->groupBase(),
        ]);
        $this->layoutManager->addPartials('_actions', 'Documents/DriveController', self::DMS_NS);
        return $response;
    }

    /**
     * Resolve the hub resource: `[name, isActive]`, or `[null, false]` when it does not
     * exist or the principal may not read it (RF-4a).
     *
     * @return array{0: ?string, 1: bool}
     */
    private function actionsResource(string $type, int $id): array
    {
        if ($type === 'folder') {
            $folder = $this->readableFolder($id);
            return $folder !== null ? [$folder->getName(), $folder->isActive()] : [null, false];
        }
        $doc = $this->readableDoc($id);
        return $doc !== null ? [$doc->getDisplayName(), $doc->isActive()] : [null, false];
    }

    // ── trash (soft-delete recovery, ADR-017) ────────────────────────────────────

    /**
     * The trash panel: lists the soft-deleted documents (domain-scoped) with restore /
     * permanent delete. Self-refreshing like the ACL panel — a restore/purge POST
     * re-renders the panel into the popup (`text/html`), so the modal stays open — AND
     * carries pane-refresh commands in the embedded envelope, so a restored document
     * reappears in the list behind the modal without a full reload. The current Drive
     * selection travels in the trash URL (`?folder=&doc=`, server-built on the
     * breadcrumb pane — same mechanism as the ⋮ action hub). Purge respects
     * `retentionUntil`; restore needs the original folder to still exist.
     *
     * `#[Fetch]` (both verbs): the panel is opened with `fetch.get` and its forms post
     * with `data-fetch-post`. The row ops (restore/purge) keep their per-entity token;
     * the `purgeAll` op ("Papierkorb leeren", 2026-07-16) spans n documents and carries
     * none — requiring Fetch mode makes the global AccessGuard CSRF gate the authority
     * (DMS-SEC-001 rule, pattern {@see folderAddAction}).
     */
    #[Fetch]
    protected function trashAction(): HtmlResponse|FetchResponse
    {
        $mutated = false;
        if (DI::getRequest()->isPost()) {
            $body = DI::getRequest()->getJsonBody();
            if (($body['op'] ?? '') === 'purgeAll') {
                // Empty the whole trash: loop the principal-scoped listDeleted() through
                // the EXISTING gated purge() (manage + retention per document, bulk rule).
                // A retention-blocked document is skipped and stays in the trash.
                $purged  = 0;
                $skipped = 0;
                foreach ($this->docService()->listDeleted() as $doc) {
                    try {
                        $this->docService()->purge((int) $doc->getId());
                        $purged++;
                    } catch (NotFoundException | \RuntimeException) {
                        $skipped++;
                    }
                }
                $this->messageService->pushFlash(
                    $purged > 0 || $skipped === 0 ? 'success' : 'error',
                    $this->bulkLabel($purged) . ' endgültig gelöscht'
                    . ($skipped > 0 ? ', ' . $skipped . ' übersprungen (Aufbewahrungsfrist)' : ''),
                );
                $mutated = $purged > 0;
            } else {
                $id = (int) ($body['id'] ?? 0);
                if (!DI::getCsrfService()->validateEntityToken(trim($body['entity_csrf'] ?? ''), 'document', $id)) {
                    return $this->fetchError('Invalid token');
                }
                try {
                    if (($body['op'] ?? '') === 'restore') {
                        $this->docService()->restore($id);
                        $this->messageService->pushFlash('success', 'Dokument wiederhergestellt');
                        $mutated = true;
                    } elseif (($body['op'] ?? '') === 'purge') {
                        $this->docService()->purge($id);
                        $this->messageService->pushFlash('success', 'Dokument endgültig gelöscht');
                        $mutated = true;
                    }
                } catch (NotFoundException | \RuntimeException $e) {
                    $this->messageService->pushFlash('error', $e->getMessage());
                }
            }
        }

        $response = $this->html(['items' => $this->trashRows()]);
        $this->layoutManager->addPartials('_trash', 'Documents/DriveController', self::DMS_NS);

        if ($mutated) {
            // Refresh the Drive panes behind the open modal (a restore puts the document
            // back into the list). Selection comes from the trash URL's query params.
            $vm = $this->buildViewModel($this->folderParam(), $this->docParam());
            foreach (['_tree' => '.dms-drive__tree', '_breadcrumb' => '.dms-drive__breadcrumb',
                      '_list' => '.dms-drive__list', '_preview' => '.dms-drive__preview'] as $partial => $target) {
                $response->addCommand('replace-html', ['target' => $target, 'html' => $this->renderPane($partial, $vm)]);
            }
        }

        return $response;
    }

    /**
     * Soft-deleted documents as flat rows for the trash panel. Scoping happens in the
     * domain ({@see DocumentService::listDeleted} — only what the principal may manage).
     *
     * @return list<array{id:int, name:string, deletedAt:?string, entityCsrf:string}>
     */
    private function trashRows(): array
    {
        $rows = [];
        foreach ($this->docService()->listDeleted() as $doc) {
            $id     = (int) $doc->getId();
            $rows[] = [
                'id'         => $id,
                'name'       => $doc->getDisplayName(),
                'deletedAt'  => $doc->getDeletedAt(),
                'entityCsrf' => DI::getCsrfService()->generateEntityToken('document', $id),
            ];
        }
        return $rows;
    }

    // ── access control (ADR-017 ACL) ─────────────────────────────────────────────
    // The panel lives INSIDE the combined edit modal ({@see combinedEdit} — `_edit`
    // partial); grant/revoke posts carry `op=` back to the edit source URL.

    /** Apply a grant/revoke from the panel form; validates the subject and pushes a flash. */
    private function applyAclOp(string $type, int $id, array $body): void
    {
        $subjectType = ($body['subject_type'] ?? '') === 'user' ? 'user' : 'role';
        $subject     = trim($body['subject'] ?? '');

        if (($body['op'] ?? '') === 'revoke') {
            $this->docService()->revoke($type, $id, $subjectType, $subject);
            $this->messageService->pushFlash('success', 'Regel entfernt');
            return;
        }

        // grant
        if ($subjectType === 'role' && !in_array($subject, [AuthRole::MEMBER, AuthRole::VISITOR], true)) {
            $this->messageService->pushFlash('error', 'Unbekannte Rolle (erlaubt: member, visitor).');
            return;
        }
        if ($subjectType === 'user' && (!ctype_digit($subject) || (int) $subject <= 0)) {
            $this->messageService->pushFlash('error', 'Benutzer-ID muss eine positive Zahl sein.');
            return;
        }
        try {
            $this->docService()->grant(
                $type, $id, $subjectType, $subject,
                (string) ($body['rights'] ?? ''),
                DI::getAuthService()->getCurrentUser()?->getId(),
            );
            $this->messageService->pushFlash('success', 'Regel gespeichert');
        } catch (\InvalidArgumentException $e) {
            $this->messageService->pushFlash('error', $e->getMessage());
        }
    }

    /**
     * Direct ACEs on a resource as flat rows for the panel.
     *
     * @return list<array{subjectType:string, subjectId:string, rights:string}>
     */
    private function aceRows(string $type, int $id): array
    {
        $rows = [];
        foreach ($this->docService()->acesFor($type, $id) as $ace) {
            $rows[] = [
                'subjectType' => $ace->getSubjectType(),
                'subjectId'   => $ace->getSubjectId(),
                'rights'      => $ace->getRights(),
            ];
        }
        return $rows;
    }

    private ?TreeService $folderTreeSvc = null;

    private function folderTree(): TreeService
    {
        // One tree, no scope partitions (ADR-020) — the roots ARE the partitions.
        return $this->folderTreeSvc ??= new TreeService(fn(Folder $f) => null);
    }

    private function folderParam(): ?int
    {
        $id = (int) DI::getRequest()->getGetParameter('folder') ?: null;
        // On a rooted mount, "no folder" means the mount root (its documents show on entry),
        // never the true tree top — this mount does not present the top. Null in the full view.
        return $id ?? $this->mountRoot();
    }

    /**
     * Flat, depth-indented folder list for the upload / move target selects — only the
     * folders the principal may `read` (RF-4a; write is enforced in the domain on use).
     * When `$excludeId` is given, that folder AND its whole subtree are skipped (so a
     * folder can never be moved into itself or a descendant).
     *
     * @return list<array{id:int, label:string}>
     */
    private function folderOptions(?int $excludeId = null): array
    {
        $folders = $this->folderRepo()->findAll();
        $tree    = $this->folderTree();
        $authz   = Authz::create();

        $options = [];
        $walk = function (?int $parentId, int $depth) use (&$walk, $folders, $tree, &$options, $excludeId, $authz): void {
            foreach ($tree->children($folders, $parentId, null) as $folder) {
                $fid = (int) $folder->getId();
                if ($excludeId !== null && $fid === $excludeId) {
                    continue; // skip the folder itself and — by not recursing — its subtree
                }
                if (!$authz->allows('folder', $fid, 'read')) {
                    continue; // unreadable subtree is not offered (children inherit the miss)
                }
                $options[] = [
                    'id'    => $fid,
                    'label' => str_repeat('— ', $depth) . $folder->getName(),
                ];
                $walk($fid, $depth + 1);
            }
        };

        // Rooted mount (option A): offer only the mount root + its subtree, so upload/move
        // targets stay inside the presented partition. Full view: the readable partitions
        // (the drive root itself is never a target — it stores no documents, ADR-021; the
        // walk starts at its children so an unreadable root does not hide granted subtrees).
        $mountRootId = $this->mountRoot();
        if ($mountRootId !== null) {
            $root = $this->folderRepo()->find($mountRootId);
            if ($root !== null && $mountRootId !== $excludeId && $authz->allows('folder', $mountRootId, 'read')) {
                $options[] = ['id' => $mountRootId, 'label' => $root->getName()];
                $walk($mountRootId, 1);
            }
        } else {
            $drive = $this->folderRepo()->findDriveRoot();
            $walk($drive?->getId(), 0);
        }
        return $options;
    }

    private function docParam(): ?int
    {
        return (int) DI::getRequest()->getGetParameter('doc') ?: null;
    }

    /** Renders one Drive pane partial to a string for a `replace-html` command. */
    private function renderPane(string $partial, array $vm): string
    {
        return (new TemplateRenderer(self::DMS_NS))
            ->partial('Documents/DriveController/' . $partial, $vm);
    }

    /**
     * Builds the full Drive view model (folder tree + counts + active path/breadcrumb,
     * the selected folder's file rows, and the selected document's preview). Shared by
     * the full-page {@see listAction} and the in-place {@see paneAction}.
     *
     * Scope = tree + ACL (ADR-020 (b)): the tree contains only folders the principal may
     * `read` — plus unreadable ANCESTORS of readable folders (path nodes, so a grant deep
     * in a partition stays reachable; their counts are masked and their content is never
     * listed). The requested selection is denied when unreadable (RF-4a): files/preview
     * stay empty — the filter above is presentation, THIS is the gate for pane content.
     *
     * @return array<string, mixed>
     */
    private function buildViewModel(?int $selectedFolderId, ?int $selectedDoc): array
    {
        $svc     = DocumentService::create();
        $folders = $this->folderRepo()->findAll();
        $tree    = $this->folderTree();
        $authz   = Authz::create();
        $mountRootId = $this->mountRoot(); // presentation scope (option A); null = full view

        $readable = [];
        foreach ($folders as $f) {
            if ($f->getId() !== null) {
                $readable[$f->getId()] = $authz->allows('folder', (int) $f->getId(), 'read');
            }
        }

        // Live document counts per folder id (only for readable folders — masked otherwise).
        $countByFolder = [];
        foreach ($svc->listAll() as $doc) {
            $key = $doc->getFolderId() ?? 0;
            if ($readable[$key] ?? false) {
                $countByFolder[$key] = ($countByFolder[$key] ?? 0) + 1;
            }
        }

        // The id chain from the selected folder up to the root drives the active path
        // (so ancestor folders render open + highlighted) and the breadcrumb.
        $index = [];
        foreach ($folders as $f) {
            if ($f->getId() !== null) {
                $index[$f->getId()] = $f;
            }
        }

        // The breadcrumb HOME is always a single anchor (ADR-021 — one root, never two):
        // the mount root on a rooted mount, else the drive root itself. The crumb walk
        // stops there, so the home label never repeats as a crumb ("drive › Drive › …").
        $driveRootId      = $this->folderRepo()->findDriveRoot()?->getId();
        $breadcrumbRootId = $mountRootId ?? $driveRootId;

        $activePath = [];
        $crumbs     = [];
        $cur        = $selectedFolderId;
        $guard      = 50;
        while ($cur !== null && isset($index[$cur]) && $guard-- > 0) {
            $activePath[$cur] = true;
            if ($cur === $breadcrumbRootId) {
                break; // the breadcrumb home, not a crumb — never climb above it
            }
            array_unshift($crumbs, ['id' => $cur, 'name' => $index[$cur]->getName()]);
            $cur = $index[$cur]->getParentId();
        }

        // Build one folder node (recursing into children), or null when it is invisible
        // (not readable and no readable descendant — RF-4). Split out from $build so a
        // rooted mount can build its single top node (the mount root) the same way.
        $makeNode = function (Folder $folder) use (&$build, $countByFolder, $activePath, $selectedFolderId, $readable): ?array {
            $id       = (int) $folder->getId();
            $children = $build($id);
            $isRead   = $readable[$id] ?? false;
            if (!$isRead && $children === []) {
                return null; // invisible: not readable and no readable descendant (RF-4)
            }
            return [
                'id'       => $id,
                'name'     => $folder->getName(),
                'count'    => $isRead ? ($countByFolder[$id] ?? 0) : 0,
                'active'   => $id === $selectedFolderId,
                'onPath'   => isset($activePath[$id]),
                'inactive' => !$folder->isActive(),
                'children' => $children,
            ];
        };
        $build = function (?int $parentId) use (&$makeNode, $folders, $tree): array {
            $nodes = [];
            foreach ($tree->children($folders, $parentId, null) as $folder) {
                $node = $makeNode($folder);
                if ($node !== null) {
                    $nodes[] = $node;
                }
            }
            return $nodes;
        };

        // The tree top: the full readable forest, or — on a rooted mount (option A) — the
        // single mount-root subtree, so the Drive presents one partition as its top.
        if ($mountRootId !== null) {
            $mountFolder = $index[$mountRootId] ?? null;
            $node        = $mountFolder !== null ? $makeNode($mountFolder) : null;
            $roots       = $node !== null ? [$node] : [];
        } else {
            $roots = $build(null);
        }

        // Documents of the selected folder → list view-model. Content only for a
        // READABLE selection (deny-by-default; a probe of an unreadable id gets nothing).
        $files   = [];
        $preview = null;
        if ($selectedFolderId !== null && ($readable[$selectedFolderId] ?? false)) {
            foreach ($svc->listByFolder($selectedFolderId) as $doc) {
                $files[] = $this->fileVm($doc, $svc, $selectedDoc);
                if ($selectedDoc !== null && (int) $doc->getId() === $selectedDoc) {
                    $preview = $this->previewVm($doc, $svc);
                }
            }
        }

        return [
            'roots'            => $roots,
            'rootCount'        => 0, // the drive root stores no documents (ADR-021)
            'rootActive'       => $selectedFolderId !== null && $selectedFolderId === $breadcrumbRootId,
            'rootLabel'        => ($breadcrumbRootId !== null && isset($index[$breadcrumbRootId])) ? $index[$breadcrumbRootId]->getName() : 'drive',
            'rootFolderId'     => $breadcrumbRootId,
            'crumbs'           => $crumbs,
            'selectedFolderId' => $selectedFolderId,
            'selectedDoc'      => $selectedDoc,
            'files'            => $files,
            'preview'          => $preview,
            'base'             => $this->groupBase(),
            'tplNs'            => self::DMS_NS,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fileVm(Document $doc, DocumentService $svc, ?int $selectedDoc): array
    {
        $kind     = $doc->getKind();
        [$thumb, $icon] = self::KIND_UI[$kind] ?? ['text', 'i-text'];
        $id       = (int) $doc->getId();
        // Variant-presence, not kind: a video with a browser-extracted poster now has an `s`
        // variant → it shows a thumbnail; a video without one stays on its icon (P4).
        $hasThumb = array_key_exists('s', $doc->getVariants());

        return [
            'id'           => $id,
            'displayName'  => $doc->getDisplayName(),
            'ext'          => strtoupper($doc->getExt()),
            'size'         => $this->humanSize($doc->getSizeBytes()),
            'thumbClass'   => $thumb,
            'icon'         => $icon,
            'thumbUrl'     => $hasThumb ? $this->groupBase() . '/document/preview?id=' . $id . '&variant=s' : null,
            'deliveryMode' => $svc->effectiveDeliveryMode($doc),
            'active'       => $doc->isActive(),
            'dimensions'   => ($doc->getWidth() && $doc->getHeight()) ? $doc->getWidth() . '×' . $doc->getHeight() : null,
            'isActive'     => $id === $selectedDoc,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function previewVm(Document $doc, DocumentService $svc): array
    {
        $kind     = $doc->getKind();
        [, $icon] = self::KIND_UI[$kind] ?? ['text', 'i-text'];
        $id       = (int) $doc->getId();
        // Show the largest available derivative regardless of kind — a video with a poster
        // has usable variants too (P4); no variant → the icon fallback stays.
        $variant  = null;
        foreach (['xl', 'l', 'm', 's'] as $v) {
            if (array_key_exists($v, $doc->getVariants())) { $variant = $v; break; }
        }
        $imageUrl = $variant !== null
            ? $this->groupBase() . '/document/preview?id=' . $id . '&variant=' . $variant
            : null;

        return [
            'id'           => $id,
            'displayName'  => $doc->getDisplayName(),
            'icon'         => $icon,
            'imageUrl'     => $imageUrl,
            'kind'         => $kind,
            'ext'          => strtoupper($doc->getExt()),
            'size'         => $this->humanSize($doc->getSizeBytes()),
            'dimensions'   => ($doc->getWidth() && $doc->getHeight()) ? $doc->getWidth() . ' × ' . $doc->getHeight() . ' px' : null,
            'deliveryMode' => $svc->effectiveDeliveryMode($doc),
            'active'       => $doc->isActive(),
            'previewUrl'   => $this->groupBase() . '/document/preview?id=' . $id,
            'downloadUrl'  => $this->groupBase() . '/document/download?id=' . $id,
        ];
    }

    private function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $n = (float) $bytes;
        while ($n >= 1024 && $i < count($units) - 1) { $n /= 1024; $i++; }
        return ($i === 0 ? (string) $bytes : number_format($n, 1)) . ' ' . $units[$i];
    }
}
