<?php
namespace Z77\Module\Dms\Ui;

use Z77\Core\DI,
    Z77\Core\Http\RequestMode,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Core\Http\WindowOrigin,
    Z77\Core\Services\TemplateRenderer,
    Z77\Shared\Attributes\Csrf,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod,
    Z77\Shared\Upload\UploadPolicy,
    Z77\Module\Dms\Entities\Document,
    Z77\Module\Dms\Entities\Folder,
    Z77\Module\Dms\Services\Authz,
    Z77\Module\Dms\Services\UploadService
;

/**
 * The media picker (bauplan `media-picker-bauplan.md` P1, decisions 2–4): the window behind a
 * media field's «Auswählen …» ({@see MediaField}, `partials/mediaField`). Mounted NEXT TO
 * {@see DriveControllerTrait} in the same host controller (it uses the Drive's readable-folder,
 * tree and upload helpers) — the backend's `Documents/DriveController`, so the actions live at
 * `/backend/documents/drive/pick` and `/pick-upload`.
 *
 *   - GET  `pick` — the window (fetched; mask `dms-pick`, entity `media-field:<target>`), else a
 *          page: the folder tree below `root`, the shown folder's grid (`accept`), the upload
 *          into the shown folder. Parameters: `target` (the field's DOM id), `root` (a folder id
 *          or a partition key / slug — FIND-only), `accept` (`image` | ''), `public` (1 = only
 *          documents delivered openly may be chosen), `folder` (the shown folder);
 *   - POST `pick` `doc=<id>` — the choice, judged again (readable, `accept`, inside `root`,
 *          `public`). Fetched: `close-modal` (this window) and the commands that fill the field
 *          `#<target>` in the ORIGIN the picker was opened from (ADR-047 part 2);
 *   - POST `pick-upload` — one file of the upload component, through the USER path
 *          ({@see UploadService::save()}: session `write`, sniffed allowlist, `createdBy`), never
 *          `saveGenerated`. With `public=1` only into a folder that delivers publicly — a pick
 *          never switches a folder public (decision 4).
 *
 * Every read is gated in the domain as everywhere in the Drive (RF-4a): a folder or document the
 * session may not `read` is not listed, and a crafted id gets «nicht gefunden». The `root` is
 * PRESENTATION (like the Drive's `mountRoot`), applied again on the choice and the upload so the
 * picker answers only for what it shows — the protection is the ACL, and the host's save judges
 * the stored id again.
 */
trait MediaPickerTrait
{
    /**
     * The picker window, and (POST) the choice.
     */
    #[Csrf]
    protected function pickAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $fetch   = $request->getMode() === RequestMode::Fetch;
        $origin  = WindowOrigin::of($request);
        $state   = $this->pickState();
        if ($state === null) {
            return $this->pickRefuse($fetch, 'Unbekannte Auswahl (accept).', null);
        }
        $rootId = $this->pickRootId($state['root']);
        if ($rootId === false) {
            return $this->pickRefuse($fetch, 'Ablageort nicht gefunden.', null);
        }
        $pickUrl = $this->groupBase() . '/drive/pick';
        $keep    = array_filter([
            'target'  => $state['target'],
            'root'    => $state['root'],
            'accept'  => $state['accept'],
            'public'  => $state['public'] ? '1' : '',
            '_origin' => $origin === WindowOrigin::PAGE ? '' : $origin,
        ], static fn(string $v): bool => $v !== '');

        if ($request->isPost()) {
            return $this->pickChoose($fetch, $state, $rootId, $origin, $pickUrl, $keep);
        }

        $response = $this->html($this->pickVm($state, $rootId, $fetch, $pickUrl, $keep)
            + ['origin' => $origin, 'activeSection' => 'backend-main']);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials('_pick', 'Documents/DriveController', MediaField::NS);
        // The window's content arrived after the page's own bind ran: bind its upload (UPLOAD-001).
        $response->addCommand('bind-upload', ['selector' => '#' . $this->pickDomId($state['target'])]);

        return $response;
    }

    /**
     * One file of the picker's upload (THE upload component, one request per file). Same per-file
     * envelope as the Drive's {@see DriveControllerTrait::uploadAction()} — through the same
     * {@see DriveControllerTrait::storeUpload()}, i.e. {@see UploadService::save()} with the
     * session's user as `createdBy` — and on success the grid of the folder, the new document
     * marked (the component runs it once, after the last file).
     */
    #[Fetch, HttpMethod('POST')]
    protected function pickUploadAction(): FetchResponse
    {
        $request = DI::getRequest();
        $files   = $request->getUploadedFiles('files');
        if ($files === []) {
            return $this->fetchError('Keine Datei empfangen.');
        }
        $file    = $files[0]; // per-file endpoint: exactly one file per request
        $refuse  = fn(string $message): FetchResponse
            => $this->fetch()->setStatus('error')->setData(['name' => $file->originalName, 'message' => $message]);

        $state = $this->pickState();
        if ($state === null) {
            return $refuse('Unbekannte Auswahl (accept).');
        }
        $rootId   = $this->pickRootId($state['root']);
        $folderId = (int) $request->getPostParameter('folder_id');
        $folder   = $folderId > 0 ? $this->readableFolder($folderId) : null;
        if ($rootId === false || $folder === null || !$this->pickWithin($folderId, $rootId)) {
            return $refuse('Zielordner nicht gefunden.');
        }
        $why = $this->pickUploadRefusal($folder, $state['public']);
        if ($why !== null) {
            return $refuse($why);
        }
        $message = $this->pickUploadPolicy($state['accept'])->check($file);
        if ($message !== null) {
            return $refuse($message);
        }

        return $this->storeUpload(
            $file,
            $folderId,
            false,
            function (Document $doc) use ($state, $folder, $rootId): FetchResponse {
                $grid = (new TemplateRenderer(MediaField::NS))->partial('Documents/DriveController/_pickGrid', [
                    'items'  => $this->pickItems($folder, $state['accept'], $state['public']),
                    'formId' => $this->pickFormId($state['target']),
                    'folder' => ['id' => (int) $folder->getId()],
                    'accept' => $state['accept'],
                    'fresh'  => (int) $doc->getId(),
                ]);
                $scope = '#' . $this->pickDomId($state['target']);

                return $this->fetch()
                    ->addCommand('replace-html', ['target' => $scope . ' [data-dms-pick-grid]', 'html' => $grid])
                    ->addCommand('scroll-to', ['target' => $scope . ' [data-dms-pick-fresh]']);
            },
        );
    }

    // ── the choice ──────────────────────────────────────────────────────────────

    /**
     * @param array{target: string, root: string, accept: string, public: bool} $state
     * @param array<string, string> $keep
     */
    private function pickChoose(bool $fetch, array $state, ?int $rootId, string $origin, string $pickUrl, array $keep): FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $id      = (int) $request->getPostParameter('doc');
        $doc     = $this->readableDoc($id);
        $back    = MediaField::url($pickUrl, $keep + ['folder' => (int) $request->getPostParameter('folder')]);

        if ($doc === null || !MediaField::accepts($state['accept'], $doc->getKind()) || !$this->pickWithin($doc->getFolderId(), $rootId)) {
            return $this->pickRefuse($fetch, 'Dieses Dokument gibt es hier nicht (mehr) — bitte neu wählen.', $back);
        }
        if ($state['public'] && ($why = MediaField::publicRefusal($doc, $this->docService())) !== null) {
            return $this->pickRefuse($fetch, $why . ' — hier sind nur öffentlich ausgelieferte Dokumente wählbar.', $back);
        }
        if (!$fetch) {
            // Without the script the form behind cannot be filled from here: the number is shown.
            $this->messageService->pushFlashAfterRedirect('success', '«' . $doc->getDisplayName() . '» hat die Nummer ' . $id . '.');

            return $this->redirect($back, 303);
        }
        if ($state['target'] === '') {
            return $this->fetchError('Kein Feld zum Füllen — die Auswahl wurde ohne Feld geöffnet.');
        }

        // This window closes first (its context), then the field in the ORIGIN takes the value —
        // `set-value` fires `change`, so the host's form sees the choice as an input —, its
        // thumbnail (the field's own partial), and the focus goes back to «Auswählen …».
        $field = '#' . $state['target'];
        $thumb = (new TemplateRenderer(MediaField::NS))->partial('partials/mediaFieldThumb', [
            'thumb' => MediaField::thumb($id, $this->groupBase()),
        ]);

        return $this->fetch()
            ->setStatus('success')
            ->setData(['id' => $id, 'name' => $doc->getDisplayName()])
            ->addCommand('close-modal')
            ->addCommand('set-value', ['target' => $field . ' [data-media-field-input]', 'value' => (string) $id, 'origin' => $origin])
            ->addCommand('replace-html', ['target' => $field . ' [data-media-field-thumb]', 'html' => $thumb, 'origin' => $origin])
            ->addCommand('focus', ['target' => $field . ' [data-media-field-pick]', 'origin' => $origin]);
    }

    /** A refusal: a flash in the fetch answer, else back to the picker page with it. */
    private function pickRefuse(bool $fetch, string $message, ?string $back): FetchResponse|RedirectResponse
    {
        if ($fetch || $back === null) {
            return $this->fetchError($message);
        }
        $this->messageService->pushFlashAfterRedirect('error', $message);

        return $this->redirect($back, 303);
    }

    // ── the window's view model ─────────────────────────────────────────────────

    /**
     * @param array{target: string, root: string, accept: string, public: bool} $state
     * @param array<string, string> $keep
     * @return array<string, mixed>
     */
    private function pickVm(array $state, ?int $rootId, bool $window, string $pickUrl, array $keep): array
    {
        $folders  = $this->folderRepo()->findAll();
        $authz    = Authz::create();
        $readable = [];
        $index    = [];
        foreach ($folders as $f) {
            if ($f->getId() !== null) {
                $readable[(int) $f->getId()] = $authz->allows('folder', (int) $f->getId(), 'read');
                $index[(int) $f->getId()]    = $f;
            }
        }

        // The shown folder: the requested one when readable and inside the root, else the root.
        $asked    = (int) DI::getRequest()->getGetParameter('folder');
        $selected = null;
        foreach ([$asked, $rootId ?? 0] as $candidate) {
            if ($candidate > 0 && ($readable[$candidate] ?? false) && $this->pickWithin($candidate, $rootId)) {
                $selected = $candidate;
                break;
            }
        }

        $activePath = [];
        $cur        = $selected;
        $guard      = 50;
        while ($cur !== null && isset($index[$cur]) && $guard-- > 0) {
            $activePath[$cur] = true;
            if ($cur === $rootId) {
                break;
            }
            $cur = $index[$cur]->getParentId();
        }

        $folder       = $selected !== null ? $index[$selected] : null;
        $uploadNote   = $folder !== null ? $this->pickUploadRefusal($folder, $state['public']) : null;
        $uploadPolicy = $folder !== null && $uploadNote === null ? $this->pickUploadPolicy($state['accept']) : null;

        return [
            'target'       => $state['target'],
            'keep'         => $keep,
            'pickUrl'      => $pickUrl,
            'rootDomId'    => $this->pickDomId($state['target']),
            'formId'       => $this->pickFormId($state['target']),
            'window'       => $window,
            'title'        => $state['accept'] === 'image' ? 'Bild auswählen' : 'Dokument auswählen',
            'nodes'        => $this->folderNodes($folders, $readable, $rootId, $selected, [], $activePath),
            'folder'       => $folder !== null ? [
                'id'   => (int) $folder->getId(),
                'name' => $folder->getName(),
                'mode' => $this->docService()->effectiveFolderDeliveryMode($folder),
            ] : null,
            'items'        => $folder !== null ? $this->pickItems($folder, $state['accept'], $state['public']) : [],
            'uploadPolicy' => $uploadPolicy,
            'uploadNote'   => $uploadNote,
            'public'       => $state['public'],
            'accept'       => $state['accept'],
            'treeNote'     => null,
        ];
    }

    /**
     * The grid of one folder: its live documents in the editor's order that the session may read
     * and that pass `accept`; with `public` the ones not delivered openly are disabled with the
     * reason.
     *
     * @return list<array<string, mixed>>
     */
    private function pickItems(Folder $folder, string $accept, bool $public): array
    {
        $docs  = $this->docService();
        $authz = Authz::create();
        $base  = $this->groupBase();
        $items = [];
        foreach ($docs->listByFolder((int) $folder->getId()) as $doc) {
            $id = (int) $doc->getId();
            if (!MediaField::accepts($accept, $doc->getKind()) || !$authz->allows('document', $id, 'read')) {
                continue;
            }
            $reason  = $public ? MediaField::publicRefusal($doc, $docs) : null;
            $items[] = [
                'id'        => $id,
                'name'      => $doc->getDisplayName(),
                'ext'       => strtoupper($doc->getExt()),
                'thumbUrl'  => MediaField::thumbUrl($doc, $base),
                'dims'      => ($doc->getWidth() && $doc->getHeight()) ? $doc->getWidth() . '×' . $doc->getHeight() : null,
                'choosable' => $reason === null,
                'reason'    => $reason,
            ];
        }

        return $items;
    }

    /**
     * Why there is no upload into $folder here, or null. Public is shown and enforced, never
     * switched (decision 4): with `public` only a folder that delivers openly takes an upload.
     */
    private function pickUploadRefusal(Folder $folder, bool $public): ?string
    {
        if ($folder->getParentId() === null) {
            return 'In den obersten Ordner kann nichts hochgeladen werden — bitte einen Unterordner wählen.';
        }
        if (!Authz::create()->allows('folder', (int) $folder->getId(), 'write')) {
            return 'In diesen Ordner darfst du nichts hochladen.';
        }
        if ($public && $this->docService()->effectiveFolderDeliveryMode($folder) !== 'public') {
            return 'Dieser Ordner liefert nicht öffentlich aus — hochladen nur in einen öffentlichen Ordner. '
                . 'Einen Ordner öffentlich stellen geht im Drive (Bearbeiten → Auslieferung).';
        }

        return null;
    }

    /**
     * What the picker's upload lets in — the client's gate AND the server's check (UPLOAD-001):
     * `accept` sniffed on the server, the transport cap for every file, the memory cap for images
     * (GD decodes them), «überschreiben?» asked as in the Drive.
     */
    private function pickUploadPolicy(string $accept): UploadPolicy
    {
        $imageMax = min(UploadService::serverMaxBytes(), UploadService::effectiveMaxUploadBytes());

        return new UploadPolicy(
            endpoint:    $this->groupBase() . '/drive/pick-upload',
            field:       'files[]',
            multiple:    true,
            accept:      MediaField::uploadAccept($accept),
            maxBytes:    UploadService::serverMaxBytes(),
            onConflict:  UploadPolicy::CONFLICT_ASK,
            shape:       'drop',
            label:       $accept === 'image' ? 'Bilder hierher ziehen' : 'Dateien hierher ziehen',
            // The component's own hint would read «IMAGE/*»: what a user cannot guess, in words.
            hint:        $accept === 'image' ? 'Bilder · bis ' . UploadPolicy::formatBytes($imageMax) . ' · mehrere' : '',
            maxBytesPer: ['image/*' => UploadService::effectiveMaxUploadBytes()],
        );
    }

    // ── the request's state ─────────────────────────────────────────────────────

    /**
     * `target`, `root`, `accept`, `public` of the request — the POST first (the forms carry them
     * back), then the address. Null when `accept` is one the picker does not know.
     *
     * @return array{target: string, root: string, accept: string, public: bool}|null
     */
    private function pickState(): ?array
    {
        $request = DI::getRequest();
        $read    = static function (string $name) use ($request): mixed {
            $posted = $request->isPost() ? $request->getPostParameter($name) : null;

            return $posted ?? $request->getGetParameter($name);
        };
        $accept = MediaField::accept($read('accept') ?? '');
        if ($accept === null) {
            return null;
        }

        return [
            'target' => MediaField::target($read('target')),
            'root'   => MediaField::root($read('root')),
            'accept' => $accept,
            'public' => (string) ($read('public') ?? '') === '1',
        ];
    }

    /**
     * The root folder id: null = the whole tree, false = named but not found. FIND-only — a
     * folder id, else a partition by key, else by slug; never `rootFolder()`, which would CREATE
     * a partition for a crafted name (S2).
     */
    private function pickRootId(string $root): int|false|null
    {
        if ($root === '') {
            return null;
        }
        $folder = ctype_digit($root)
            ? $this->folderRepo()->find((int) $root)
            : ($this->folderRepo()->findRootByKey($root) ?? $this->folderRepo()->findRootBySlug($root));

        return $folder instanceof Folder ? (int) $folder->getId() : false;
    }

    /** Whether folder $folderId is $rootId or lies below it (always true without a root). */
    private function pickWithin(?int $folderId, ?int $rootId): bool
    {
        if ($rootId === null) {
            return $folderId !== null;
        }
        $cur   = $folderId;
        $guard = 50;
        while ($cur !== null && $guard-- > 0) {
            if ($cur === $rootId) {
                return true;
            }
            $cur = $this->folderRepo()->find($cur)?->getParentId();
        }

        return false;
    }

    /** Id of the picker content's root — the upload's bind scope and the grid's address. */
    private function pickDomId(string $target): string
    {
        return 'dms-pick-' . ($target !== '' ? $target : 'browse');
    }

    private function pickFormId(string $target): string
    {
        return $this->pickDomId($target) . '-choose';
    }
}
