<?php

namespace Z77\Module\Dms\Blob;

/**
 * Local-filesystem {@see BlobStorage}. Stores blobs under
 *
 *   <basePath>/<shard>/<id>/<variant>.<ext>
 *
 * where `<shard> = floor(id / SHARD_SIZE)` bounds the number of per-id directories
 * per shard directory (so no single directory accumulates tens of thousands of
 * entries). The layout is human-traceable: id 42 → `0/42/`, id 12345 → `12/12345/`.
 *
 * Mirrors {@see \Z77\Persistence\File\Storage\FileStorage} in how it roots itself
 * (`ABS_BASE_PATH` + relative base) and writes (`LOCK_EX`). `id`, `variant` and `ext`
 * are validated defensively even though they are server-controlled — the path scheme
 * is the security boundary and must never accept a separator or `..`.
 */
final class LocalBlobStorage implements BlobStorage
{
    /** Per-id directories per shard directory. */
    private const SHARD_SIZE = 1000;

    private string $basePath;

    public function __construct(string $basePath = 'data/blobs')
    {
        $this->basePath = ABS_BASE_PATH . '/' . trim($basePath, '/') . '/';

        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0755, true);
        }
    }

    public function put(int $id, string $variant, string $ext, string $bytes): void
    {
        $file = $this->file($id, $variant, $ext);

        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("BlobStorage: cannot create directory '{$dir}'.");
        }

        if (file_put_contents($file, $bytes, LOCK_EX) === false) {
            throw new \RuntimeException("BlobStorage: cannot write blob '{$file}'.");
        }
    }

    public function putFile(int $id, string $variant, string $ext, string $sourcePath, bool $isUpload = true): void
    {
        $file = $this->file($id, $variant, $ext);

        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("BlobStorage: cannot create directory '{$dir}'.");
        }

        // Uploaded temp file → move_uploaded_file (also asserts it is a genuine upload).
        // Other source → rename, with a copy fallback for a cross-device source.
        $ok = $isUpload
            ? move_uploaded_file($sourcePath, $file)
            : (@rename($sourcePath, $file) ?: @copy($sourcePath, $file));

        if (!$ok) {
            throw new \RuntimeException("BlobStorage: cannot move source into blob '{$file}'.");
        }

        // Match the readable perms file_put_contents would produce (move keeps temp perms).
        @chmod($file, 0644);
    }

    public function get(int $id, string $variant, string $ext): ?string
    {
        $file = $this->file($id, $variant, $ext);
        if (!is_file($file)) {
            return null;
        }

        $bytes = file_get_contents($file);

        return $bytes === false ? null : $bytes;
    }

    public function path(int $id, string $variant, string $ext): ?string
    {
        $file = $this->file($id, $variant, $ext);

        return is_file($file) ? $file : null;
    }

    public function exists(int $id, string $variant, string $ext): bool
    {
        return is_file($this->file($id, $variant, $ext));
    }

    public function size(int $id, string $variant, string $ext): ?int
    {
        $file = $this->file($id, $variant, $ext);
        if (!is_file($file)) {
            return null;
        }

        $size = filesize($file);

        return $size === false ? null : $size;
    }

    public function delete(int $id): void
    {
        $dir = $this->dir($id);
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($dir);
    }

    /**
     * Absolute path of the per-id directory for $id.
     */
    private function dir(int $id): string
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException("BlobStorage: id must be positive, got {$id}.");
        }

        $shard = intdiv($id, self::SHARD_SIZE);

        return $this->basePath . $shard . '/' . $id;
    }

    /**
     * Absolute path of one variant file, with defensive validation of $variant/$ext.
     */
    private function file(int $id, string $variant, string $ext): string
    {
        if (!preg_match('/^[a-z0-9_-]+$/i', $variant)) {
            throw new \InvalidArgumentException("BlobStorage: invalid variant name '{$variant}'.");
        }
        if (!preg_match('/^[a-z0-9]+$/i', $ext)) {
            throw new \InvalidArgumentException("BlobStorage: invalid extension '{$ext}'.");
        }

        return $this->dir($id) . '/' . $variant . '.' . $ext;
    }
}
