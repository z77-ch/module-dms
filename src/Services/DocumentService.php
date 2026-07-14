<?php

namespace Z77\Module\Dms\Services;

use Z77\Core\DI;
use Z77\Core\Exception\NotFoundException;
use Z77\Core\Http\Response\FileResponse;
use Z77\Core\Libraries\Cache\DataCache;
use Z77\Module\Dms\Blob\BlobStorage;
use Z77\Module\Dms\Blob\LocalBlobStorage;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Module\Dms\Images\DocumentKind;
use Z77\Module\Dms\Entities\AccessControlEntry;
use Z77\Module\Dms\Entities\Document;
use Z77\Module\Dms\Entities\Folder;
use Z77\Shared\Mail\Attachment;
use Z77\Shared\Mail\Mailer;
use Z77\Shared\Mail\Message;
use Z77\Module\Dms\Repositories\AccessControlEntryRepository;
use Z77\Module\Dms\Repositories\DocumentRepository;
use Z77\Module\Dms\Repositories\FolderRepository;
use Z77\Shared\Libraries\Convention\Naming;

/**
 * The public façade modules use for documents (ADR-016 / ADR-017) — the only DMS API a
 * consuming module sees. It hides the split between metadata (repositories / entity
 * manager) and bytes (`BlobStorage`), and owns the access policy that the lower layers
 * stay free of: soft-deleted documents are never listed or served.
 *
 * Access follows the ownership + ACL model (ADR-017): a document's `deliveryMode`
 * (`sealed|protected|public`, inherited via the folder chain) decides whether bytes are
 * gated. The `/media` delivery (the `OutputController`) resolves a structural path via
 * {@see resolve()}, branches on {@see effectiveDeliveryMode()}, and for protected/sealed
 * gates with `AclService::canRead()` BEFORE any byte. ACEs are managed here via
 * {@see grant()}/{@see revoke()}; the `active` output gate via {@see setActive()}.
 *
 * Delivery (`serve`) returns a {@see FileResponse}; the byte transfer is always the
 * portable PHP range-stream (web-server-accelerated delivery was rejected — see ADR-017).
 *
 * Not a DI singleton (placement decision B): built on demand via {@see create()}.
 */
final class DocumentService
{
    /** DataCache namespace for the media-url resolve indexes (see media-url-helper-bauplan). */
    private const CACHE_NS = 'DmsMediaUrl';

    public function __construct(
        private DocumentRepository $documents,
        private FolderRepository $folders,
        private UnifiedEntityManager $em,
        private BlobStorage $blob,
        private SaveService $saveService,
        private AccessControlEntryRepository $aces,
        private Authz $authz,
        private DataCache $cache,
    ) {}

    public static function create(): self
    {
        $uem = DI::getUnifiedEntityManager();

        return new self(
            $uem->getRepository(Document::class),
            $uem->getRepository(Folder::class),
            $uem,
            new LocalBlobStorage(),
            SaveService::create(),
            $uem->getRepository(AccessControlEntry::class),
            Authz::create(),
            DI::getCacheManager()->data(),
        );
    }

    // ── read ────────────────────────────────────────────────────────────────

    /**
     * Live (non-deleted) documents in a folder.
     *
     * @return Document[]
     */
    public function listByFolder(?int $folderId): array
    {
        return $this->live($this->documents->findByFolder($folderId));
    }

    /**
     * All live documents under a partition root (the whole subtree, ADR-020).
     *
     * @return Document[]
     */
    public function listByRoot(int $rootId): array
    {
        $index = $this->folderIndex();

        return array_values(array_filter(
            $this->listAll(),
            fn(Document $d) => $this->rootIdOf($d->getFolderId(), $index) === $rootId
        ));
    }

    /**
     * Every live document (all partitions).
     *
     * @return Document[]
     */
    public function listAll(): array
    {
        return $this->live($this->documents->findAll());
    }

    /**
     * A live document by id, or null (also null if soft-deleted).
     */
    public function get(int $id): ?Document
    {
        $doc = $this->documents->find($id);

        return ($doc instanceof Document && !$doc->isDeleted()) ? $doc : null;
    }

    /**
     * The partition a folder belongs to (the child of the drive root at the top of its
     * parent chain, ADR-021), or null for an unknown folder or the drive root itself.
     * A partition resolves to itself. The scope of an entity IS this derivation — never
     * a stored field (ADR-020).
     */
    public function rootOf(int $folderId): ?Folder
    {
        $index  = $this->folderIndex();
        $rootId = $this->rootIdOf($folderId, $index);

        return $rootId !== null ? ($index[$rootId] ?? null) : null;
    }

    /**
     * The partition IDENT of a folder for image-profile resolution: the partition root's
     * `key ?? slug` (a keyless, human-created partition is addressed by its slug — the
     * `?key=` mount pattern; ADR-020 rev.). Null for an unknown folder / the drive root.
     */
    public function partitionIdentOf(int $folderId): ?string
    {
        $root = $this->rootOf($folderId);
        if ($root === null) {
            return null;
        }

        return $root->getKey() ?? ($root->getSlug() !== '' ? $root->getSlug() : null);
    }

    /**
     * Partition id for a folder id via the given index (cycle-guarded), or null. The
     * partition is the chain link just BELOW the top (`parentId = null` = the drive
     * root, ADR-021); the drive root itself has no partition.
     *
     * @param array<int, Folder> $index
     */
    private function rootIdOf(?int $folderId, array $index): ?int
    {
        $cur   = $folderId;
        $prev  = null;
        $guard = 50;
        while ($cur !== null && $guard-- > 0) {
            $folder = $index[$cur] ?? null;
            if ($folder === null) {
                return null;
            }
            if ($folder->getParentId() === null) {
                return $prev; // $cur is the drive root; the previous link is the partition
            }
            $prev = $cur;
            $cur  = $folder->getParentId();
        }

        return null;
    }

    /**
     * The single drive root (ADR-021 rule 1): get-or-create, plus self-healing — any
     * OTHER top-level folder (pre-ADR-021 data, or a corrupt second top) is adopted as a
     * child of the root, so the single-root invariant holds after every call. System
     * path (trusted, like {@see rootFolder}); reads stay find-only
     * ({@see FolderRepository::findDriveRoot}).
     */
    public function driveRoot(): Folder
    {
        $drive = $this->folders->findDriveRoot();

        if ($drive === null) {
            $drive = new Folder();
            $drive->setKey(Folder::DRIVE_KEY);
            $drive->setName('Drive');
            $drive->setParentId(null);
            $drive->setSystem(true);
            $drive->setOwnerId(null); // null = z77 core / system (ADR-017 convention)
            $drive->setSlug(Folder::DRIVE_KEY);
            $drive->setSortKey(0);
            $this->em->persist($drive);
            $this->em->flush();

            // S3 (TOCTOU): a concurrent create may have won — re-resolve deterministically.
            $winner = $this->folders->findDriveRoot();
            $drive  = $winner instanceof Folder ? $winner : $drive;
        }

        // Adopt strays: every remaining top-level folder becomes a partition (a child of
        // the drive root) — pre-ADR-021 partitions keep their id/slug, URLs stay stable.
        $adopted = false;
        foreach ($this->folders->findBy(['parent_id' => null]) as $stray) {
            if ($stray->getId() !== $drive->getId()) {
                $stray->setParentId($drive->getId());
                $this->em->persist($stray);
                $adopted = true;
            }
        }
        if ($adopted) {
            $this->em->flush();
        }

        return $drive;
    }

    /**
     * Get-or-create the partition a module declares (ADR-020 rule 3/4 / ADR-021). The
     * `$key` MUST be a code constant of the module — NEVER request input (S2,
     * root-squatting) — and never the reserved {@see Folder::DRIVE_KEY}. System path,
     * ungated like `saveGenerated`: a missing partition is created as a `system = true`
     * child of the drive root (rename/move/delete-locked — its slug is the top segment
     * of every public URL of the partition, S4) with no owner (the SUPER_USER grants
     * access, ADR-020 "existence vs. access"). Key uniqueness is get-or-create by
     * construction; deterministic on a duplicate (File driver, no unique index — S3):
     * the smallest id wins.
     */
    public function rootFolder(string $key, ?string $name = null): Folder
    {
        if ($key === Folder::DRIVE_KEY) {
            throw new \InvalidArgumentException(
                "Folder key '" . Folder::DRIVE_KEY . "' is reserved for the drive root (ADR-021)."
            );
        }

        $existing = $this->folders->findRootByKey($key);
        if ($existing instanceof Folder) {
            return $existing;
        }

        $folder = new Folder();
        $folder->setKey($key); // validates the charset (S2); throws on garbage
        $folder->setName($name ?? $key);
        $folder->setParentId($this->driveRoot()->getId()); // partitions are root children (ADR-021)
        $folder->setSystem(true);
        $folder->setOwnerId(null);
        $folder->setSlug($this->uniqueRootSlug($name ?? $key));
        $folder->setSortKey($this->nextRootSortKey());
        $this->em->persist($folder);
        $this->em->flush();

        // S3 (TOCTOU): a concurrent create may have won — re-resolve deterministically.
        $winner = $this->folders->findRootByKey($key);

        return $winner instanceof Folder ? $winner : $folder;
    }

    /**
     * The module's scoped write handle (ADR-021 rule 4). The target partition is the
     * module's own `$moduleKey` unless `dmsConfig['moduleFolders']` remaps it
     * (`['<module-key>' => '<partition-key>']` — the optional remapping foreseen by
     * ADR-020; the module key itself stays a CODE constant, S2). The partition is
     * ensured get-or-create ({@see rootFolder}); every write through the handle is
     * confined to that subtree ({@see ModuleDrive}).
     */
    public function forModule(string $moduleKey): ModuleDrive
    {
        $map       = DI::getModuleManager()->getModuleConfig('dms')?->get('moduleFolders', []) ?? [];
        $targetKey = (is_array($map) ? ($map[$moduleKey] ?? null) : null) ?? $moduleKey;

        return new ModuleDrive(
            $this,
            $this->folders,
            $this->em,
            $this->rootFolder($targetKey),
        );
    }

    /** URL-safe root slug, unique among the roots (`-2`/`-3`… on collision). */
    private function uniqueRootSlug(string $name): string
    {
        $base = Naming::toSlug($name);
        if ($base === '') {
            $base = 'bereich';
        }
        $taken = [];
        foreach ($this->folders->findRoots() as $root) {
            $taken[$root->getSlug()] = true;
        }
        $slug = $base;
        $n    = 2;
        while (isset($taken[$slug])) {
            $slug = $base . '-' . $n++;
        }

        return $slug;
    }

    /** Next free sortKey at the top level (appended after the existing roots). */
    private function nextRootSortKey(): int
    {
        $max = -1;
        foreach ($this->folders->findRoots() as $root) {
            $max = max($max, $root->getSortKey());
        }

        return $max + 1;
    }

    // ── delivery ──────────────────────────────────────────────────────────────

    /**
     * Build the response for a (already authorised) document. `$variant` selects an
     * image derivative (null = the original); `$download` forces an attachment instead
     * of inline display.
     *
     * @throws NotFoundException when the document is deleted, the variant is unknown,
     *                           or the bytes are missing
     */
    public function serve(Document $doc, ?string $variant = null, bool $download = false, ?string $cacheControl = null): FileResponse
    {
        if ($doc->isDeleted()) {
            throw new NotFoundException('Document not found.');
        }

        $variantName = $variant ?? BlobStorage::ORIGINAL;
        if ($variantName !== BlobStorage::ORIGINAL && !array_key_exists($variantName, $doc->getVariants())) {
            throw new NotFoundException("Unknown variant '{$variantName}'.");
        }

        // A variant may have a different extension than the original (a video poster is `jpg`
        // though the video is `mp4`) — resolve the blob + MIME by the VARIANT's own extension.
        $ext  = $this->variantExt($doc, $variantName);
        $mime = $variantName === BlobStorage::ORIGINAL
            ? $doc->getMimeType()
            : self::mimeForExt($ext, $doc->getMimeType());

        $path = $this->blob->path($doc->getId(), $variantName, $ext);
        if ($path === null) {
            throw new NotFoundException('Document bytes not found.');
        }

        return new FileResponse(
            path: $path,
            filename: $doc->getOriginalName(),
            mimeType: $mime,
            disposition: $download ? FileResponse::ATTACHMENT : FileResponse::INLINE,
            etag: $doc->getChecksum() . '-' . $variantName,
            // Default to the safe (private) policy; the public `/media` branch passes an
            // explicit immutable `cacheControl` (OutputController).
            cacheControl: $cacheControl ?? 'private, no-cache',
        );
    }

    /**
     * The stored extension of a variant blob. A derivative can differ from the document's
     * own extension (a video's poster variants are `jpg`); the ext is recorded in the variant
     * meta ({@see ProcessedVariant::toMeta}). Falls back to the document extension for the
     * original and for legacy image variants persisted before the ext was stored (there the
     * variant ext always equalled the document ext, so the fallback is correct).
     */
    private function variantExt(Document $doc, string $variantName): string
    {
        return self::variantExtFromMap($variantName, $doc->getVariants(), $doc->getExt());
    }

    /** Ext of a variant from a stored variants map (entity-free twin of {@see variantExt}). */
    private static function variantExtFromMap(string $variantName, array $variants, string $docExt): string
    {
        if ($variantName === BlobStorage::ORIGINAL) {
            return $docExt;
        }
        $meta = $variants[$variantName] ?? null;

        return (is_array($meta) && !empty($meta['ext'])) ? (string) $meta['ext'] : $docExt;
    }

    /** Best-effort MIME for a served image derivative, by extension; `$fallback` otherwise. */
    private static function mimeForExt(string $ext, string $fallback): string
    {
        return match (strtolower($ext)) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'webp'        => 'image/webp',
            'gif'         => 'image/gif',
            'avif'        => 'image/avif',
            default       => $fallback,
        };
    }

    /**
     * The ONLY way to build a public `/media` URL for templates/modules (decided
     * 2026-07-02). Appends a content-version token (`?v=` + checksum prefix) so an
     * in-place byte replace ({@see SaveService::replace}) busts long-lived browser
     * caches: the static file may be cached for a year (deploy `.htaccess`), the token
     * changes exactly when the bytes change — path and identity stay stable. Never
     * assemble a `/media` path by hand — a hand-built URL has no version token and goes
     * stale after a replace.
     *
     * Pure URL building — NO access gate (delivery is gated by the OutputController /
     * the static copy only exists for effectively-public, active-chain documents). For a
     * non-public document the returned URL simply 404s. Returns null for a soft-deleted
     * document or a broken/unslugged folder chain.
     */
    public function publicUrl(Document $doc, ?string $variant = null): ?string
    {
        if ($doc->isDeleted()) {
            return null;
        }

        return $this->buildPublicUrl(
            $this->folderSlugPath($doc),
            $doc->getSlug(),
            $variant,
            $doc->getExt(),
            $doc->getVariants(),
            $doc->getChecksum(),
        );
    }

    /**
     * The single `/media` URL assembler — used by {@see publicUrl()} (entity path) AND the
     * cached {@see urlForPath()} (template path), so both produce byte-identical URLs. Returns
     * null for an unslugged document, a broken/empty folder-slug chain, or a missing variant.
     * Appends the `?v=<checksum>` content-version token.
     */
    private function buildPublicUrl(
        string $relPath,
        string $slug,
        ?string $variant,
        string $docExt,
        array $variants,
        string $checksum,
    ): ?string {
        if ($slug === '' || $relPath === '') {
            return null;
        }
        if ($variant !== null && !array_key_exists($variant, $variants)) {
            return null;
        }
        $ext  = $variant !== null ? self::variantExtFromMap($variant, $variants, $docExt) : $docExt;
        $file = $slug . ($variant !== null ? '.' . $variant : '') . '.' . $ext;

        return '/media/' . $relPath . '/' . $file . '?v=' . substr($checksum, 0, 8);
    }

    /**
     * Public `/media` URL for a document addressed by its structural slug path
     * (`'front/imgs/logo.png'` = partition-root slug + folder slugs + `<slug>.<ext>`).
     * Combines {@see resolve()} + {@see publicUrl()} so a caller (e.g. the `mediaUrl()`
     * template helper) never walks the tree itself. `$variant` selects an image variant
     * of the SAME document (the path always names the original leaf).
     *
     * Returns null when the path resolves to nothing (unknown / soft-deleted / ambiguous).
     * Builds the URL blindly like {@see publicUrl()} — it does NOT gate on `deliveryMode`;
     * a non-public document yields a URL that 404s at delivery.
     */
    public function urlForPath(string $path, ?string $variant = null): ?string
    {
        $key   = implode('/', array_filter(explode('/', $path), static fn($s) => $s !== ''));
        $entry = $this->publicPathIndex()[$key] ?? null;
        if ($entry === null) {
            return null;
        }

        return $this->buildPublicUrl(
            $entry['relPath'],
            $entry['slug'],
            $variant,
            $entry['ext'],
            $entry['variants'],
            $entry['checksum'],
        );
    }

    /**
     * The template-facing image bundle for a DMS document by structural slug path: the public
     * URL plus the localized `alt` / `caption` (current request language → i18n default → empty,
     * the {@see t()} resolution order) and the intrinsic pixel dimensions. Backs the
     * {@see \mediaImage()} helper; same index + cache as {@see urlForPath}. Returns null when the
     * path resolves to nothing.
     *
     * @return array{url: string, alt: string, caption: string, width: ?int, height: ?int}|null
     */
    public function imageForPath(string $path, ?string $variant = null): ?array
    {
        $key   = implode('/', array_filter(explode('/', $path), static fn($s) => $s !== ''));
        $entry = $this->publicPathIndex()[$key] ?? null;
        if ($entry === null) {
            return null;
        }
        $url = $this->buildPublicUrl(
            $entry['relPath'], $entry['slug'], $variant, $entry['ext'], $entry['variants'], $entry['checksum'],
        );
        if ($url === null) {
            return null;
        }

        // Dimensions must match the DELIVERED image, not the original. For a named derivative
        // use the variant's own w/h (ProcessedVariant::toMeta); for the original (variant null)
        // fall back to the document dimensions. buildPublicUrl() already returned null for an
        // unknown variant, so a non-null $variant is guaranteed present in the variants map here.
        $width  = $entry['width'] ?? null;
        $height = $entry['height'] ?? null;
        if ($variant !== null && isset($entry['variants'][$variant])) {
            $width  = $entry['variants'][$variant]['w'] ?? $width;
            $height = $entry['variants'][$variant]['h'] ?? $height;
        }

        return [
            'url'     => $url,
            'alt'     => $this->localizeMap($entry['alt'] ?? []),
            'caption' => $this->localizeMap($entry['caption'] ?? []),
            'width'   => $width,
            'height'  => $height,
        ];
    }

    /**
     * Resolve a `{ lang: text }` map to the current request language, falling back to the i18n
     * default language, then to the empty string (the {@see t()} resolution order).
     *
     * @param array<string,string> $map
     */
    private function localizeMap(array $map): string
    {
        if ($map === []) {
            return '';
        }
        $current = DI::getRequest()->getLanguage();
        if (!empty($map[$current])) {
            return (string) $map[$current];
        }

        return (string) ($map[DI::getI18n()->getDefaultLanguage()] ?? '');
    }

    /**
     * Read-gated byte delivery for a management surface (RF-4a): the current principal
     * needs effective `read` on the document (session principal, domain-enforced — a UI
     * gate is bypassable). Unlike the public `/media` gate this does NOT require the
     * active chain: `active` is an output gate for public delivery, and management must
     * be able to preview inactive documents. Denial → 404 (no existence leak).
     *
     * @throws NotFoundException when unknown, soft-deleted, or not readable
     */
    public function serveFor(int $id, ?string $variant = null, bool $download = false): FileResponse
    {
        $this->authz->require('document', $id, 'read');
        $doc = $this->get($id);
        if ($doc === null) {
            throw new NotFoundException('Document not found.');
        }

        return $this->serve($doc, $variant, $download);
    }

    /**
     * Resolve a structural `/media` path (ADR-017 §8 / ADR-020) to a document + variant.
     * The leading segments are the folder-slug chain from the tree top — the FIRST segment
     * is the partition-root slug (ADR-020 rule 5; the root is a real folder now, so it is
     * simply the first chain link). The last segment is the file: `<doc-slug>.<ext>`
     * (original) or `<doc-slug>.<variant>.<ext>` (a derivative — the variant lives in the
     * filename, ADR §8). Slugs never contain a dot, so the filename splits unambiguously.
     *
     * Pure resolution — NO `deliveryMode`/ACL/`active` gate (that is the OutputController's
     * job, R4c). Soft-deleted documents do not resolve. Returns `null` when the folder
     * chain, the document, or the requested variant does not exist, OR when more than one
     * live document shares the slug+ext in the folder (ambiguous → never serve a guess;
     * per-folder document-slug uniqueness is a save-side concern, R5).
     *
     * @param list<string> $segments the full path after `/media`
     * @return array{document: Document, variant: string}|null
     */
    public function resolve(array $segments): ?array
    {
        if ($segments === []) {
            return null;
        }

        $filename       = array_pop($segments);
        $folderSegments = $segments;

        // A document always lives in a folder — a bare `/media/<file>` (no folder
        // segment) never resolves. The minimum is `/media/<root-slug>/<file>`.
        if ($folderSegments === []) {
            return null;
        }

        // Walk the folder-slug chain from the drive root's children (ADR-021 rule 2: the
        // root's own slug is NOT a path segment — the first hop is the partition slug).
        $drive = $this->folders->findDriveRoot();
        if ($drive === null) {
            return null; // no root yet (fresh install, nothing seeded) — nothing resolves
        }
        $folders  = $this->folders->findAll();
        $parentId = $drive->getId();
        foreach ($folderSegments as $slugSegment) {
            $match = null;
            foreach ($folders as $folder) {
                if ($folder->getParentId() === $parentId && $folder->getSlug() === $slugSegment) {
                    $match = $folder;
                    break;
                }
            }
            if ($match === null) {
                return null;
            }
            $parentId = $match->getId();
        }

        // Split the filename into <slug>[.<variant>].<ext>.
        $parts = explode('.', $filename);
        $count = count($parts);
        if ($count === 2) {
            [$slug, $variant, $ext] = [$parts[0], BlobStorage::ORIGINAL, $parts[1]];
        } elseif ($count === 3) {
            [$slug, $variant, $ext] = [$parts[0], $parts[1], $parts[2]];
        } else {
            return null;
        }
        $ext = strtolower($ext);

        // Exactly one live document must match. For the ORIGINAL leaf the ext is the
        // document's own; for a VARIANT leaf the ext belongs to the variant (a video poster
        // is `slug.s.jpg` though the video is `slug.mp4`), so match on the variant's stored ext.
        $candidates = [];
        foreach ($this->documents->findByFolder($parentId) as $doc) {
            if ($doc->isDeleted() || $doc->getSlug() !== $slug) {
                continue;
            }
            if ($variant === BlobStorage::ORIGINAL) {
                if ($doc->getExt() === $ext) {
                    $candidates[] = $doc;
                }
            } elseif (array_key_exists($variant, $doc->getVariants()) && $this->variantExt($doc, $variant) === $ext) {
                $candidates[] = $doc;
            }
        }
        if (count($candidates) !== 1) {
            return null;
        }

        return ['document' => $candidates[0], 'variant' => $variant];
    }

    /**
     * Effective delivery mode of a document (ADR-017 §3): own mode if set, else the
     * nearest ancestor folder's mode, else the root default `protected`. A `sealed`
     * ancestor (or own `sealed`) caps the whole subtree — the vault can never be opened.
     * Drives the OutputController branch: `public` = served openly; `protected`/`sealed`
     * = ACL `READ` + `active` gate before any byte.
     */
    public function effectiveDeliveryMode(Document $doc): string
    {
        return $this->resolveEffective($doc->getDeliveryMode(), $doc->getFolderId());
    }

    /**
     * Effective delivery mode of a folder — like {@see effectiveDeliveryMode} but the
     * ancestor walk starts at the folder's parent (the folder's own mode is its own).
     */
    public function effectiveFolderDeliveryMode(Folder $folder): string
    {
        return $this->resolveEffective($folder->getDeliveryMode(), $folder->getParentId());
    }

    /**
     * The effective image profile of a folder: its own `profile` if set, else the nearest
     * ancestor folder's — the {@see effectiveFolderDeliveryMode} inheritance pattern,
     * without a default (null = no assignment anywhere; the save path then falls back to
     * the partition's `default` profile, {@see SaveService}). Display/consistency helper —
     * the authoritative save-time resolution walks the chain itself (`SaveService`).
     */
    public function effectiveFolderProfile(Folder $folder): ?string
    {
        $own = $folder->getProfile();
        if ($own !== null) {
            return $own;
        }

        $index   = $this->folderIndex();
        $current = $folder->getParentId() !== null ? ($index[$folder->getParentId()] ?? null) : null;
        $guard   = 50;
        while ($current !== null && $guard-- > 0) {
            if ($current->getProfile() !== null) {
                return $current->getProfile();
            }
            $pid     = $current->getParentId();
            $current = $pid !== null ? ($index[$pid] ?? null) : null;
        }

        return null;
    }

    /**
     * Resolve an effective delivery mode: own mode if set, else the nearest ancestor
     * folder's mode, else the default `protected`; a `sealed` anywhere in
     * `own + ancestors` caps the whole result to `sealed`. `$ancestorStartId` is where the
     * ancestor walk begins (a document → its folder; a folder → its parent). The chain
     * ends at the partition root — a real folder whose own mode inherits down (ADR-020).
     */
    private function resolveEffective(?string $ownMode, ?int $ancestorStartId): string
    {
        $sealed    = ($ownMode === 'sealed');
        $inherited = null;

        if ($ancestorStartId !== null) {
            $index   = $this->folderIndex();
            $current = $index[$ancestorStartId] ?? null;
            $guard   = 50;
            while ($current !== null && $guard-- > 0) {
                $mode = $current->getDeliveryMode();
                if ($mode === 'sealed') {
                    $sealed = true;
                }
                if ($inherited === null && $mode !== null) {
                    $inherited = $mode;
                }
                $pid     = $current->getParentId();
                $current = $pid !== null ? ($index[$pid] ?? null) : null;
            }
        }

        if ($sealed) {
            return 'sealed';
        }
        return $ownMode ?? $inherited ?? 'protected';
    }

    // ── mutate ──────────────────────────────────────────────────────────────

    /**
     * Persist a module-generated file (e.g. an invoice PDF).
     */
    public function saveGenerated(string $bytes, SaveRequest $req): Document
    {
        $doc = $this->saveService->saveGenerated($bytes, $req);
        $this->rebuildMaterialization();
        return $doc;
    }

    /**
     * Rename a document (metadata only — the display name shown in the tool). Empty
     * input is ignored; the blob and original filename are never touched.
     *
     * @throws NotFoundException when the document is unknown
     */
    public function rename(int $id, string $displayName): void
    {
        $this->authz->require('document', $id, 'manage');
        $doc = $this->get($id);
        if ($doc === null) {
            throw new NotFoundException('Document not found.');
        }

        $displayName = trim($displayName);
        if ($displayName === '') {
            return;
        }

        $doc->setDisplayName($displayName);
        $doc->setUpdatedAt(gmdate('c'));
        $this->em->persist($doc);
        $this->em->flush();
    }

    /**
     * Set the localized image texts (`alt` / `caption`) — user-editable via the Drive edit
     * modal, gated like {@see rename()} (effective `manage`, session principal). Each map is
     * `{ lang: text }`; only configured languages are kept, values are trimmed + control-char
     * stripped (they render into an HTML `alt` attribute and a `<figcaption>` — output stays
     * `e()`-escaped), empty strings dropped so the map stays sparse.
     *
     * @param array<string,string> $alt
     * @param array<string,string> $caption
     */
    public function setImageText(int $id, array $alt, array $caption): void
    {
        $this->authz->require('document', $id, 'manage');
        $doc = $this->get($id);
        if ($doc === null) {
            throw new NotFoundException('Document not found.');
        }

        $doc->setAlt($this->cleanTextMap($alt));
        $doc->setCaption($this->cleanTextMap($caption));
        $doc->setUpdatedAt(gmdate('c'));
        $this->em->persist($doc);
        $this->em->flush();
    }

    /**
     * Keep only configured languages, trim + strip control characters, drop empties.
     *
     * @param array<string,string> $map
     * @return array<string,string>
     */
    private function cleanTextMap(array $map): array
    {
        $out = [];
        foreach (DI::getI18n()->getLanguages() as $lang) {
            $val = preg_replace('/[\x00-\x1F\x7F]/', '', trim((string) ($map[$lang] ?? ''))) ?? '';
            if ($val !== '') {
                $out[$lang] = $val;
            }
        }

        return $out;
    }

    /**
     * Send a live document as an e-mail attachment via the configured {@see Mailer}
     * (Phase 6). The bytes come from `BlobStorage` (a variant may be selected); the
     * sender is the installation default (`config/mail`). Authorising the caller is the
     * controller's job — this only enforces the document policy.
     *
     * @param list<string> $recipients
     *
     * @throws NotFoundException   when the document is unknown, deleted, or its bytes are missing
     * @throws \RuntimeException    when the kind is not mailable or mail is not configured
     */
    public function send(int $id, array $recipients, string $subject, string $body, ?string $variant = null): void
    {
        $doc = $this->get($id);
        if ($doc === null) {
            throw new NotFoundException('Document not found.');
        }

        $kind = DocumentKind::tryFrom($doc->getKind());
        if ($kind === null || !$kind->mailable()) {
            throw new \RuntimeException("Documents of kind '{$doc->getKind()}' cannot be sent as an attachment.");
        }

        $variantName = $variant ?? BlobStorage::ORIGINAL;
        if ($variantName !== BlobStorage::ORIGINAL && !array_key_exists($variantName, $doc->getVariants())) {
            throw new NotFoundException("Unknown variant '{$variantName}'.");
        }

        $bytes = $this->blob->get($doc->getId(), $variantName, $this->variantExt($doc, $variantName));
        if ($bytes === null) {
            throw new NotFoundException('Document bytes not found.');
        }

        $message = (new Message())
            ->subject($subject)
            ->text($body)
            ->attach(new Attachment($doc->getOriginalName(), $doc->getMimeType(), $bytes));
        $message->to(...$recipients);

        Mailer::create()->send($message);
    }

    /**
     * Soft-delete: mark `deletedAt`, keep the bytes (retention applies only to a hard
     * purge). A no-op for an already-deleted or unknown document.
     */
    public function delete(int $id): void
    {
        $this->authz->require('document', $id, 'manage');
        $doc = $this->get($id);
        if ($doc === null) {
            return;
        }
        $doc->setDeletedAt(gmdate('c'));
        $doc->setUpdatedAt(gmdate('c'));
        $this->em->persist($doc);
        $this->em->flush();
        $this->rebuildMaterialization();
    }

    /**
     * Soft-deleted documents the current principal may `manage` (the trash),
     * most-recently-deleted first. Scoped in the domain, deny-by-default (RF-4a):
     * a caller never sees deleted documents outside their effective right
     * (admin bypass = all).
     *
     * @return Document[]
     */
    public function listDeleted(): array
    {
        $out = [];
        foreach ($this->documents->findAll() as $doc) {
            if ($doc->isDeleted() && $this->authz->allows('document', (int) $doc->getId(), 'manage')) {
                $out[] = $doc;
            }
        }
        usort($out, fn(Document $a, Document $b) => strcmp((string) $b->getDeletedAt(), (string) $a->getDeletedAt()));
        return $out;
    }

    /**
     * Restore a soft-deleted document (clear `deletedAt`). Its original folder must still
     * exist — a document always lives in a folder (option A); a public one is re-materialized.
     *
     * @throws NotFoundException   the document is unknown or not soft-deleted
     * @throws \RuntimeException    the original folder no longer exists
     */
    public function restore(int $id): void
    {
        $this->authz->require('document', $id, 'manage');
        $doc = $this->documents->find($id);
        if (!$doc instanceof Document || !$doc->isDeleted()) {
            throw new NotFoundException('Deleted document not found.');
        }
        $folderId = $doc->getFolderId();
        if ($folderId === null || !$this->folders->find($folderId) instanceof Folder) {
            throw new \RuntimeException('Der ursprüngliche Ordner existiert nicht mehr — Wiederherstellen nicht möglich.');
        }

        $doc->setDeletedAt(null);
        $doc->setUpdatedAt(gmdate('c'));
        $this->em->persist($doc);
        $this->em->flush();
        $this->rebuildMaterialization();
    }

    /**
     * Hard-purge a soft-deleted document: remove the metadata record AND all blob bytes.
     * Respects retention — a document whose `retentionUntil` lies in the future cannot be
     * purged. Only a soft-deleted document may be purged (delete first).
     *
     * @throws NotFoundException   the document is unknown or not soft-deleted
     * @throws \RuntimeException    retention is still active
     */
    public function purge(int $id): void
    {
        $this->authz->require('document', $id, 'manage');
        $doc = $this->documents->find($id);
        if (!$doc instanceof Document || !$doc->isDeleted()) {
            throw new NotFoundException('Deleted document not found.');
        }
        $until = $doc->getRetentionUntil();
        if ($until !== null && strtotime($until) > time()) {
            throw new \RuntimeException("Aufbewahrungsfrist läuft noch (bis {$until}) — endgültiges Löschen gesperrt.");
        }

        $this->blob->delete($id);   // all variants
        $this->em->remove($doc);    // metadata record
        $this->rebuildMaterialization();
    }

    /**
     * Move a document to another folder (metadata only, layout B). The target folder
     * must exist and the principal needs `write` on it (a manage-holder of a document
     * must not push it into a folder they cannot write to — RF-4a hardening).
     *
     * @throws NotFoundException on an unknown document or target folder
     */
    public function move(int $id, ?int $folderId): void
    {
        $this->authz->require('document', $id, 'manage');
        $doc = $this->get($id);
        if ($doc === null) {
            throw new NotFoundException('Document not found.');
        }

        // A document always lives in a folder (roots are folders, ADR-020).
        if ($folderId === null) {
            throw new \InvalidArgumentException('A document must live in a folder.');
        }
        $folder = $this->folders->find($folderId);
        if (!$folder instanceof Folder) {
            throw new NotFoundException('Target folder not found.');
        }
        // The drive root organizes partitions, it stores nothing (ADR-021 rule 2 — a
        // document there would never be publicly addressable).
        if ($folder->getParentId() === null) {
            throw new \InvalidArgumentException('Dokumente können nicht direkt im Drive-Root liegen.');
        }
        $this->authz->require('folder', $folderId, 'write');

        $doc->setFolderId($folderId);
        $doc->setUpdatedAt(gmdate('c'));
        $this->em->persist($doc);
        $this->em->flush();
        $this->rebuildMaterialization();
    }

    /**
     * Toggle a document's `active` output gate (ADR-017). An inactive document (or one
     * under an inactive ancestor folder) is never served. A no-op for an unknown document.
     *
     * @throws NotFoundException when the document is unknown or soft-deleted
     */
    public function setActive(int $id, bool $active): void
    {
        $this->authz->require('document', $id, 'manage');
        $doc = $this->get($id);
        if ($doc === null) {
            throw new NotFoundException('Document not found.');
        }

        $doc->setActive($active);
        $doc->setUpdatedAt(gmdate('c'));
        $this->em->persist($doc);
        $this->em->flush();
        $this->rebuildMaterialization();
    }

    /**
     * Set a document's own delivery mode (ADR-017 §3): `null` = inherit, else
     * `sealed|protected|public` (invalid values are coerced to `null` by the entity).
     * Opening beyond a `sealed` ancestor is rejected — the vault can never be opened.
     *
     * @throws NotFoundException on an unknown document
     * @throws \InvalidArgumentException when trying to open under a sealed ancestor
     */
    public function setDeliveryMode(int $id, ?string $mode): void
    {
        $this->authz->require('document', $id, 'manage');
        $doc = $this->get($id);
        if ($doc === null) {
            throw new NotFoundException('Document not found.');
        }
        $this->assertOpenable($mode, $doc->getFolderId());

        $doc->setDeliveryMode($mode);
        $doc->setUpdatedAt(gmdate('c'));
        $this->em->persist($doc);
        $this->em->flush();
        $this->rebuildMaterialization();
    }

    /**
     * Set a folder's own delivery mode (inherited by its subtree). Same rules as
     * {@see setDeliveryMode}; the ancestor cap walks the folder's parent chain.
     *
     * @throws NotFoundException on an unknown folder
     * @throws \InvalidArgumentException when trying to open under a sealed ancestor
     */
    public function setFolderDeliveryMode(int $folderId, ?string $mode): void
    {
        $this->authz->require('folder', $folderId, 'manage');
        $folder = $this->folders->find($folderId);
        if (!$folder instanceof Folder) {
            throw new NotFoundException('Folder not found.');
        }
        // The drive root's mode is fixed at `null` (ADR-021 rule 1): `sealed` would cap
        // every partition forever; an explicit mode would silently re-default the world.
        if ($folder->getParentId() === null) {
            throw new \RuntimeException('Der Modus des Drive-Root ist fest (Vererbungs-Standard).');
        }
        $this->assertOpenable($mode, $folder->getParentId());

        $folder->setDeliveryMode($mode);
        $this->em->persist($folder);
        $this->em->flush();
        $this->rebuildMaterialization();
    }

    /**
     * Toggle a folder's `active` output gate (ADR-017). Like {@see setActive} for documents:
     * an inactive folder hides its whole subtree from public delivery (materialization skips
     * it). A no-op for an unknown folder.
     *
     * @throws NotFoundException when the folder is unknown
     */
    public function setFolderActive(int $folderId, bool $active): void
    {
        $this->authz->require('folder', $folderId, 'manage');
        $folder = $this->folders->find($folderId);
        if (!$folder instanceof Folder) {
            throw new NotFoundException('Folder not found.');
        }
        // The drive root is always active (ADR-021 rule 1) — deactivating it would gate
        // the whole DMS off (`isActiveChain` walks through it).
        if ($folder->getParentId() === null) {
            throw new \RuntimeException('Der Drive-Root ist immer aktiv.');
        }
        $folder->setActive($active);
        $this->em->persist($folder);
        $this->em->flush();
        $this->rebuildMaterialization();
    }

    /**
     * The structural `sealed` cap: a resource must not declare a mode more open than a
     * `sealed` ancestor. `$ancestorStartId` is where the ancestor walk begins (a document →
     * its folder; a folder → its parent). `sealed` / `null` (inherit) are always allowed.
     */
    private function assertOpenable(?string $mode, ?int $ancestorStartId): void
    {
        if (($mode === 'protected' || $mode === 'public')
            && $this->resolveEffective(null, $ancestorStartId) === 'sealed') {
            throw new \InvalidArgumentException('Unter einem versiegelten Ordner kann kein offenerer Modus gesetzt werden.');
        }
    }

    /**
     * Grant a right on a resource to a subject (ADR-017 ACL). Idempotent per
     * `(resource, subject)`: an existing ACE is raised/lowered to `$rights` rather than
     * duplicated. ACEs are only consulted for `deliveryMode = protected` — the union /
     * ancestor walk / owner-admin bypass is `AclService` policy, not stored here.
     *
     * @param 'folder'|'document' $resourceType
     * @param 'user'|'role'       $subjectType  `user` → `$subjectId` is a user id; `role` → a role name
     * @param 'read'|'write'|'manage' $rights
     *
     * @throws \InvalidArgumentException on an invalid type/right or an empty subject id
     */
    public function grant(string $resourceType, int $resourceId, string $subjectType, string $subjectId, string $rights, ?int $createdBy = null): AccessControlEntry
    {
        $this->requireGrantLevel($resourceType, $resourceId);
        $this->assertResourceType($resourceType);
        if (!in_array($subjectType, ['user', 'role'], true)) {
            throw new \InvalidArgumentException("Invalid ACL subject type '{$subjectType}'.");
        }
        if (!in_array($rights, ['read', 'write', 'manage'], true)) {
            throw new \InvalidArgumentException("Invalid ACL right '{$rights}'.");
        }
        $subjectId = trim($subjectId);
        if ($subjectId === '') {
            throw new \InvalidArgumentException('ACL subject id must not be empty.');
        }

        $ace = $this->findAce($resourceType, $resourceId, $subjectType, $subjectId);
        if ($ace === null) {
            $ace = new AccessControlEntry();
            $ace->setResourceType($resourceType);
            $ace->setResourceId($resourceId);
            $ace->setSubjectType($subjectType);
            $ace->setSubjectId($subjectId);
            $ace->setCreatedBy($createdBy);
            $ace->setCreatedAt(gmdate('c'));
        }

        $ace->setRights($rights);
        $this->em->persist($ace);
        $this->em->flush();

        return $ace;
    }

    /**
     * Revoke the ACE granting a subject access on a resource. Returns the number removed
     * (0 when there was none — a no-op). Removes any duplicates defensively.
     *
     * @param 'folder'|'document' $resourceType
     * @param 'user'|'role'       $subjectType
     */
    public function revoke(string $resourceType, int $resourceId, string $subjectType, string $subjectId): int
    {
        $this->requireGrantLevel($resourceType, $resourceId);
        $this->assertResourceType($resourceType);

        $removed = 0;
        foreach ($this->aces->findByResource($resourceType, $resourceId) as $ace) {
            if ($ace->getSubjectType() === $subjectType && $ace->getSubjectId() === trim($subjectId)) {
                $this->em->remove($ace);
                $removed++;
            }
        }
        if ($removed > 0) {
            $this->em->flush();
        }

        return $removed;
    }

    /**
     * The single ACE for a `(resource, subject)` pair, or null. Defensive on duplicates
     * (returns the first); `grant()` keeps the pair unique going forward.
     */
    private function findAce(string $resourceType, int $resourceId, string $subjectType, string $subjectId): ?AccessControlEntry
    {
        foreach ($this->aces->findByResource($resourceType, $resourceId) as $ace) {
            if ($ace->getSubjectType() === $subjectType && $ace->getSubjectId() === $subjectId) {
                return $ace;
            }
        }
        return null;
    }

    /**
     * Direct ACEs stored on a resource (for the management panel). Does NOT resolve the
     * effective permission (owner/admin bypass + ancestor union — that is `AclService`).
     *
     * @param 'folder'|'document' $resourceType
     * @return AccessControlEntry[]
     */
    public function acesFor(string $resourceType, int $resourceId): array
    {
        $this->assertResourceType($resourceType);
        return $this->aces->findByResource($resourceType, $resourceId);
    }

    /**
     * Whether a `sealed` ancestor caps this resource (so opener modes must be blocked in
     * the UI). A document walks from its folder; a folder from its parent.
     *
     * @param 'folder'|'document' $resourceType
     */
    public function hasSealedAncestor(string $resourceType, int $resourceId): bool
    {
        $this->assertResourceType($resourceType);
        if ($resourceType === 'document') {
            $doc = $this->documents->find($resourceId);
            return $doc instanceof Document
                && $this->resolveEffective(null, $doc->getFolderId()) === 'sealed';
        }
        $folder = $this->folders->find($resourceId);
        return $folder instanceof Folder
            && $this->resolveEffective(null, $folder->getParentId()) === 'sealed';
    }

    private function assertResourceType(string $resourceType): void
    {
        if (!in_array($resourceType, ['folder', 'document'], true)) {
            throw new \InvalidArgumentException("Invalid ACL resource type '{$resourceType}'.");
        }
    }

    /**
     * The authorization gate for grant/revoke (ADR-021 / D5): on the drive root or a
     * partition (root-level folders) only a SUPER_USER may manage ACEs — a delegated
     * `manage` is NOT enough there (it would let an area admin widen their own
     * partition). Below a partition (subfolders, documents) the normal `manage` right
     * applies, so a SUPER_USER-granted area admin can delegate further.
     */
    private function requireGrantLevel(string $resourceType, int $resourceId): void
    {
        if ($resourceType === 'folder') {
            $folder = $this->folders->find($resourceId);
            if ($folder instanceof Folder && $this->isRootLevel($folder)) {
                $this->authz->requireSuperUser();
                return;
            }
        }
        $this->authz->require($resourceType, $resourceId, 'manage');
    }

    /** Whether the folder is the drive root or a partition (a direct child of it). */
    private function isRootLevel(Folder $folder): bool
    {
        if ($folder->getParentId() === null) {
            return true; // the drive root
        }
        $parent = $this->folderIndex()[$folder->getParentId()] ?? null;

        return $parent instanceof Folder && $parent->getParentId() === null;
    }

    // ── public materialization (ADR-017 §2 / R6) ─────────────────────────────────

    /**
     * Idempotent rebuild of the static public copy (ADR-017 / ADR-020): clear every
     * partition directory under `public/media`, then write every live, active-chain,
     * effectively-`public` document's bytes (original + image variants) to the path that
     * mirrors its `/media` URL — top segment = the root-folder slug. So the web server
     * serves them statically and only un-materialized public files ever reach the
     * {@see \Z77\Module\Dms\Ui\Controllers\Media\OutputController}. Pure function of
     * blob + metadata; carries no own state, so it is always safe to re-run. Called after
     * every DMS mutation that can change public delivery (mode / active / slug / folder /
     * delete). Clearing per CHILD directory also removes orphans of renamed human roots.
     * (Perf: fine for the low-volume DMS; a targeted diff is a later optimisation.)
     *
     * Wipe guard (S5): only direct children of `public/media` are removed — never
     * `public/media` itself, and never via a data-driven path concatenation.
     */
    public function rebuildMaterialization(): void
    {
        // Ensure the single-root invariant first (get-or-create + stray adoption,
        // ADR-021): every mutation path runs through here, so pre-ADR-021 data heals
        // before any path is derived.
        $this->driveRoot();

        $base = $this->mediaBase();
        if (is_dir($base)) {
            foreach (scandir($base) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $base . '/' . $entry;
                if (is_dir($path)) {
                    $this->rrmdir($path);
                } else {
                    @unlink($path);
                }
            }
        }

        foreach ($this->listAll() as $doc) {
            if ($this->effectiveDeliveryMode($doc) === 'public' && $this->isActiveChain($doc)) {
                $this->writeMaterialized($doc);
            }
        }
    }

    /** Absolute base of the materialized files under the docroot. */
    private function mediaBase(): string
    {
        return ABS_BASE_PATH . '/public/media';
    }

    /**
     * The document is servable openly only if it and every ancestor folder are active.
     * Public: consulted by both the materialization job (which files to write) and the
     * `OutputController` PHP fallback (so an un-materialized public doc under an inactive
     * ancestor is not leaked) — the two must agree.
     */
    public function isActiveChain(Document $doc): bool
    {
        if (!$doc->isActive()) {
            return false;
        }
        $index = $this->folderIndex();
        $cur   = $doc->getFolderId();
        $guard = 50;
        while ($cur !== null && $guard-- > 0) {
            $folder = $index[$cur] ?? null;
            if ($folder === null) {
                break;
            }
            if (!$folder->isActive()) {
                return false;
            }
            $cur = $folder->getParentId();
        }
        return true;
    }

    /** Write the original + every image variant to the materialized media path. */
    private function writeMaterialized(Document $doc): void
    {
        $relPath = $this->folderSlugPath($doc);
        if ($relPath === '') {
            // No resolvable folder chain — corrupt data; loud, never a write into the base (S5).
            throw new \RuntimeException(
                "Materialization: document {$doc->getId()} has no resolvable folder-slug chain."
            );
        }
        $dir = $this->mediaBase() . '/' . $relPath;

        $variants = array_merge([BlobStorage::ORIGINAL], array_keys($doc->getVariants()));
        foreach ($variants as $variant) {
            // Each variant carries its own extension (a video poster is `jpg`, not the video's ext).
            $ext   = $this->variantExt($doc, $variant);
            $bytes = $this->blob->get($doc->getId(), $variant, $ext);
            if ($bytes === null) {
                continue;
            }
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException("Materialization: cannot create directory '{$dir}'.");
            }
            $file = $dir . '/' . ($variant === BlobStorage::ORIGINAL
                ? "{$doc->getSlug()}.{$ext}"
                : "{$doc->getSlug()}.{$variant}.{$ext}");
            if (file_put_contents($file, $bytes, LOCK_EX) === false) {
                throw new \RuntimeException("Materialization: cannot write '{$file}'.");
            }
        }
    }

    /**
     * The document's folder-slug chain (partition → leaf, first segment = the partition
     * slug), joined for the media path. The drive root's own slug is NOT a segment
     * (ADR-021 rule 2). Empty string when the chain is broken, any segment has an empty
     * slug (S5 — the caller treats that as corrupt data), or the document sits directly
     * in the drive root (forbidden — never publicly addressable).
     */
    private function folderSlugPath(Document $doc): string
    {
        $folderId = $doc->getFolderId();
        if ($folderId === null) {
            return '';
        }
        $index = $this->folderIndex();
        $slugs = [];
        $cur   = $folderId;
        $guard = 50;
        while ($cur !== null && $guard-- > 0) {
            $folder = $index[$cur] ?? null;
            if ($folder === null) {
                return ''; // dangling parent — corrupt chain
            }
            if ($folder->getParentId() === null) {
                break; // the drive root — not a path segment (ADR-021 rule 2)
            }
            if ($folder->getSlug() === '') {
                return ''; // empty segment would corrupt the path (S5)
            }
            array_unshift($slugs, $folder->getSlug());
            $cur = $folder->getParentId();
        }
        return implode('/', $slugs);
    }

    /** @return array<int, Folder> */
    private function folderIndex(): array
    {
        $index = [];
        foreach ($this->folders->findAll() as $folder) {
            $id = $folder->getId();
            if ($id !== null) {
                $index[$id] = $folder;
            }
        }
        return $index;
    }

    /**
     * Reduced folder index (id → {parentId, slug}) for the whole tree, cached in APCu
     * (pattern {@see AclService::folderIndex}). Entity-free so it survives serialisation;
     * feeds {@see publicPathIndex}. Dropped on any Folder/Document write (`invalidatesCache`).
     *
     * @return array<int, array{parentId: ?int, slug: string}>
     */
    private function folderSlugIndex(): array
    {
        $cached = $this->cache->get(self::CACHE_NS, ['folders']);
        if ($cached !== null) {
            return $cached;
        }

        $index = [];
        foreach ($this->folders->findAll() as $folder) {
            $id = $folder->getId();
            if ($id !== null) {
                $index[$id] = ['parentId' => $folder->getParentId(), 'slug' => $folder->getSlug()];
            }
        }
        $this->cache->set(self::CACHE_NS, ['folders'], $index, cachePersist: true);

        return $index;
    }

    /**
     * The template-facing public-URL index: structural path (`"<folder-slug-chain>/<slug>.<ext>"`,
     * the SAME string a caller passes to {@see \mediaUrl()} / {@see urlForPath}) → the minimal
     * fields to build the URL. Cached in APCu; built once from the folder-slug index + every live
     * document, so a page-render resolving many images pays one folders + one documents load, then
     * O(1) lookups (instead of N× file decodes through the uncached repositories).
     *
     * Ambiguity is resolved like {@see resolve()}: if two live documents map to the same path
     * (same slug+ext in the same folder) the entry is dropped — never serve a guess. Soft-deleted
     * documents are excluded ({@see listAll}).
     *
     * @return array<string, array{relPath: string, slug: string, ext: string, checksum: string, variants: array, alt: array, caption: array, width: ?int, height: ?int}>
     */
    private function publicPathIndex(): array
    {
        $cached = $this->cache->get(self::CACHE_NS, ['publicPaths']);
        if ($cached !== null) {
            return $cached;
        }

        $folders   = $this->folderSlugIndex();
        $index     = [];
        $ambiguous = [];
        foreach ($this->listAll() as $doc) {
            $slug = $doc->getSlug();
            if ($slug === '') {
                continue;
            }
            $relPath = self::slugPathForFolder($doc->getFolderId(), $folders);
            if ($relPath === '') {
                continue; // no folder chain / corrupt / drive-root-only → not addressable
            }
            $key = $relPath . '/' . $slug . '.' . $doc->getExt();
            if (isset($index[$key])) {
                $ambiguous[$key] = true;
                continue;
            }
            $index[$key] = [
                'relPath'  => $relPath,
                'slug'     => $slug,
                'ext'      => $doc->getExt(),
                'checksum' => $doc->getChecksum(),
                'variants' => $doc->getVariants(),
                'alt'      => $doc->getAltMap(),
                'caption'  => $doc->getCaptionMap(),
                'width'    => $doc->getWidth(),
                'height'   => $doc->getHeight(),
            ];
        }
        foreach (array_keys($ambiguous) as $key) {
            unset($index[$key]);
        }
        $this->cache->set(self::CACHE_NS, ['publicPaths'], $index, cachePersist: true);

        return $index;
    }

    /**
     * Folder-slug chain for a folder id over the reduced index — the entity-free twin of
     * {@see folderSlugPath()}, same rules: the drive root (parentId null) is NOT a path segment
     * (ADR-021), an empty slug or a dangling parent yields '' so a corrupt chain is never turned
     * into a path.
     *
     * @param array<int, array{parentId: ?int, slug: string}> $folders
     */
    private static function slugPathForFolder(?int $folderId, array $folders): string
    {
        if ($folderId === null) {
            return '';
        }
        $slugs = [];
        $cur   = $folderId;
        $guard = 50;
        while ($cur !== null && $guard-- > 0) {
            $folder = $folders[$cur] ?? null;
            if ($folder === null) {
                return '';
            }
            if ($folder['parentId'] === null) {
                break; // drive root — not a path segment
            }
            if ($folder['slug'] === '') {
                return '';
            }
            array_unshift($slugs, $folder['slug']);
            $cur = $folder['parentId'];
        }

        return implode('/', $slugs);
    }

    /** Recursively remove a directory (materialization cleanup). */
    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * Keep only live documents.
     *
     * @param Document[] $docs
     * @return Document[]
     */
    private function live(array $docs): array
    {
        return array_values(array_filter($docs, fn(Document $d) => !$d->isDeleted()));
    }
}
