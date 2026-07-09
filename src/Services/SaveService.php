<?php

namespace Z77\Module\Dms\Services;

use Z77\Core\DI;
use Z77\Module\Dms\Blob\BlobStorage;
use Z77\Module\Dms\Blob\LocalBlobStorage;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Module\Dms\Images\DocumentKind;
use Z77\Module\Dms\Images\GdImageProcessor;
use Z77\Module\Dms\Images\ImageProcessor;
use Z77\Module\Dms\Images\ImageProfileRegistry;
use Z77\Module\Dms\Entities\Document;
use Z77\Shared\Libraries\Convention\Naming;
use Z77\Shared\ValueObjects\UploadedFile;

/**
 * Source-independent persistence of a new document (Phase 3): validated bytes + meta →
 * `BlobStorage` (the bytes) + a `Document` record (the metadata). Serves both the HTTP
 * upload path (via `UploadService`) and module-generated files (`saveGenerated()`).
 *
 * **Two-phase save** — the blob key is the document `id`, and the `id` is only assigned
 * on flush (collection mode). So the record is persisted first to obtain the `id`, then
 * the original (and any image derivatives) are written under that `id`, then the record
 * is persisted again with the resulting `variants` map. Uploads are infrequent, so the
 * second flush is a non-issue.
 *
 * Not a DI singleton (placement decision B): the consumer builds it once via
 * {@see create()}; the constructor stays explicit for isolated testing.
 */
final class SaveService
{
    public function __construct(
        private BlobStorage $blob,
        private UnifiedEntityManager $em,
        private ImageProfileRegistry $profiles,
        private ImageProcessor $imageProcessor,
    ) {}

    public static function create(): self
    {
        return new self(
            new LocalBlobStorage(),
            DI::getUnifiedEntityManager(),
            ImageProfileRegistry::fromModules(),
            new GdImageProcessor(),
        );
    }

    /**
     * Persist an uploaded file's bytes + metadata. The MIME type in $req is the
     * server-side sniff; an unclassifiable type (not on the allowlist) is rejected.
     *
     * @throws \RuntimeException when the MIME type is not allowlisted
     */
    public function save(string $bytes, SaveRequest $req): Document
    {
        $this->assertFolderTarget($req->folderId);

        $ext  = strtolower(pathinfo($req->originalName, PATHINFO_EXTENSION));
        $kind = DocumentKind::fromMime($req->mimeType, $ext);
        if ($kind === null) {
            throw new \RuntimeException("SaveService: file type '{$req->mimeType}' is not allowed.");
        }

        $now = gmdate('c');
        $doc = new Document();
        $doc->setFolderId($req->folderId);
        $doc->setDisplayName($req->displayName !== '' ? $req->displayName : $req->originalName);
        $doc->setOriginalName($req->originalName);
        $doc->setExt($ext);
        $doc->setMimeType($req->mimeType);
        $doc->setKind($kind->value);
        $doc->setSizeBytes(strlen($bytes));
        $doc->setChecksum(hash('sha256', $bytes));
        $doc->setSource($req->source);
        $doc->setShowOriginal($req->showOriginal);
        $doc->setProfile($req->profile);
        $doc->setRetentionUntil($req->retentionUntil);
        $doc->setMeta($req->meta);
        // DMS rebuild (ADR-017 / R1): ownership, active gate, slug, image dimensions.
        $doc->setOwnerId($req->ownerId ?? $req->createdBy);
        $doc->setActive($req->active);
        $doc->setSlug($this->uniqueSlug(
            $req->displayName !== '' ? $req->displayName : $req->originalName,
            $doc->getFolderId(),
            $ext,
        ));
        if ($kind->hasImageVariants()) {
            $size = @getimagesizefromstring($bytes);
            if ($size !== false) {
                $doc->setWidth($size[0] ?? null);
                $doc->setHeight($size[1] ?? null);
            }
        }
        $doc->setCreatedBy($req->createdBy);
        $doc->setCreatedAt($now);
        $doc->setUpdatedAt($now);

        // Phase 1: persist to obtain the auto-increment id (= blob key).
        $this->em->persist($doc);
        $this->em->flush();
        $id = $doc->getId();

        // Phase 2: write the original, then derivatives, then record variants.
        $this->blob->put($id, BlobStorage::ORIGINAL, $ext, $bytes);

        if ($kind->hasImageVariants()) {
            $variants = $this->generateVariants($id, $ext, $bytes, $req);
            if ($variants !== []) {
                $doc->setVariants($variants);
                $doc->setUpdatedAt(gmdate('c'));
                $this->em->persist($doc);
                $this->em->flush();
            }
        }

        return $doc;
    }

    /**
     * Persist an UPLOADED file WITHOUT loading it into memory: the original is MOVED into
     * the blob (`move_uploaded_file`) and the checksum is streamed — so a multi-GB video
     * never enters PHP memory. Only an IMAGE original is read back (small) to produce its
     * `s/m/l/xl` derivatives. The caller ({@see UploadService}) may pass the checksum it
     * already computed for the duplicate check to avoid a second streamed pass.
     *
     * @throws \RuntimeException when the MIME type is not allowlisted or the temp file is unreadable
     */
    public function saveFromUpload(UploadedFile $file, SaveRequest $req, ?string $checksum = null): Document
    {
        $this->assertFolderTarget($req->folderId);

        $ext  = strtolower(pathinfo($req->originalName, PATHINFO_EXTENSION));
        $kind = DocumentKind::fromMime($req->mimeType, $ext);
        if ($kind === null) {
            throw new \RuntimeException("SaveService: file type '{$req->mimeType}' is not allowed.");
        }

        // Checksum + image dimensions MUST be read before the move (the temp path is gone
        // after it). `sha256()` streams the file; `imageSize()` reads only the header.
        $sum = $checksum ?? $file->sha256();
        if ($sum === null) {
            throw new \RuntimeException('SaveService: cannot read the uploaded temp file.');
        }

        $now = gmdate('c');
        $doc = new Document();
        $doc->setFolderId($req->folderId);
        $doc->setDisplayName($req->displayName !== '' ? $req->displayName : $req->originalName);
        $doc->setOriginalName($req->originalName);
        $doc->setExt($ext);
        $doc->setMimeType($req->mimeType);
        $doc->setKind($kind->value);
        $doc->setSizeBytes($file->size);
        $doc->setChecksum($sum);
        $doc->setSource($req->source);
        $doc->setShowOriginal($req->showOriginal);
        $doc->setProfile($req->profile);
        $doc->setRetentionUntil($req->retentionUntil);
        $doc->setMeta($req->meta);
        $doc->setOwnerId($req->ownerId ?? $req->createdBy);
        $doc->setActive($req->active);
        $doc->setSlug($this->uniqueSlug(
            $req->displayName !== '' ? $req->displayName : $req->originalName,
            $doc->getFolderId(),
            $ext,
        ));
        if ($kind->hasImageVariants()) {
            $dim = $file->imageSize();
            if ($dim !== null) {
                $doc->setWidth($dim[0]);
                $doc->setHeight($dim[1]);
            }
        }
        $doc->setCreatedBy($req->createdBy);
        $doc->setCreatedAt($now);
        $doc->setUpdatedAt($now);

        // Phase 1: persist to obtain the id (= blob key).
        $this->em->persist($doc);
        $this->em->flush();
        $id = (int) $doc->getId();

        // Phase 2: MOVE the original into the blob (no bytes in memory).
        $this->blob->putFile($id, BlobStorage::ORIGINAL, $ext, $file->tmpPath, true);

        // Phase 3: derivatives.
        if ($kind->hasImageVariants()) {
            // Image original — read the small original back from the blob and run GD.
            $variants = $this->variantsFromStoredOriginal($id, $ext, $req);
            if ($variants !== []) {
                $doc->setVariants($variants);
                $doc->setUpdatedAt(gmdate('c'));
                $this->em->persist($doc);
                $this->em->flush();
            }
        } elseif ($req->posterBytes !== null && $kind->acceptsPoster()) {
            // Video poster (P4): the ONE client image is the pixel source — run it through
            // the SAME `s/m/l/xl` pipeline as an image, and take the document's dimensions
            // from it. The variant blobs land under this video's id (`<id>/s.jpg` next to
            // `orig.mp4`); delivery + thumbnail then work exactly like an image.
            $variants = $this->generateVariants($id, 'jpg', $req->posterBytes, $req);
            $size     = @getimagesizefromstring($req->posterBytes);
            if ($size !== false) {
                $doc->setWidth($size[0] ?? null);
                $doc->setHeight($size[1] ?? null);
            }
            if ($variants !== [] || $size !== false) {
                $doc->setVariants($variants);
                $doc->setUpdatedAt(gmdate('c'));
                $this->em->persist($doc);
                $this->em->flush();
            }
        }

        return $doc;
    }

    /**
     * In-place overwrite of an uploaded document (the move-based sibling of {@see replace}):
     * MOVE the new original into the blob, stream the checksum, renew the byte-derived
     * fields; identity/ACL/mode/slug/URL stay. Authorization guards are the CALLER's job.
     *
     * @throws \RuntimeException when the new MIME type is not allowlisted or the temp file is unreadable
     */
    public function replaceFromUpload(Document $doc, UploadedFile $file, SaveRequest $req, ?string $checksum = null): Document
    {
        $ext  = $doc->getExt();
        $kind = DocumentKind::fromMime($req->mimeType, $ext);
        if ($kind === null) {
            throw new \RuntimeException("SaveService: file type '{$req->mimeType}' is not allowed.");
        }

        $sum = $checksum ?? $file->sha256();
        if ($sum === null) {
            throw new \RuntimeException('SaveService: cannot read the uploaded temp file.');
        }

        $w = $h = null;
        if ($kind->hasImageVariants()) {
            $dim = $file->imageSize();
            if ($dim !== null) {
                [$w, $h] = $dim;
            }
        }

        $id = (int) $doc->getId();

        // Clear old variants, then move the new original in.
        $this->blob->delete($id);
        $this->blob->putFile($id, BlobStorage::ORIGINAL, $ext, $file->tmpPath, true);

        // TODO poster on overwrite (P4): a re-uploaded video drops its old poster variants and
        // gets none back here. Applying `$req->posterBytes` on the overwrite path is optional
        // for the first cut — wire it the same way as `saveFromUpload` when needed.
        $variants = $kind->hasImageVariants()
            ? $this->variantsFromStoredOriginal($id, $ext, $req)
            : [];

        $doc->setMimeType($req->mimeType);
        $doc->setKind($kind->value);
        $doc->setSizeBytes($file->size);
        $doc->setChecksum($sum);
        $doc->setShowOriginal($req->showOriginal);
        $doc->setVariants($variants);
        $doc->setWidth($w);
        $doc->setHeight($h);
        $doc->setUpdatedAt(gmdate('c'));
        $this->em->persist($doc);
        $this->em->flush();

        return $doc;
    }

    /**
     * Read a stored (image) original back from the blob and generate its derivatives. The
     * original was just moved in, so it is on disk; images are small enough to read whole
     * (GD needs the pixels), and `GdImageProcessor` guards its own pixel-memory budget.
     *
     * @return array<string, array{w:int,h:int,bytes:int}>
     */
    private function variantsFromStoredOriginal(int $id, string $ext, SaveRequest $req): array
    {
        $path = $this->blob->path($id, BlobStorage::ORIGINAL, $ext);
        if ($path === null) {
            return [];
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            return [];
        }

        return $this->generateVariants($id, $ext, $bytes, $req);
    }

    /**
     * Replace an existing document's BYTES in place (upload overwrite): the byte-derived
     * fields (mime, kind, size, checksum, dimensions) and the image variants renew; the
     * identity and access state — `id` (= blob key), `slug`, `/media` URL, owner, ACL,
     * `deliveryMode`, `active`, `createdBy/At` — stay untouched. `showOriginal` follows
     * the new request (chosen in the upload modal); the stored `profile` is kept.
     *
     * Same original name ⇒ same extension, so the blob files are simply rewritten.
     * Transactionless like {@see save} (ARCH-A003): a blob failure mid-replace can leave
     * bytes/metadata inconsistent — the record is only flushed after the blob writes.
     * Authorization/policy guards (manage, sealed, retention) are the CALLER's job
     * ({@see UploadService}); this is byte/metadata mechanics only.
     *
     * @throws \RuntimeException when the new MIME type is not allowlisted
     */
    public function replace(Document $doc, string $bytes, SaveRequest $req): Document
    {
        $ext  = $doc->getExt();
        $kind = DocumentKind::fromMime($req->mimeType, $ext);
        if ($kind === null) {
            throw new \RuntimeException("SaveService: file type '{$req->mimeType}' is not allowed.");
        }

        $id = (int) $doc->getId();

        // Bytes first: clear ALL old blob files (a shrinking variant set must not leave
        // orphans), then write the new original + derivatives.
        $this->blob->delete($id);
        $this->blob->put($id, BlobStorage::ORIGINAL, $ext, $bytes);

        $variants = [];
        if ($kind->hasImageVariants()) {
            $variants = $this->generateVariants($id, $ext, $bytes, $req);
        }

        $doc->setMimeType($req->mimeType);
        $doc->setKind($kind->value);
        $doc->setSizeBytes(strlen($bytes));
        $doc->setChecksum(hash('sha256', $bytes));
        $doc->setShowOriginal($req->showOriginal);
        $doc->setVariants($variants);
        $doc->setWidth(null);
        $doc->setHeight(null);
        if ($kind->hasImageVariants()) {
            $size = @getimagesizefromstring($bytes);
            if ($size !== false) {
                $doc->setWidth($size[0] ?? null);
                $doc->setHeight($size[1] ?? null);
            }
        }
        $doc->setUpdatedAt(gmdate('c'));
        $this->em->persist($doc);
        $this->em->flush();

        return $doc;
    }

    /**
     * Convenience for module-generated files (e.g. invoice PDFs): same pipeline,
     * `source = generated`.
     */
    public function saveGenerated(string $bytes, SaveRequest $req): Document
    {
        return $this->save($bytes, new SaveRequest(
            originalName: $req->originalName,
            mimeType: $req->mimeType,
            folderId: $req->folderId,
            displayName: $req->displayName,
            source: 'generated',
            profile: $req->profile,
            showOriginal: $req->showOriginal,
            createdBy: $req->createdBy,
            retentionUntil: $req->retentionUntil,
            meta: $req->meta,
            ownerId: $req->ownerId,
            active: $req->active,
        ));
    }

    /**
     * Generate, store, and describe the image derivatives for one document.
     *
     * @return array<string, array{w:int,h:int,bytes:int}>
     */
    private function generateVariants(int $id, string $ext, string $bytes, SaveRequest $req): array
    {
        $specs = $this->specsFor($req);

        $variants = [];
        foreach ($this->imageProcessor->generate($bytes, $ext, $specs) as $name => $pv) {
            $this->blob->put($id, $name, $pv->ext, $pv->bytes);
            $variants[$name] = $pv->toMeta();
        }

        return $variants;
    }

    /**
     * Which variant specs to produce: when the original is preserved (per-document
     * `showOriginal` or per-profile `preserveOriginal`), only the framework thumbnail;
     * otherwise the full `admin` set plus the selected project profile.
     *
     * @return list<\Z77\Module\Dms\Images\VariantSpec>
     */
    private function specsFor(SaveRequest $req): array
    {
        $admin   = $this->profiles->admin();
        $profile = null;

        if ($req->profile !== null) {
            // Project profiles are module-owned and resolved via the partition's root
            // `key` (ADR-020 / S7). A document under a keyless (human-created) root has
            // no module profiles — only `admin` resolves there.
            $rootKey = $this->rootKeyOf($req->folderId);
            $profile = $rootKey !== null ? $this->profiles->get($rootKey, $req->profile) : null;
            if ($profile === null && $req->profile !== ImageProfileRegistry::ADMIN) {
                throw new \RuntimeException(
                    "SaveService: image profile '{$req->profile}' is not defined for root '"
                    . ($rootKey ?? '(keyless)') . "'."
                );
            }
            $profile ??= $this->profiles->admin();
        }

        $preserve = $req->showOriginal || ($profile?->preserveOriginal ?? false);

        if ($preserve) {
            $thumb = $admin->variant(ImageProfileRegistry::ADMIN_THUMB);
            return $thumb !== null ? [$thumb] : [];
        }

        $specs = array_values($admin->variants);
        if ($profile !== null) {
            foreach ($profile->variants as $spec) {
                $specs[] = $spec;
            }
        }

        return $specs;
    }

    /**
     * A save target must be a real folder BELOW the drive root: documents always live in
     * a folder (ADR-020), and never directly in the drive root (ADR-021 rule 2 — it
     * organizes partitions and is no public path segment, so such a document could never
     * be addressed).
     */
    private function assertFolderTarget(?int $folderId): void
    {
        if ($folderId === null) {
            throw new \RuntimeException('SaveService: a document must be saved into a folder.');
        }
        $folder = $this->em->getRepository(\Z77\Module\Dms\Entities\Folder::class)->find($folderId);
        if ($folder !== null && $folder->getParentId() === null) {
            throw new \RuntimeException('SaveService: the drive root cannot store documents (ADR-021).');
        }
    }

    /**
     * The `key` of the PARTITION the folder belongs to (the chain link just below the
     * drive root, ADR-021), or null (keyless human partition / broken chain / the drive
     * root itself). Derived, never stored (ADR-020).
     */
    private function rootKeyOf(?int $folderId): ?string
    {
        $repo  = $this->em->getRepository(\Z77\Module\Dms\Entities\Folder::class);
        $index = [];
        foreach ($repo->findAll() as $folder) {
            if ($folder->getId() !== null) {
                $index[$folder->getId()] = $folder;
            }
        }

        $cur   = $folderId;
        $prev  = null;
        $guard = 50;
        while ($cur !== null && $guard-- > 0) {
            $folder = $index[$cur] ?? null;
            if ($folder === null) {
                return null;
            }
            if ($folder->getParentId() === null) {
                // $folder is the drive root; the previous link is the partition.
                return $prev?->getKey();
            }
            $prev = $folder;
            $cur  = $folder->getParentId();
        }

        return null;
    }

    /**
     * URL-safe document slug, unique among the live documents that share the same folder
     * AND extension (ADR-017 §8 / R5). The structural `/media` resolve matches a path leaf
     * on slug + ext and refuses to serve when more than one live document matches
     * ({@see DocumentService::resolve}); de-duplicating here guarantees that never happens.
     *
     * The base is {@see Naming::toSlug} of the file name (extension dropped); `-2`, `-3`…
     * break a collision (same scheme as folder slugs, {@see FolderController::uniqueSlug}).
     * A punctuation-only name falls back to `datei`. Documents of a different extension in
     * the same folder do not collide (the leaf disambiguates by ext).
     */
    private function uniqueSlug(string $name, ?int $folderId, string $ext): string
    {
        $base = Naming::toSlug(pathinfo($name, PATHINFO_FILENAME));
        if ($base === '') {
            $base = 'datei';
        }

        $taken = [];
        foreach ($this->em->getRepository(Document::class)->findByFolder($folderId) as $sibling) {
            if (!$sibling->isDeleted() && $sibling->getExt() === $ext) {
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
}
