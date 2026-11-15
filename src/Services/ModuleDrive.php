<?php

namespace Z77\Module\Dms\Services;

use Z77\Module\Dms\Blob\BlobStorage;
use Z77\Module\Dms\Entities\AccessControlEntry;
use Z77\Module\Dms\Entities\Document;
use Z77\Module\Dms\Entities\Folder;
use Z77\Module\Dms\Repositories\AccessControlEntryRepository;
use Z77\Module\Dms\Repositories\FolderRepository;
use Z77\Module\Dms\ValueObjects\ActivityEvent;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Libraries\Convention\Naming;

/**
 * A module's handle on the DMS (ADR-021 rule 4): scoped to the module's target
 * partition, resolved ONCE via `dmsConfig['moduleFolders']` (config remaps module key →
 * partition key; no entry = the module's own key) by {@see DocumentService::forModule()}.
 *
 * The subtree boundary is enforced HERE, in the domain (same rationale as R-authz-1 — a
 * caller-side check is bypassable): a module may create subfolders BELOW its target and
 * write into them, but never above or outside. Every write asserts the target folder lies
 * inside the module's subtree and throws otherwise. Like `saveGenerated`, this is the
 * trusted system path — no session principal (a cron/module context has none); the
 * boundary is structural, not ACL.
 *
 * The one READ ({@see read()}) keeps the same boundary: a module gets back the bytes of a
 * document it stored in its own partition, nothing else.
 */
final class ModuleDrive
{
    /** @internal built by {@see DocumentService::forModule()} — the key resolution lives there */
    public function __construct(
        private DocumentService $documents,
        private FolderRepository $folders,
        private UnifiedEntityManager $em,
        private AccessControlEntryRepository $aces,
        private BlobStorage $blob,
        private Folder $root,
        private bool $created = false,
    ) {}

    /** The module's target partition (the subtree top — every write stays below it). */
    public function root(): Folder
    {
        return $this->root;
    }

    /**
     * Whether the partition was created by the {@see DocumentService::forModule()} call that
     * built this handle. A module sets up what is NEW (e.g. {@see ensurePublic()} for its fresh
     * partition) and leaves an existing one as people have set it since.
     */
    public function created(): bool
    {
        return $this->created;
    }

    /**
     * Make the module's partition deliver PUBLICLY (`deliveryMode = public` on the partition
     * root, inherited by its subtree) — for a module whose documents are web content (z77.ch
     * «Projekte»: its images are shown on the public site). Idempotent: a partition that is
     * already `public` is left alone, nothing is written. An explicit act of the MODULE, never a
     * side effect: the media picker refuses a non-public document and never switches a folder
     * (bauplan `media-picker-bauplan.md` decision 4) — switching would publish every other
     * document below it.
     *
     * A partition someone `sealed` stays sealed: opening a vault is a person's decision in the
     * Drive, not a module's (ADR-017, the sealed cap). Trusted system path like the rest of this
     * handle — no session principal, the boundary is the module's own partition. The `/media`
     * copies of the subtree are invalidated after the flush (a gate change, DMS-MAT-001).
     *
     * @throws \RuntimeException when the partition is sealed
     */
    public function ensurePublic(): Folder
    {
        $root = $this->folders->find((int) $this->root->getId()) ?? $this->root;
        if ($root->getDeliveryMode() === 'public') {
            return $this->root = $root;
        }
        if ($root->getDeliveryMode() === 'sealed') {
            throw new \RuntimeException(
                "ModuleDrive: partition '{$root->getKey()}' is sealed — a module never opens a sealed partition; "
                . 'that is a decision in the Drive (ADR-017).'
            );
        }

        $root->setDeliveryMode('public');
        $this->em->persist($root);
        $this->em->flush();
        $this->documents->invalidateMaterializedFolder((int) $root->getId()); // AFTER the flush: a gate change

        return $this->root = $root;
    }

    /**
     * Give a ROLE a right on the module's partition root (inherited by its subtree) — for a
     * module whose partition is worked on by people who are not super users, e.g. the content
     * images: editors choose and upload them in the media picker (owner decision 2026-11-11).
     * Without a grant a fresh partition is visible to a super user only (ADR-021: the ACL
     * bypass is `superUser` alone).
     *
     * Role ACEs match by exact name (`AclService`), not by the role ladder: a grant to
     * `editor` does NOT cover `admin` — grant each role that needs the right.
     *
     * Idempotent and NEVER lowers: an existing ACE with an equal or higher right stays, a
     * lower one is raised. Like {@see ensurePublic()} a module calls it for what it set up
     * itself ({@see created()}), never to re-decide grants a person changed in the Drive.
     * Trusted system path (no session principal) — the boundary is the module's own
     * partition, the same as `DocumentService::grant()` without its SUPER_USER gate.
     *
     * @param 'read'|'write'|'manage' $rights
     */
    public function grantRole(string $role, string $rights): AccessControlEntry
    {
        $ladder = ['read' => 1, 'write' => 2, 'manage' => 3];
        if (!isset($ladder[$rights])) {
            throw new \InvalidArgumentException("ModuleDrive: invalid ACL right '{$rights}'.");
        }
        $role = trim($role);
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9._-]{0,99}$/', $role) !== 1) {
            throw new \InvalidArgumentException("ModuleDrive: invalid role name '{$role}'.");
        }

        $rootId = (int) $this->root->getId();
        foreach ($this->aces->findByResource('folder', $rootId) as $ace) {
            if ($ace->getSubjectType() === 'role' && $ace->getSubjectId() === $role) {
                if (($ladder[$ace->getRights()] ?? 0) >= $ladder[$rights]) {
                    return $ace;
                }
                $ace->setRights($rights);
                $this->em->persist($ace);
                $this->em->flush();

                return $ace;
            }
        }

        $ace = new AccessControlEntry();
        $ace->setResourceType('folder');
        $ace->setResourceId($rootId);
        $ace->setSubjectType('role');
        $ace->setSubjectId($role);
        $ace->setRights($rights);
        $ace->setCreatedBy(null);
        $ace->setCreatedAt(gmdate('c'));
        $this->em->persist($ace);
        $this->em->flush();

        return $ace;
    }

    /**
     * Get-or-create a subfolder path below the module's target, e.g.
     * `folder('Invoices', '2026')`. Matches existing children by name; creates missing
     * links (system path, ungated — the boundary is the subtree itself). Returns the
     * deepest folder.
     */
    public function folder(string ...$names): Folder
    {
        $parent = $this->root;
        foreach ($names as $name) {
            $name = trim($name);
            if ($name === '') {
                throw new \InvalidArgumentException('ModuleDrive: a folder name must not be empty.');
            }
            $parent = $this->childByName($parent, $name) ?? $this->createChild($parent, $name);
        }

        return $parent;
    }

    /**
     * Persist a module-generated file (e.g. an invoice PDF) inside the module's subtree.
     * The `SaveRequest.folderId` MUST point into the subtree — resolve it via
     * {@see root()} / {@see folder()}; anything outside (or missing) throws.
     */
    public function saveGenerated(string $bytes, SaveRequest $req): Document
    {
        $this->assertWithin($req->folderId);

        return $this->documents->saveGenerated($bytes, $req);
    }

    /**
     * Set the legal retention deadline (`retentionUntil`, ISO date) of documents the module
     * stored — e.g. a receipt photo once its receipt is posted to the ledger (Swiss OR 958f:
     * 10 years). A running deadline blocks the purge and an overwrite ({@see DocumentService::purge()},
     * `UploadService`); a soft-delete stays possible (restorable, bytes kept).
     *
     * Server-side only: `retentionUntil` is a server-controlled field (no `#[Clean]`) and this is the
     * one write path a module has for it. It only EXTENDS — a document whose deadline is already the
     * same or later is left alone, so the call is idempotent and safe to repeat (a backfill). A
     * soft-deleted document is covered too (it may still be restored, its purge must wait). One flush
     * for the whole batch. Unknown documents and documents outside the module's partition (moved away
     * in the Drive, or a `moduleFolders` remap since) are NOT written and come back in `skipped` — a
     * module never sets a deadline on a document outside its subtree (ADR-021 rule 4).
     *
     * @param array<int, string> $untilById document id => deadline `YYYY-MM-DD`
     * @return array{retained: int, skipped: list<int>} written documents; ids not written (unknown or outside)
     * @throws \InvalidArgumentException a deadline that is not a valid `YYYY-MM-DD` date
     */
    public function retainUntil(array $untilById): array
    {
        foreach ($untilById as $id => $until) {
            $parts = explode('-', (string) $until);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $until) !== 1 || !checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
                throw new \InvalidArgumentException("ModuleDrive: retention deadline '{$until}' of document {$id} is not a YYYY-MM-DD date.");
            }
        }

        $documents = $this->em->getRepository(Document::class);
        $retained  = 0;
        $skipped   = [];
        foreach ($untilById as $id => $until) {
            $doc = $documents->find((int) $id);
            if (!$doc instanceof Document || !$this->isWithin($doc->getFolderId())) {
                $skipped[] = (int) $id;
                continue;
            }
            $current = $doc->getRetentionUntil();
            if ($current !== null && substr($current, 0, 10) >= $until) {
                continue;   // already kept as long or longer — never shortened
            }
            $doc->setRetentionUntil($until);
            $this->em->persist($doc);
            $retained++;
        }
        if ($retained > 0) {
            $this->em->flush();
        }

        return ['retained' => $retained, 'skipped' => $skipped];
    }

    /**
     * The ORIGINAL bytes of a live document in the module's partition — for a module that works
     * on a file it stored itself, later and outside the request that stored it (module-creditor's
     * job `creditor-intake` reads the supplier PDF an upload put here; creditor.md
     * CREDITOR-RECOG-WEB-001).
     *
     * Null, never an exception, when the module may not or cannot read it: an unknown id, a
     * document in the trash, one OUTSIDE the module's partition (another module's, a person's, one
     * moved away in the Drive, a `moduleFolders` remap since), missing bytes, or bytes that no
     * longer match the stored checksum. The boundary is the write boundary ({@see isWithin()}); no
     * session principal and no ACL — a module reads what it stored, never on a person's behalf, and
     * hands nothing to a browser. Not reported to the activity hook (DMS-ACTIVITY-001, «left out on
     * purpose»): no person receives the original.
     */
    public function read(int $documentId): ?string
    {
        $doc = $documentId > 0 ? $this->em->getRepository(Document::class)->find($documentId) : null;
        if (!$doc instanceof Document || $doc->isDeleted() || !$this->isWithin($doc->getFolderId())) {
            return null;
        }
        $bytes = $this->blob->get((int) $doc->getId(), BlobStorage::ORIGINAL, $doc->getExt());
        if ($bytes === null || !hash_equals(strtolower($doc->getChecksum()), hash('sha256', $bytes))) {
            return null;
        }

        return $bytes;
    }

    /**
     * The write boundary (ADR-021 rule 4): the folder must lie inside the module's
     * subtree — the module's partition or below, NEVER above or in a foreign partition.
     */
    private function assertWithin(?int $folderId): void
    {
        if ($this->isWithin($folderId)) {
            return;
        }

        throw new \RuntimeException(
            "ModuleDrive: folder {$folderId} is outside the module's partition '{$this->root->getKey()}' — "
            . 'a module must never write above or outside its configured target (ADR-021).'
        );
    }

    /** Whether the folder is the module's partition or lies below it. */
    private function isWithin(?int $folderId): bool
    {
        $cur   = $folderId;
        $guard = 50;
        while ($cur !== null && $guard-- > 0) {
            if ($cur === $this->root->getId()) {
                return true;
            }
            $folder = $this->folders->find($cur);
            if (!$folder instanceof Folder) {
                return false;
            }
            $cur = $folder->getParentId();
        }

        return false;
    }

    private function childByName(Folder $parent, string $name): ?Folder
    {
        foreach ($this->folders->findBy(['parent_id' => $parent->getId()]) as $child) {
            if ($child->getName() === $name) {
                return $child;
            }
        }

        return null;
    }

    /** Create a child folder (sibling-unique slug, same `-2`/`-3`… scheme as FolderService). */
    private function createChild(Folder $parent, string $name): Folder
    {
        $folder = new Folder();
        $folder->setName($name);
        $folder->setParentId($parent->getId());
        $folder->setSystem(false); // a normal folder — only partitions carry the system lock
        $folder->setOwnerId(null); // module/system-owned (ADR-017 convention)
        $folder->setSlug($this->uniqueSlug($parent, $name));
        $folder->setSortKey($this->nextSortKey($parent));
        $this->em->persist($folder);
        $this->em->flush();
        ActivityLog::folder(ActivityEvent::FOLDER_CREATED, (int) $folder->getId(), $parent->getId());

        return $folder;
    }

    private function uniqueSlug(Folder $parent, string $name): string
    {
        $base = Naming::toSlug($name);
        if ($base === '') {
            $base = 'ordner';
        }
        $taken = [];
        foreach ($this->folders->findBy(['parent_id' => $parent->getId()]) as $sibling) {
            $taken[$sibling->getSlug()] = true;
        }
        $slug = $base;
        $n    = 2;
        while (isset($taken[$slug])) {
            $slug = $base . '-' . $n++;
        }

        return $slug;
    }

    private function nextSortKey(Folder $parent): int
    {
        $max = -1;
        foreach ($this->folders->findBy(['parent_id' => $parent->getId()]) as $sibling) {
            $max = max($max, $sibling->getSortKey());
        }

        return $max + 1;
    }
}
