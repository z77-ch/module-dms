<?php

namespace Z77\Module\Dms\Services;

use Z77\Core\DI;
use Z77\Core\Libraries\Cache\DataCache;
use Z77\Module\Dms\Entities\AccessControlEntry;
use Z77\Module\Dms\Entities\Document;
use Z77\Module\Dms\Entities\Folder;
use Z77\Module\Dms\Repositories\AccessControlEntryRepository;
use Z77\Module\Dms\Repositories\DocumentRepository;
use Z77\Module\Dms\Repositories\FolderRepository;
use Z77\Module\Dms\ValueObjects\Principal;

/**
 * The DMS authorization policy (ADR-017 / R2). Computes the effective right of a
 * {@see Principal} on a folder or document and the read/active output gate. It only
 * decides *permission* — it never touches bytes, paths, or delivery; the delivery layer
 * (R4) calls {@see canRead()} BEFORE resolving any blob path. ACL is consulted only for
 * `deliveryMode = protected`; `public` is a delivery mode (no check, no `guest` subject),
 * `sealed` never leaves `data/blobs` — that branching is the delivery layer's job (R4).
 *
 * Effective right (no admin/owner shortcut) = the union of all ACEs on the resource plus
 * every ancestor folder whose subject matches the principal's user id or one of its
 * roles, taken as the maximum on the ladder `none < read < write < manage`.
 *
 * R2b caching (APCu via {@see DataCache}) — two layers, identical behaviour to R2a:
 *  - Layer 1 (principal-independent inputs): the tree-wide folder index (ADR-020 — scope
 *    is the tree, no area partitions) and the ACE set indexed by resource. These are
 *    loaded once and reused, removing the redundant file reads.
 *  - Layer 2 (the result): the effective right per `(principal, resourceType, resourceId)`,
 *    so a repeated lookup (e.g. R4 serving the same document to the same user) is a pure
 *    cache hit with no I/O.
 * Invalidation is the framework's coarse mechanism: `AccessControlEntry`, `Folder` and
 * `Document` all carry `#[Entity(..., invalidatesCache: true)]`, so any write clears the
 * whole APCu pool (and the page cache) — both layers drop together. No finer invalidation
 * is needed for the write rates this store sees (ACE grants, active toggles, folder moves).
 *
 * Not a DI singleton (placement decision B): built on demand via {@see create()}.
 */
final class AclService
{
    /** Right ladder — order matters; the int is the comparable level. */
    private const RIGHTS = ['none' => 0, 'read' => 1, 'write' => 2, 'manage' => 3];

    /** Guard against a corrupt parent chain (cycle / dangling parentId). */
    private const CHAIN_GUARD = 50;

    /** DataCache namespace for every entry this service owns. */
    private const CACHE_NS = 'AclService';

    public function __construct(
        private AccessControlEntryRepository $aces,
        private FolderRepository $folders,
        private DocumentRepository $documents,
        private DataCache $cache,
    ) {}

    public static function create(): self
    {
        $uem = DI::getUnifiedEntityManager();

        return new self(
            $uem->getRepository(AccessControlEntry::class),
            $uem->getRepository(Folder::class),
            $uem->getRepository(Document::class),
            DI::getCacheManager()->data(),
        );
    }

    /**
     * Effective right of the principal on a resource: `none|read|write|manage`.
     * Unknown resource → `none`. Cached per `(principal, type, id)` (layer 2).
     */
    public function effectiveRight(Principal $p, string $resourceType, int $resourceId): string
    {
        if ($p->isSuperUser()) {
            return 'manage';
        }

        $components = ['right', $this->signature($p), $resourceType, $resourceId];
        $cached     = $this->cache->get(self::CACHE_NS, $components);
        if ($cached !== null) {
            return $cached;
        }

        $right = $this->computeRight($p, $resourceType, $resourceId);
        $this->cache->set(self::CACHE_NS, $components, $right, cachePersist: true);

        return $right;
    }

    /**
     * Whether the principal has at least `$required` on the resource.
     */
    public function hasAccess(Principal $p, string $resourceType, int $resourceId, string $required = 'read'): bool
    {
        $have = self::RIGHTS[$this->effectiveRight($p, $resourceType, $resourceId)] ?? 0;
        $need = self::RIGHTS[$required] ?? PHP_INT_MAX;

        return $have >= $need;
    }

    /**
     * The output gate for delivery (R4): the document AND every ancestor folder must be
     * `active`, AND the principal must have at least `read`. Either failing → false.
     */
    public function canRead(Principal $p, Document $doc): bool
    {
        if (!$doc->isActive()) {
            return false;
        }

        [$index, $chainIds] = $this->ancestry($doc->getFolderId());
        foreach ($chainIds as $folderId) {
            if (!($index[$folderId]['active'] ?? false)) {
                return false;
            }
        }

        $right = $this->effectiveRight($p, 'document', (int) $doc->getId());

        return self::RIGHTS[$right] >= self::RIGHTS['read'];
    }

    // ── policy (uncached core, fed by the layer-1 caches) ───────────────────────

    /**
     * The effective right without the layer-2 cache — the R2a algorithm, but reading the
     * ancestor chain and ACEs from the layer-1 caches instead of the repositories.
     */
    private function computeRight(Principal $p, string $resourceType, int $resourceId): string
    {
        if ($resourceType === 'document') {
            $doc = $this->documents->find($resourceId);
            if (!$doc instanceof Document) {
                return 'none';
            }
            [$index, $chainIds] = $this->ancestry($doc->getFolderId());

            if ($p->isAuthenticated() && $this->ownsAny($p, $doc->getOwnerId(), $index, $chainIds)) {
                return 'manage';
            }
            return $this->levelName($this->unionRight($p, $this->documentKeys((int) $doc->getId(), $chainIds)));
        }

        if ($resourceType === 'folder') {
            $folder = $this->folders->find($resourceId);
            if (!$folder instanceof Folder) {
                return 'none';
            }
            [$index, $chainIds] = $this->ancestry($folder->getId());

            if ($p->isAuthenticated() && $this->ownsAny($p, null, $index, $chainIds)) {
                return 'manage';
            }
            return $this->levelName($this->unionRight($p, $this->folderKeys($chainIds)));
        }

        return 'none';
    }

    /**
     * Maximum right level granted to the principal across the given ACE resource keys
     * (`<type>:<id>`), read from the layer-1 ACE index.
     *
     * @param string[] $resourceKeys
     */
    private function unionRight(Principal $p, array $resourceKeys): int
    {
        $index = $this->aceIndex();
        $best  = 0;
        foreach ($resourceKeys as $key) {
            foreach ($index[$key] ?? [] as $ace) {
                if ($this->matchesSubject($p, $ace)) {
                    $best = max($best, self::RIGHTS[$ace['rights']] ?? 0);
                }
            }
        }
        return $best;
    }

    /**
     * @param array{subjectType:string,subjectId:string,rights:string} $ace
     */
    private function matchesSubject(Principal $p, array $ace): bool
    {
        return match ($ace['subjectType']) {
            'user'  => $p->isAuthenticated() && $ace['subjectId'] === (string) $p->userId,
            'role'  => in_array($ace['subjectId'], $p->roles, true),
            default => false,
        };
    }

    /**
     * Whether the principal owns the resource itself or any folder in its chain.
     *
     * @param array<int, array{parentId:?int,ownerId:?int,active:bool}> $index
     * @param int[]                                                     $chainIds
     */
    private function ownsAny(Principal $p, ?int $selfOwnerId, array $index, array $chainIds): bool
    {
        if ($selfOwnerId !== null && $selfOwnerId === $p->userId) {
            return true;
        }
        foreach ($chainIds as $folderId) {
            if (($index[$folderId]['ownerId'] ?? null) === $p->userId) {
                return true;
            }
        }
        return false;
    }

    // ── layer-1 caches (principal-independent inputs) ───────────────────────────

    /**
     * The tree-wide folder index plus the self+ancestor id chain for `$folderId`
     * (nearest first). The chain ends at a root folder (`parentId = null`) — the
     * partition itself (ADR-020). Empty chain when `$folderId` is null.
     *
     * @return array{0: array<int, array{parentId:?int,ownerId:?int,active:bool}>, 1: int[]}
     */
    private function ancestry(?int $folderId): array
    {
        if ($folderId === null) {
            return [[], []];
        }

        $index   = $this->folderIndex();
        $chain   = [];
        $current = $folderId;
        $guard   = self::CHAIN_GUARD;
        while ($current !== null && isset($index[$current]) && $guard-- > 0) {
            $chain[] = $current;
            $current = $index[$current]['parentId'];
        }

        return [$index, $chain];
    }

    /**
     * Every folder reduced to the fields the policy needs, keyed by id (the whole tree —
     * scope is the tree, ADR-020). Cached (layer 1) — principal-independent, shared by
     * every lookup.
     *
     * @return array<int, array{parentId:?int,ownerId:?int,active:bool}>
     */
    private function folderIndex(): array
    {
        $cached = $this->cache->get(self::CACHE_NS, ['folders']);
        if ($cached !== null) {
            return $cached;
        }

        $index = [];
        foreach ($this->folders->findAll() as $folder) {
            $id = $folder->getId();
            if ($id !== null) {
                $index[$id] = [
                    'parentId' => $folder->getParentId(),
                    'ownerId'  => $folder->getOwnerId(),
                    'active'   => $folder->isActive(),
                ];
            }
        }

        $this->cache->set(self::CACHE_NS, ['folders'], $index, cachePersist: true);

        return $index;
    }

    /**
     * Every ACE reduced to its match fields, grouped by resource key (`<type>:<id>`).
     * Cached (layer 1) as a single entry — one load instead of one per ancestor.
     *
     * @return array<string, array<int, array{subjectType:string,subjectId:string,rights:string}>>
     */
    private function aceIndex(): array
    {
        $cached = $this->cache->get(self::CACHE_NS, ['aces']);
        if ($cached !== null) {
            return $cached;
        }

        $index = [];
        foreach ($this->aces->findAll() as $ace) {
            $key = $ace->getResourceType() . ':' . $ace->getResourceId();
            $index[$key][] = [
                'subjectType' => $ace->getSubjectType(),
                'subjectId'   => $ace->getSubjectId(),
                'rights'      => $ace->getRights(),
            ];
        }

        $this->cache->set(self::CACHE_NS, ['aces'], $index, cachePersist: true);

        return $index;
    }

    // ── helpers ─────────────────────────────────────────────────────────────────

    /**
     * ACE resource keys for a document: the document itself + each ancestor folder.
     *
     * @param int[] $chainIds
     * @return string[]
     */
    private function documentKeys(int $docId, array $chainIds): array
    {
        $keys = ['document:' . $docId];
        foreach ($chainIds as $folderId) {
            $keys[] = 'folder:' . $folderId;
        }
        return $keys;
    }

    /**
     * ACE resource keys for a folder: every folder in its self+ancestor chain.
     *
     * @param int[] $chainIds
     * @return string[]
     */
    private function folderKeys(array $chainIds): array
    {
        return array_map(fn($folderId) => 'folder:' . $folderId, $chainIds);
    }

    /**
     * Stable cache signature of a principal: the right depends on the user id and the
     * (order-independent) role set. A role change rebuilds the principal → a new
     * signature → a fresh entry, so a changed principal never reads a stale right.
     */
    private function signature(Principal $p): string
    {
        $roles = $p->roles;
        sort($roles);

        return $p->userId . '-' . substr(md5(implode(',', $roles)), 0, 12);
    }

    private function levelName(int $level): string
    {
        return array_search($level, self::RIGHTS, true) ?: 'none';
    }
}
