<?php

namespace Z77\Module\Dms\Services;

use Z77\Core\DI;
use Z77\Core\Exception\NotFoundException;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Module\Dms\Entities\Folder;
use Z77\Module\Dms\Images\ImageProfileRegistry;
use Z77\Module\Dms\Repositories\FolderRepository;
use Z77\Shared\Libraries\Convention\Naming;
use Z77\Shared\Tree\TreeService;

/**
 * Folder domain operations for the DMS (create / rename / move / delete) plus the
 * invariants that go with them: URL-safe sibling-unique slug, the empty/system delete
 * guard, and the reparent cycle guard. The folder rules live in the domain, not the
 * surface (ADR-019) — the counterpart of {@see DocumentService} for the folder tree.
 *
 * Scope is the tree itself (ADR-020/021): ONE drive root at the top, its children are
 * the partitions. The partition LIFECYCLE (create/rename/move/delete — by `add`/`move`
 * with a `null`/root parent or on a partition itself) is SUPER_USER-only (ADR-021 / D5);
 * below a partition the normal ACL rights gate. The drive root itself is always locked
 * (system); module-declared partitions (`system = true`, carrying a `key`) are
 * rename/move/delete-locked (S4): their slug is the top segment of every public URL of
 * the partition and their key is the module's address.
 *
 * Mutating methods validate and throw (`InvalidArgumentException` for bad input,
 * `NotFoundException` for a missing folder, `RuntimeException` for a blocked delete);
 * the caller surfaces the message. Rename/move re-materialize the public tree (a
 * slug/parent change moves descendant `/media` paths); create/delete do not (a new
 * folder is empty, delete is only allowed on empty folders).
 */
final class FolderService
{
    private const NAME_MAX = 120;

    private ?TreeService $tree = null;

    public function __construct(
        private FolderRepository $folders,
        private UnifiedEntityManager $em,
        private DocumentService $documents,
        private Authz $authz,
    ) {}

    public static function create(): self
    {
        $uem = DI::getUnifiedEntityManager();

        return new self(
            $uem->getRepository(Folder::class),
            $uem,
            DocumentService::create(),
            Authz::create(),
        );
    }

    /** Create a folder under $parentId (null or the drive root = a new partition, SUPER_USER only). */
    public function add(?int $parentId, string $name): Folder
    {
        // `null` means "under the drive root" (there is no other top, ADR-021).
        $driveId = (int) $this->documents->driveRoot()->getId();
        $parentId ??= $driveId;

        // A child of the drive root = a new partition (ADR-020/021) → SUPER_USER only.
        // A subfolder needs write on the parent.
        if ($parentId === $driveId) {
            $this->authz->requireSuperUser();
        } else {
            $this->authz->require('folder', $parentId, 'write');
        }
        $this->assertName($name);
        if (!$this->folders->find($parentId) instanceof Folder) {
            throw new \InvalidArgumentException('Ziel-Ordner nicht gefunden.');
        }

        $folder = new Folder();
        $folder->setName($name);
        $folder->setParentId($parentId);
        $folder->setSystem(false);
        $folder->setSortKey($this->treeService()->nextSortKey($this->folders->findAll(), $folder));
        $folder->setSlug($this->uniqueSlug($folder));
        $this->em->persist($folder);
        $this->em->flush();

        return $folder;
    }

    /** Rename a folder (name + re-slug); re-materializes descendant public paths. */
    public function rename(int $id, string $name): Folder
    {
        $this->authz->require('folder', $id, 'manage');
        $folder = $this->require($id);
        $this->assertSystemRootUnlocked($folder, 'umbenannt');
        $this->requirePartitionGate($folder); // partition lifecycle = SUPER_USER (ADR-021/D5)
        $this->assertName($name);

        $folder->setName($name);
        $folder->setSlug($this->uniqueSlug($folder));
        $this->em->persist($folder);
        $this->em->flush();
        $this->documents->rebuildMaterialization(); // slug change → descendant media paths

        return $folder;
    }

    /**
     * Move a folder under $targetId; re-materializes descendant public paths.
     * Moving to the top (`null`) creates a new partition root → Super-User only.
     */
    public function move(int $id, ?int $targetId): Folder
    {
        $this->authz->require('folder', $id, 'manage');
        $folder = $this->require($id);
        $this->assertSystemRootUnlocked($folder, 'verschoben');
        $this->requirePartitionGate($folder); // demoting a partition = SUPER_USER (ADR-021/D5)

        // `null` means "under the drive root" (there is no other top, ADR-021).
        $driveId = (int) $this->documents->driveRoot()->getId();
        $targetId ??= $driveId;

        if ($targetId === $driveId) {
            // Promoting a folder to a child of the drive root creates a partition
            // (ADR-020/021) — the same privilege as creating one.
            $this->authz->requireSuperUser();
        } else {
            if (!$this->folders->find($targetId) instanceof Folder) {
                throw new \InvalidArgumentException('Ziel-Ordner nicht gefunden.');
            }
            $this->authz->require('folder', $targetId, 'write');
        }
        if ($targetId === $id || $this->isDescendant($targetId, $id)) {
            throw new \InvalidArgumentException('Ein Ordner kann nicht in sich selbst oder einen Unterordner verschoben werden.');
        }

        $folder->setParentId($targetId);
        $folder->setSlug($this->uniqueSlug($folder)); // re-unique among the new siblings
        $this->em->persist($folder);
        $this->em->flush();
        $this->documents->rebuildMaterialization(); // reparent → descendant media paths

        return $folder;
    }

    /**
     * Set (or clear) a partition's `key` — its stable address for `rootFolder()` /
     * `forModule()` / `?key=` mounts (ADR-021 revision: the key is UI-settable, but ONLY
     * here and only by a SUPER_USER; the `?key=` REQUEST resolution stays find-only, S2).
     * Restricted to human-created partitions: a module-declared (`system`) partition's
     * key is the module's identity and stays code-only. Enforced unique tree-wide;
     * `Folder::DRIVE_KEY` is reserved.
     *
     * @throws \InvalidArgumentException not a partition / reserved / bad charset / duplicate
     * @throws \RuntimeException         module-declared (system) partition
     */
    public function setKey(int $id, ?string $key): Folder
    {
        $this->authz->requireSuperUser();
        $folder = $this->require($id);
        if (!$this->isPartition($folder)) {
            throw new \InvalidArgumentException('Nur Bereiche (oberste Ordner unter dem Drive) können einen Key tragen.');
        }
        if ($folder->isSystem()) {
            throw new \RuntimeException('Der Key eines modul-verwalteten Bereichs ist die Modul-Adresse und kann nicht geändert werden.');
        }
        if ($key === Folder::DRIVE_KEY) {
            throw new \InvalidArgumentException("Der Key '" . Folder::DRIVE_KEY . "' ist für den Drive-Root reserviert.");
        }
        if ($key !== null) {
            foreach ($this->folders->findAll() as $other) {
                if ($other->getId() !== $id && $other->getKey() === $key) {
                    throw new \InvalidArgumentException("Der Key '{$key}' wird bereits verwendet — er muss eindeutig sein.");
                }
            }
        }

        $folder->setKey($key); // validates the slug charset (S2); throws on garbage
        $this->em->persist($folder);
        $this->em->flush();

        return $folder;
    }

    /**
     * Assign (or clear) a folder's image profile — the name of a profile of the folder's
     * PARTITION in the project's DMS `imageProfilesConfig` (partition-namespaced, project
     * override). Uploads into the subtree resolve it via inheritance at save time
     * (`SaveService`); clearing (`null`/`''`) restores inheritance from the parent chain.
     * Gate: effective `manage` (like {@see rename}); the drive root never carries a
     * profile. No re-materialization — profiles do not change URLs or bytes.
     *
     * @throws \InvalidArgumentException unknown profile for the partition / `admin` / drive root
     */
    public function setProfile(int $id, ?string $profile): Folder
    {
        $this->authz->require('folder', $id, 'manage');
        $folder = $this->require($id);
        if ($folder->getParentId() === null) {
            throw new \InvalidArgumentException('Der Drive-Root kann kein Bildprofil tragen.');
        }

        $profile = $profile !== null ? trim($profile) : null;
        $profile = $profile === '' ? null : $profile;
        if ($profile !== null) {
            // `admin` is framework-fixed (always generated) and never an assignment;
            // anything else must exist in the partition's config block.
            $ident = $this->documents->partitionIdentOf($id);
            if (
                $profile === ImageProfileRegistry::ADMIN
                || $ident === null
                || !ImageProfileRegistry::fromConfig()->has($ident, $profile)
            ) {
                throw new \InvalidArgumentException(
                    "Das Bildprofil '{$profile}' ist für diesen Bereich nicht definiert."
                );
            }
        }

        $folder->setProfile($profile);
        $this->em->persist($folder);
        $this->em->flush();

        return $folder;
    }

    /** Delete a folder (only when empty and not a system folder). Returns the former parent id. */
    public function delete(int $id): ?int
    {
        $this->authz->require('folder', $id, 'manage');
        $folder = $this->require($id);
        $this->requirePartitionGate($folder); // deleting a partition = SUPER_USER (ADR-021/D5)
        if (($reason = $this->blockReason($folder)) !== null) {
            throw new \RuntimeException($reason);
        }
        $parentId = $folder->getParentId();
        $this->em->remove($folder);

        return $parentId;
    }

    /** Delete guard: system folders and non-empty folders (subfolders or live docs) cannot be deleted. */
    public function blockReason(Folder $folder): ?string
    {
        if ($folder->isSystem()) {
            return 'Dies ist ein geschützter System-Ordner und kann nicht gelöscht werden.';
        }
        foreach ($this->folders->findAll() as $f) {
            if ($f->getParentId() === $folder->getId()) {
                return 'Der Ordner ist nicht leer. Bitte zuerst Unterordner und Dokumente verschieben oder löschen.';
            }
        }
        if ($this->documents->listByFolder($folder->getId()) !== []) {
            return 'Der Ordner ist nicht leer. Bitte zuerst Unterordner und Dokumente verschieben oder löschen.';
        }
        return null;
    }

    /**
     * The drive root is always locked (ADR-021 rule 1 — the single mandatory top), and
     * S4: a module-declared partition (`system` child of the root) must never change its
     * slug (top segment of every public URL of the partition) or its position (its `key`
     * is the module's address and only valid on a partition).
     */
    private function assertSystemRootUnlocked(Folder $folder, string $verb): void
    {
        if ($folder->getParentId() === null) {
            throw new \RuntimeException(
                "Der Drive-Root kann nicht {$verb} werden."
            );
        }
        if ($folder->isSystem() && $this->isPartition($folder)) {
            throw new \RuntimeException(
                "Dieser Bereich ist modul-verwaltet und kann nicht {$verb} werden."
            );
        }
    }

    /** Whether the folder is a partition (a direct child of the drive root, ADR-021). */
    private function isPartition(Folder $folder): bool
    {
        $parentId = $folder->getParentId();
        if ($parentId === null) {
            return false; // the drive root itself
        }
        $parent = $this->folders->find($parentId);

        return $parent instanceof Folder && $parent->getParentId() === null;
    }

    /**
     * ADR-021 / D5: the lifecycle of a PARTITION (rename/move/delete) is SUPER_USER-only —
     * a delegated `manage` on the partition lets an area admin work INSIDE it, not
     * reshape the partition set itself. No-op below the partition level.
     */
    private function requirePartitionGate(Folder $folder): void
    {
        if ($this->isPartition($folder)) {
            $this->authz->requireSuperUser();
        }
    }

    private function assertName(string $name): void
    {
        if ($name === '') {
            throw new \InvalidArgumentException('Bitte einen Ordnernamen angeben.');
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            throw new \InvalidArgumentException('Der Ordnername ist zu lang (max. ' . self::NAME_MAX . ' Zeichen).');
        }
    }

    private function require(int $id): Folder
    {
        $folder = $id ? $this->folders->find($id) : null;
        if (!$folder instanceof Folder) {
            throw new NotFoundException('Ordner nicht gefunden');
        }
        return $folder;
    }

    /** URL-safe slug, unique among siblings (same parent, self excluded), `-2`/`-3`… on collision. */
    private function uniqueSlug(Folder $folder): string
    {
        $base = Naming::toSlug($folder->getName());
        if ($base === '') {
            $base = 'ordner';
        }
        $taken = [];
        foreach ($this->folders->findAll() as $sibling) {
            if ($sibling->getId() !== $folder->getId() && $sibling->getParentId() === $folder->getParentId()) {
                $taken[$sibling->getSlug()] = true;
            }
        }
        $slug = $base;
        $n    = 2;
        while (isset($taken[$slug])) {
            $slug = $base . '-' . $n++;
        }
        return $slug;
    }

    /** Whether $maybeChild lies inside the subtree of $ancestor (walking parent links). */
    private function isDescendant(int $maybeChild, int $ancestor): bool
    {
        $cur   = $maybeChild;
        $guard = 100;
        while ($cur !== 0 && $guard-- > 0) {
            $f = $this->folders->find($cur);
            if (!$f instanceof Folder) {
                return false;
            }
            $parent = $f->getParentId();
            if ($parent === $ancestor) {
                return true;
            }
            $cur = $parent ?? 0;
        }
        return false;
    }

    private function treeService(): TreeService
    {
        // One tree, no partitions by scope (ADR-020) — the roots are the partitions.
        return $this->tree ??= new TreeService(fn(Folder $f) => null);
    }
}
