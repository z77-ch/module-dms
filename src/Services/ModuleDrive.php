<?php

namespace Z77\Module\Dms\Services;

use Z77\Module\Dms\Entities\Document;
use Z77\Module\Dms\Entities\Folder;
use Z77\Module\Dms\Repositories\FolderRepository;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Libraries\Convention\Naming;

/**
 * A module's write handle on the DMS (ADR-021 rule 4): scoped to the module's target
 * partition, resolved ONCE via `dmsConfig['moduleFolders']` (config remaps module key →
 * partition key; no entry = the module's own key) by {@see DocumentService::forModule()}.
 *
 * The subtree boundary is enforced HERE, in the domain (same rationale as R-authz-1 — a
 * caller-side check is bypassable): a module may create subfolders BELOW its target and
 * write into them, but never above or outside. Every write asserts the target folder lies
 * inside the module's subtree and throws otherwise. Like `saveGenerated`, this is the
 * trusted system path — no session principal (a cron/module context has none); the
 * boundary is structural, not ACL.
 */
final class ModuleDrive
{
    /** @internal built by {@see DocumentService::forModule()} — the key resolution lives there */
    public function __construct(
        private DocumentService $documents,
        private FolderRepository $folders,
        private UnifiedEntityManager $em,
        private Folder $root,
    ) {}

    /** The module's target partition (the subtree top — every write stays below it). */
    public function root(): Folder
    {
        return $this->root;
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
     * The write boundary (ADR-021 rule 4): the folder must lie inside the module's
     * subtree — the module's partition or below, NEVER above or in a foreign partition.
     */
    private function assertWithin(?int $folderId): void
    {
        $cur   = $folderId;
        $guard = 50;
        while ($cur !== null && $guard-- > 0) {
            if ($cur === $this->root->getId()) {
                return;
            }
            $folder = $this->folders->find($cur);
            if (!$folder instanceof Folder) {
                break;
            }
            $cur = $folder->getParentId();
        }

        throw new \RuntimeException(
            "ModuleDrive: folder {$folderId} is outside the module's partition '{$this->root->getKey()}' — "
            . 'a module must never write above or outside its configured target (ADR-021).'
        );
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
