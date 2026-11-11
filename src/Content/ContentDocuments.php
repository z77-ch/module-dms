<?php

namespace Z77\Module\Dms\Content;

use Z77\Core\Config\AuthRole;
use Z77\Module\Dms\Entities\Document;
use Z77\Module\Dms\Services\Authz;
use Z77\Module\Dms\Services\DocumentService;
use Z77\Module\Dms\Ui\MediaField;
use Z77\Shared\Content\DocumentReferences;

/**
 * The DMS behind the content editor's `document` fields (media-picker bauplan P2b), declared in
 * `dmsConfig['contentDocuments']` and folded in by `ContentExtensions::assemble()`.
 *
 *   - {@see refusal()}: the server's check of a CHANGED document id on save — it must name a
 *     live document the session may read, of the field's `accept` kind, and (unless the
 *     descriptor says `public => false`) one `/media` delivers openly: a content page is
 *     public, a protected image would silently show nothing there. The picker's rule
 *     ({@see MediaField}), asked again — the picker is the convenience, this is the gate.
 *   - {@see pickerRoot()}: the partition of content images — the module key {@see MODULE_KEY}
 *     through `DocumentService::forModule()`, so a project points it at another partition
 *     with the ONE existing mechanism, `dmsConfig['moduleFolders']` (`'content' => 'web'`).
 *     A partition this call CREATES is made public ({@see \Z77\Module\Dms\Services\ModuleDrive::ensurePublic()});
 *     an existing one stays as people have set it — never switched implicitly (bauplan
 *     decision 4).
 */
final class ContentDocuments implements DocumentReferences
{
    /** The module key of content images in the DMS (a code constant, S2) — remappable via `moduleFolders`. */
    public const MODULE_KEY = 'content';

    /** The Drive's name of the partition when it is created here. */
    private const PARTITION_NAME = 'Inhalte';

    private ?string $root = null;

    public function refusal(int $id, array $field): ?string
    {
        $docs = DocumentService::create();
        $doc  = $docs->get($id);
        if (!$doc instanceof Document || !Authz::create()->allows('document', $id, 'read')) {
            return 'nennt kein Dokument, das es gibt (Nr. ' . $id . ') — bitte neu wählen.';
        }
        $accept = is_string($field['accept'] ?? null) ? $field['accept'] : '';
        if (!MediaField::accepts($accept, $doc->getKind())) {
            return 'Nr. ' . $id . ' ist ' . ($accept === 'image' ? 'kein Bild' : 'nicht von der passenden Art') . ' — bitte neu wählen.';
        }
        if (($field['public'] ?? true) !== false && ($why = MediaField::publicRefusal($doc, $docs)) !== null) {
            return 'Nr. ' . $id . ': ' . $why . ' — hier sind nur öffentlich ausgelieferte Dokumente wählbar.';
        }

        return null;
    }

    public function pickerRoot(): string
    {
        if ($this->root === null) {
            $drive = DocumentService::create()->forModule(self::MODULE_KEY, self::PARTITION_NAME);
            if ($drive->created()) {
                $drive->ensurePublic();
                // Who works on content images (owner decision 2026-11-11): editors choose and
                // upload, an admin also renames and deletes. Role ACEs match by exact name, so
                // each role is granted on its own. Set up once, like the public mode — a
                // person's later change in the Drive stays.
                $drive->grantRole(AuthRole::EDITOR, 'write');
                $drive->grantRole(AuthRole::ADMIN, 'manage');
            }
            $this->root = (string) $drive->root()->getId();
        }

        return $this->root;
    }
}
