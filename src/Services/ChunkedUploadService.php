<?php

namespace Z77\Module\Dms\Services;

use Z77\Core\DI;
use Z77\Module\Dms\Entities\Document;
use Z77\Module\Dms\Images\UploadTypes;
use Z77\Shared\Upload\UploadPolicy;
use Z77\Shared\ValueObjects\UploadedFile;

/**
 * Chunked upload (F3, z77-rudaz.ch 2026-10-31): a file of several GB enters the DMS in
 * pieces, because one request is capped by the host (cyon: 500 MB) and by PHP
 * (`upload_max_filesize` / `post_max_size`). HTTP-agnostic — the endpoints are
 * {@see \Z77\Module\Dms\Ui\ChunkedUploadTrait}, the browser client is
 * `documents/chunk-upload.js`.
 *
 *   begin(folderId, name, size)  → uploadId   gate: `write` on the folder (session principal)
 *   append(uploadId, index, chunk)            same principal, gate again, exact chunk size
 *   status(uploadId)             → received   resume after a disconnect
 *   finish(uploadId)             → Document   THE upload pipeline ({@see UploadService::saveAssembled})
 *   abort(uploadId)                           the owner gives up
 *   cleanup(olderThan)           → count      abandoned uploads ({@see \Z77\Module\Dms\Jobs\ChunkCleanupJob})
 *
 * **Where the bytes wait.** `data/dms/chunks/<uploadId>/` — outside the web root, never
 * under `public/`. `uploadId` is 32 random hex chars and the ONLY thing a client names; it
 * is validated before it touches a path. The directory holds `manifest.json` (folder,
 * name, size, chunk size, the owner's hash, flags, the received indices) and `data.bin`.
 *
 * **Every chunk is written straight to its offset in `data.bin`** (`index × chunkBytes`)
 * instead of into a part file of its own. Deliberate deviation from «parts, concatenated at
 * finish»: a concatenation copies every byte of a multi-GB file once more INSIDE the finish
 * request (time limit) and needs twice the disk while it runs. With offsets the file is
 * complete when the last chunk lands, in any order, and a re-sent index simply overwrites
 * its own range. `finish()` then only checks, hashes (streamed) and renames it into the blob.
 *
 * **Who may.** begin gates `write` on the target folder FIRST — the same domain gate as a
 * single-request upload; append and finish gate it again (a grant may be revoked midway),
 * and append/status/finish/abort answer «not found» to anyone but the identity that began
 * the upload (stored as a hash of realm + id, so a member and a backend user with the same
 * id never meet). The kind is decided at finish on the SNIFFED bytes by the one pipeline;
 * begin refuses only what is certain from the name alone: a server-executable extension.
 *
 * **Concurrency.** append and finish hold an exclusive `flock` on the manifest; a second
 * finish that waited finds the directory gone and answers «not found».
 *
 * Seams for the offline harness (`tests/dms-chunked-upload.php`): the write gate, the owner
 * key and the final store are closures — `create()` binds them to {@see Authz}, the session
 * identity and {@see UploadService::saveAssembled}. `Authz` is final and reads the session,
 * so it cannot stand in a CLI test; the closures keep the production wiring in ONE place.
 */
final class ChunkedUploadService
{
    /** Default chunk size when dmsConfig names none. */
    public const DEFAULT_CHUNK_BYTES = 8 * 1024 * 1024;

    /** An abandoned upload is removed after this long without a chunk (job + begin sweep). */
    public const TTL_SECONDS = 86400;

    private const MIN_CHUNK_BYTES = 64 * 1024;
    private const ID              = '/^[a-f0-9]{32}$/';
    private const MANIFEST        = 'manifest.json';
    private const DATA            = 'data.bin';

    /** Disk head-room kept free beyond the declared size before an upload may begin. */
    private const DISK_RESERVE = 64 * 1024 * 1024;

    /**
     * @param string   $baseDir      absolute directory holding one sub-directory per upload
     * @param int      $chunkBytes   the chunk size the client must use (already clamped)
     * @param int|null $maxBytes     total per file; null = no cap beyond the free disk space
     * @param \Closure $requireWrite fn(int $folderId): void — throws unless the session principal may write
     * @param \Closure $owner        fn(): string — stable key of the session identity, '' = nobody
     * @param \Closure $store        fn(UploadedFile $file, int $folderId, bool $overwrite,
     *                               bool $showOriginal, ?UploadedFile $poster): Document
     */
    public function __construct(
        private string $baseDir,
        private int $chunkBytes,
        private ?int $maxBytes,
        private UploadTypes $types,
        private \Closure $requireWrite,
        private \Closure $owner,
        private \Closure $store,
    ) {
        $this->baseDir = rtrim($baseDir, '/\\');
        if ($this->chunkBytes < 1) {
            throw new \InvalidArgumentException('ChunkedUploadService: chunkBytes must be positive.');
        }
    }

    public static function create(): self
    {
        $config = DI::getConfigManager()->getArrayConfig('App/Config/dmsConfig', 'Z77\\Module\\Dms');
        $chunk  = (int) ($config->get('chunkBytes', self::DEFAULT_CHUNK_BYTES) ?: self::DEFAULT_CHUNK_BYTES);
        $max    = $config->get('maxUploadBytes', null);
        $authz  = Authz::create();

        return new self(
            ABS_BASE_PATH . '/data/dms/chunks',
            self::effectiveChunkBytes($chunk, UploadService::serverMaxBytes()),
            is_numeric($max) && (int) $max > 0 ? (int) $max : null,
            UploadTypes::fromConfig(),
            static function (int $folderId) use ($authz): void {
                $authz->require('folder', $folderId, 'write');
            },
            static function (): string {
                $user = DI::getAuthService()->getCurrentUser();
                return $user !== null && $user->isLoggedIn() ? $user->getRealm() . ':' . $user->getId() : '';
            },
            static function (UploadedFile $file, int $folderId, bool $overwrite, bool $showOriginal, ?UploadedFile $poster): Document {
                // createdBy/owner are backend ids (Principal::fromAuthUser) — a member's
                // string id is a different id space and must not land there.
                $user      = DI::getAuthService()->getCurrentUser();
                $createdBy = $user !== null && $user->isBackendRealm() && is_int($user->getId()) ? $user->getId() : null;

                return UploadService::create()->saveAssembled($file, $folderId, $showOriginal, $createdBy, $overwrite, $poster);
            },
        );
    }

    /**
     * The chunk size that fits through ONE request of this server: the configured one, but
     * below the PHP transport cap minus head-room for the multipart envelope and the other
     * fields (1 MB, or a quarter of a small cap), never below 64 KB.
     */
    public static function effectiveChunkBytes(int $configured, int $serverMaxBytes): int
    {
        $configured = $configured > 0 ? $configured : self::DEFAULT_CHUNK_BYTES;
        $headroom   = min(1024 * 1024, intdiv($serverMaxBytes, 4));

        return max(self::MIN_CHUNK_BYTES, min($configured, $serverMaxBytes - $headroom));
    }

    /** The chunk size every chunk but the last must have. */
    public function chunkBytes(): int
    {
        return $this->chunkBytes;
    }

    /** The total cap per file, null = none beyond the disk. */
    public function maxBytes(): ?int
    {
        return $this->maxBytes;
    }

    /**
     * Open an upload. Gate first, then the name, the size and the certain refusals.
     *
     * @return string the upload id (32 hex)
     * @throws ChunkUploadException readable refusal
     * @throws \Z77\Core\Exception\NotFoundException no `write` on the folder
     */
    public function begin(int $folderId, string $name, int $size, bool $overwrite = false, bool $showOriginal = false): string
    {
        ($this->requireWrite)($folderId);

        $owner = ($this->owner)();
        if ($owner === '') {
            throw new ChunkUploadException('Bitte zuerst anmelden.', 403);
        }

        $name = self::cleanName($name);
        if ($name === '') {
            throw new ChunkUploadException('Ungültiger Dateiname.', 400);
        }
        if ($size <= 0) {
            throw new ChunkUploadException('Leere Datei.', 400);
        }
        if ($this->maxBytes !== null && $size > $this->maxBytes) {
            throw new ChunkUploadException('Datei zu gross (max. ' . UploadPolicy::formatBytes($this->maxBytes) . ').', 413);
        }
        // Certain from the name alone (DMS-MEDIA-PHP-001): never accepted, whatever the bytes.
        // Everything else is decided on the sniffed bytes at finish.
        if (UploadTypes::isExecutable(strtolower(pathinfo($name, PATHINFO_EXTENSION)))) {
            throw new ChunkUploadException('Dateityp nicht erlaubt.', 415);
        }

        $this->ensureBaseDir();
        // Abandoned uploads go with the next begin as well — the job is the backstop for
        // an installation where nobody uploads any more.
        $this->cleanup(self::TTL_SECONDS);

        $free = @disk_free_space($this->baseDir);
        if (is_float($free) && $free < $size + self::DISK_RESERVE) {
            throw new ChunkUploadException('Nicht genug Speicherplatz auf dem Server für diese Datei.', 507);
        }

        $uploadId = bin2hex(random_bytes(16));
        $dir      = $this->baseDir . '/' . $uploadId;
        if (!@mkdir($dir, 0700) && !is_dir($dir)) {
            throw new \RuntimeException("ChunkedUploadService: cannot create '{$dir}'.");
        }
        if (@file_put_contents($dir . '/' . self::DATA, '') === false) {
            throw new \RuntimeException("ChunkedUploadService: cannot create the data file in '{$dir}'.");
        }

        $manifest = [
            'version'      => 1,
            'folderId'     => $folderId,
            'name'         => $name,
            'size'         => $size,
            'chunkBytes'   => $this->chunkBytes,
            'chunks'       => intdiv($size + $this->chunkBytes - 1, $this->chunkBytes),
            'owner'        => hash('sha256', $owner),
            'created'      => gmdate('c'),
            'overwrite'    => $overwrite,
            'showOriginal' => $showOriginal,
            'received'     => [],
        ];
        if (@file_put_contents($dir . '/' . self::MANIFEST, json_encode($manifest, JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
            throw new \RuntimeException("ChunkedUploadService: cannot write the manifest in '{$dir}'.");
        }

        return $uploadId;
    }

    /**
     * Record one chunk: its bytes go to their offset in the data file. Every chunk but the
     * last MUST be exactly `chunkBytes`, the last exactly the rest. Re-sending an index
     * overwrites its range (idempotent).
     *
     * Integrity (owner 2026-10-31): `$sha256` is the hash the BROWSER computed over the
     * chunk it sliced from the original; the received bytes must hash to it, otherwise the
     * chunk is refused with `retry` and the client sends it again. Every chunk verified at
     * its fixed offset + the exact total size = the assembled file IS the original.
     *
     * @return int how many chunks are now received
     */
    public function append(string $uploadId, int $index, UploadedFile $chunk, string $sha256): int
    {
        $sha256 = strtolower(trim($sha256));
        if (preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1) {
            throw new ChunkUploadException('Prüfsumme des Teilstücks fehlt oder ist ungültig.', 400);
        }

        return $this->withManifest($uploadId, function (array $m, \Closure $save, string $dir) use ($index, $chunk, $sha256): int {
            ($this->requireWrite)((int) $m['folderId']);

            $chunks = (int) $m['chunks'];
            if ($index < 0 || $index >= $chunks) {
                throw new ChunkUploadException('Ungültige Teilstück-Nummer.', 400);
            }
            if (!$chunk->isOk()) {
                throw new ChunkUploadException('Teilstück nicht empfangen.', 400);
            }

            $chunkBytes = (int) $m['chunkBytes'];
            $expected   = $index < $chunks - 1 ? $chunkBytes : (int) $m['size'] - ($chunks - 1) * $chunkBytes;
            clearstatcache(true, $chunk->tmpPath);
            if (filesize($chunk->tmpPath) !== $expected) {
                throw new ChunkUploadException('Teilstück hat die falsche Grösse.', 400);
            }
            $got = hash_file('sha256', $chunk->tmpPath);
            if ($got === false || !hash_equals($sha256, $got)) {
                throw new ChunkUploadException('Teilstück beschädigt angekommen — es wird erneut gesendet.', 422, retry: true);
            }

            $in  = @fopen($chunk->tmpPath, 'rb');
            $out = @fopen($dir . '/' . self::DATA, 'c+b');
            if ($in === false || $out === false) {
                throw new \RuntimeException('ChunkedUploadService: cannot open the chunk or the data file.');
            }
            try {
                if (fseek($out, $index * $chunkBytes) !== 0 || stream_copy_to_stream($in, $out) !== $expected) {
                    throw new \RuntimeException('ChunkedUploadService: writing the chunk failed.');
                }
                fflush($out);
            } finally {
                fclose($in);
                fclose($out);
            }

            $received   = array_map('intval', (array) $m['received']);
            $received[] = $index;
            $received   = array_values(array_unique($received));
            sort($received);
            $m['received'] = $received;
            $save($m);

            return count($received);
        });
    }

    /**
     * What the server holds for this upload — the client resumes with the missing indices.
     *
     * @return array{name: string, size: int, chunkBytes: int, chunks: int, received: list<int>}
     */
    public function status(string $uploadId): array
    {
        return $this->withManifest($uploadId, static fn (array $m): array => [
            'name'       => (string) $m['name'],
            'size'       => (int) $m['size'],
            'chunkBytes' => (int) $m['chunkBytes'],
            'chunks'     => (int) $m['chunks'],
            'received'   => array_map('intval', (array) $m['received']),
        ]);
    }

    /**
     * All chunks there → the data file goes through THE upload pipeline. The upload
     * directory is removed afterwards — on success AND on a refusal (type, duplicate, …) —
     * with one exception: a NAME CONFLICT keeps it, so the user's «überschreiben» can
     * finish again with `$overwrite = true` without sending gigabytes once more (the job
     * removes it if nobody answers).
     *
     * @param bool|null $overwrite null = the flag given at begin
     * @throws DuplicateUploadException|NameConflictException as {@see UploadService::save}
     * @throws ChunkUploadException incomplete, size mismatch, unknown upload
     */
    public function finish(string $uploadId, ?bool $overwrite = null, ?UploadedFile $poster = null): Document
    {
        [$doc, $error, $discard] = $this->withManifest(
            $uploadId,
            function (array $m, \Closure $save, string $dir) use ($overwrite, $poster): array {
                ($this->requireWrite)((int) $m['folderId']);

                $missing = (int) $m['chunks'] - count((array) $m['received']);
                if ($missing > 0) {
                    throw new ChunkUploadException("Upload unvollständig: {$missing} Teilstück(e) fehlen.", 409);
                }
                $data = $dir . '/' . self::DATA;
                clearstatcache(true, $data);
                if (!is_file($data) || filesize($data) !== (int) $m['size']) {
                    throw new ChunkUploadException('Die empfangene Datei hat nicht die angekündigte Grösse.', 409);
                }

                $file = new UploadedFile((string) $m['name'], $data, (int) $m['size']);
                try {
                    $doc = ($this->store)($file, (int) $m['folderId'], $overwrite ?? (bool) $m['overwrite'], (bool) $m['showOriginal'], $poster);
                    return [$doc, null, true];
                } catch (NameConflictException $e) {
                    return [null, $e, false];
                } catch (\Throwable $e) {
                    return [null, $e, true];
                }
            }
        );

        if ($discard) {
            $this->removeDir($this->baseDir . '/' . $uploadId);
        }
        if ($error !== null) {
            throw $error;
        }

        return $doc;
    }

    /** The owner gives up: the upload and its bytes are removed. */
    public function abort(string $uploadId): void
    {
        $this->withManifest($uploadId, static fn (): bool => true);
        $this->removeDir($this->baseDir . '/' . $uploadId);
    }

    /**
     * Remove uploads that received nothing for `$olderThanSeconds` (the manifest is
     * rewritten with every chunk, so its mtime is the last activity). An upload whose
     * manifest is locked right now (a chunk or a finish in flight) is left alone.
     *
     * @return int removed uploads
     */
    public function cleanup(int $olderThanSeconds = self::TTL_SECONDS): int
    {
        if (!is_dir($this->baseDir)) {
            return 0;
        }
        $removed = 0;
        $cutoff  = time() - max(0, $olderThanSeconds);
        foreach (scandir($this->baseDir) ?: [] as $entry) {
            $dir = $this->baseDir . '/' . $entry;
            if (preg_match(self::ID, $entry) !== 1 || !is_dir($dir)) {
                continue;
            }
            $manifest = $dir . '/' . self::MANIFEST;
            clearstatcache(true, $manifest);
            $touched = is_file($manifest) ? (int) filemtime($manifest) : (int) filemtime($dir);
            if ($touched >= $cutoff) {
                continue;
            }
            $h = is_file($manifest) ? @fopen($manifest, 'rb') : null;
            if ($h === false || ($h !== null && !flock($h, LOCK_EX | LOCK_NB))) {
                if (is_resource($h)) {
                    fclose($h);
                }
                continue; // busy right now — the next sweep takes it
            }
            if ($h !== null) {
                flock($h, LOCK_UN);
                fclose($h);
            }
            $this->removeDir($dir);
            $removed++;
        }

        return $removed;
    }

    /**
     * The file name as it will be stored: last path segment, no control characters,
     * trimmed; '' = unusable.
     */
    public static function cleanName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $pos  = strrpos($name, '/');
        if ($pos !== false) {
            $name = substr($name, $pos + 1);
        }
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name));

        return $name === '.' || $name === '..' || strlen($name) > 255 ? '' : $name;
    }

    /**
     * Run `$fn(manifest, save, dir)` under an exclusive lock on the manifest, after the id
     * and the owner were checked. Unknown id, removed upload, other owner — all «not found».
     */
    private function withManifest(string $uploadId, \Closure $fn): mixed
    {
        if (preg_match(self::ID, $uploadId) !== 1) {
            throw self::notFound();
        }
        $dir  = $this->baseDir . '/' . $uploadId;
        $path = $dir . '/' . self::MANIFEST;
        $h    = is_file($path) ? @fopen($path, 'r+b') : false;
        if ($h === false) {
            throw self::notFound();
        }

        try {
            if (!flock($h, LOCK_EX)) {
                throw new \RuntimeException('ChunkedUploadService: cannot lock the manifest.');
            }
            clearstatcache(true, $path);
            if (!is_file($path)) {
                throw self::notFound(); // removed while we waited (a finish or an abort)
            }
            $m = json_decode((string) stream_get_contents($h, -1, 0), true);
            if (!is_array($m) || !isset($m['folderId'], $m['name'], $m['size'], $m['chunkBytes'], $m['chunks'], $m['owner'])) {
                throw self::notFound();
            }
            $owner = ($this->owner)();
            if ($owner === '' || !hash_equals((string) $m['owner'], hash('sha256', $owner))) {
                throw self::notFound();
            }

            $save = static function (array $manifest) use ($h): void {
                $json = json_encode($manifest, JSON_UNESCAPED_UNICODE);
                if (!ftruncate($h, 0) || !rewind($h) || fwrite($h, (string) $json) !== strlen((string) $json)) {
                    throw new \RuntimeException('ChunkedUploadService: cannot write the manifest.');
                }
                fflush($h);
            };

            return $fn($m, $save, $dir);
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }

    private function ensureBaseDir(): void
    {
        if (!is_dir($this->baseDir) && !@mkdir($this->baseDir, 0700, true) && !is_dir($this->baseDir)) {
            throw new \RuntimeException("ChunkedUploadService: cannot create '{$this->baseDir}'.");
        }
    }

    /** The upload directory holds only flat files (manifest, data). */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($dir . '/' . $entry);
            }
        }
        @rmdir($dir);
    }

    private static function notFound(): ChunkUploadException
    {
        return new ChunkUploadException('Upload nicht gefunden — bitte die Datei neu hochladen.', 404);
    }
}
