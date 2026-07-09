<?php

namespace Z77\Module\Dms\Repositories;

use Z77\Persistence\File\Repository\FileRepository;
use Z77\Module\Dms\Entities\Folder;

/**
 * Folder metadata access. The tree algorithms (sort, children, move) live in
 * `TreeService` over the full set (see tree.md); this repository only loads/filters.
 * Scope is the tree itself (ADR-020/021): ONE drive root at the top, its children are
 * the partitions. All lookups here are find-only — creation (get-or-create + stray
 * adoption) is `DocumentService::driveRoot()` / `rootFolder()`.
 */
class FolderRepository extends FileRepository
{
    /**
     * The single drive root (ADR-021): the mandatory top-level folder. Null only before
     * `DocumentService::driveRoot()` first ran (fresh install without the seed).
     * Deterministic on corrupt multi-top data: the smallest id wins (S3).
     */
    public function findDriveRoot(): ?Folder
    {
        $tops = $this->findBy(['parent_id' => null]);
        usort($tops, fn(Folder $a, Folder $b) => ($a->getId() ?? 0) <=> ($b->getId() ?? 0));

        // Prefer the folder actually carrying the reserved key (self-heals a state where
        // a stray top-level folder has a smaller id than the seeded root).
        foreach ($tops as $top) {
            if ($top->getKey() === Folder::DRIVE_KEY) {
                return $top;
            }
        }

        return $tops[0] ?? null;
    }

    /**
     * The partitions: every direct child of the drive root (ADR-021), sorted by id so
     * key lookups are deterministic (S3 — the File driver cannot enforce uniqueness; on
     * a duplicate key the smallest id wins). Empty when no drive root exists yet.
     *
     * @return Folder[]
     */
    public function findRoots(): array
    {
        $drive = $this->findDriveRoot();
        if ($drive === null) {
            return [];
        }

        $roots = $this->findBy(['parent_id' => $drive->getId()]);
        usort($roots, fn(Folder $a, Folder $b) => ($a->getId() ?? 0) <=> ($b->getId() ?? 0));

        return $roots;
    }

    /**
     * The partition addressed by a module `key`, or null. Deterministic on a
     * (theoretically possible) duplicate: the smallest id wins (S3). The drive root
     * itself is never returned (it is not a partition).
     */
    public function findRootByKey(string $key): ?Folder
    {
        foreach ($this->findRoots() as $root) {
            if ($root->getKey() === $key) {
                return $root;
            }
        }

        return null;
    }

    /**
     * The partition addressed by its slug (the top segment of its public `/media` URL), or
     * null. Partition slugs are unique among partitions (`uniqueRootSlug`); on a theoretical
     * duplicate the smallest id wins (S3). Unlike {@see findRootByKey} (a code-declared module
     * key that MUST NOT come from request input, S2), the slug is set through the normal folder
     * create/rename flow — so a human-created partition (`key = null`) is addressable without a key.
     */
    public function findRootBySlug(string $slug): ?Folder
    {
        foreach ($this->findRoots() as $root) {
            if ($root->getSlug() === $slug) {
                return $root;
            }
        }

        return null;
    }
}
