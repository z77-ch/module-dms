<?php

namespace Z77\Module\Dms\Images;

/**
 * GD-backed {@see ImageProcessor} (OPEN-8). Chosen over Imagick because GD ships as a
 * PHP extension (no Composer dependency). Quality is pushed well past GD defaults to
 * narrow the visible gap to professional tools — `imagecopyresampled`, JPEG quality 90,
 * and a mild unsharp-mask after each downscale; the per-image `showOriginal` /
 * per-profile `preserveOriginal` escape hatch (handled by the caller) covers the cases
 * where even that is not sharp enough.
 *
 * Downscale only: a spec wider than the source produces a variant at the source size,
 * never an upscaled (blurry) one. Source format is preserved (v1).
 */
final class GdImageProcessor implements ImageProcessor
{
    private const JPEG_QUALITY = 90;
    private const WEBP_QUALITY = 90;
    private const PNG_COMPRESSION = 6;

    /** Raster formats GD can round-trip; anything else yields no derivatives. */
    private const SUPPORTED = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    public function generate(string $bytes, string $sourceExt, array $specs): array
    {
        $ext = strtolower(ltrim($sourceExt, '.'));
        if (!in_array($ext, self::SUPPORTED, true)) {
            return [];
        }

        // Memory guard BEFORE decoding: the decoded bitmap scales with pixels, not file
        // size (a 24-MP photo alone is ~120 MB). If it cannot fit, produce no derivatives
        // instead of fataling mid-save — the document still stores and serves its
        // original, the same graceful path as an unsupported format.
        $dims = @getimagesizefromstring($bytes); // header read, cheap
        if ($dims === false || !$this->fitsMemory((int) $dims[0], (int) $dims[1], $specs)) {
            return [];
        }

        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return [];
        }

        $sw = imagesx($src);
        $sh = imagesy($src);

        $out = [];
        try {
            foreach ($specs as $spec) {
                [$destW, $destH, $cropW, $cropH, $cropX, $cropY] = $this->geometry($sw, $sh, $spec);

                $dst = imagecreatetruecolor($destW, $destH);
                $this->preserveAlpha($dst, $ext);
                imagecopyresampled($dst, $src, 0, 0, $cropX, $cropY, $destW, $destH, $cropW, $cropH);

                // Sharpen only when the image was actually scaled down.
                if ($destW < $cropW || $destH < $cropH) {
                    $this->unsharp($dst);
                }

                $out[$spec->name] = new ProcessedVariant(
                    name: $spec->name,
                    bytes: $this->encode($dst, $ext),
                    width: $destW,
                    height: $destH,
                    ext: $ext,
                );
                imagedestroy($dst);
            }
        } finally {
            imagedestroy($src);
        }

        return $out;
    }

    /**
     * Compute destination size and the source crop rectangle for one spec.
     * `contain` fits within the box (downscale-only); `cover` (needs height)
     * centre-crops to the box aspect, then scales down to the box.
     *
     * @return array{0:int,1:int,2:int,3:int,4:int,5:int} [destW, destH, cropW, cropH, cropX, cropY]
     */
    private function geometry(int $sw, int $sh, VariantSpec $spec): array
    {
        if ($spec->fit === VariantSpec::FIT_COVER && $spec->height !== null) {
            $boxAspect = $spec->width / $spec->height;

            $cropW = $sw;
            $cropH = (int) round($sw / $boxAspect);
            if ($cropH > $sh) {
                $cropH = $sh;
                $cropW = (int) round($sh * $boxAspect);
            }
            $cropX = (int) (($sw - $cropW) / 2);
            $cropY = (int) (($sh - $cropH) / 2);

            $destW = min($spec->width, $cropW);
            $destH = min($spec->height, $cropH);

            return [max(1, $destW), max(1, $destH), $cropW, $cropH, $cropX, $cropY];
        }

        // contain
        $maxW  = $spec->width;
        $maxH  = $spec->height ?? PHP_INT_MAX;
        $scale = min($maxW / $sw, $maxH / $sh, 1.0);

        $destW = max(1, (int) round($sw * $scale));
        $destH = max(1, (int) round($sh * $scale));

        return [$destW, $destH, $sw, $sh, 0, 0];
    }

    /**
     * Whether decoding + processing fits the remaining memory budget: GD holds the
     * decoded source (~5 bytes/px incl. overhead) plus, per variant, the destination
     * bitmap AND `imageconvolution`'s internal working copy (the unsharp step clones
     * the image). Compared against the remaining `memory_limit` with a 20% safety
     * margin; an unlimited limit (-1) always fits.
     *
     * The budget is measured from USED bytes (`memory_get_usage(false)`), not reserved:
     * `(true)` includes ZendMM's freed-but-cached chunks, which stay elevated after a
     * prior variant generation in the same worker (php -S dev, LSAPI on cyon) although
     * the allocator reuses them — a `(true)`-based check refuses images that process
     * fine (DMS-MEM-001, review 2026-07-16).
     *
     * @param list<VariantSpec> $specs
     */
    private function fitsMemory(int $sw, int $sh, array $specs): bool
    {
        $limit = $this->memoryLimitBytes();
        if ($limit <= 0) {
            return true; // -1 = unlimited
        }

        $largestDest = 0;
        foreach ($specs as $spec) {
            [$destW, $destH] = $this->geometry($sw, $sh, $spec);
            $largestDest = max($largestDest, $destW * $destH);
        }

        $needed    = ($sw * $sh + 2 * $largestDest) * 5;
        $available = $limit - memory_get_usage(false);

        return $needed <= (int) ($available * 0.8);
    }

    /** `memory_limit` in bytes; 0 = unlimited. Parses the K/M/G shorthand. */
    private function memoryLimitBytes(): int
    {
        $raw = ini_get('memory_limit');
        if ($raw === false || $raw === '' || $raw === '-1') {
            return 0;
        }
        $value = (int) $raw;

        return match (strtoupper(substr(trim($raw), -1))) {
            'G'     => $value * 1024 ** 3,
            'M'     => $value * 1024 ** 2,
            'K'     => $value * 1024,
            default => $value,
        };
    }

    private function preserveAlpha(\GdImage $img, string $ext): void
    {
        if (!in_array($ext, ['png', 'webp', 'gif'], true)) {
            return;
        }
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefill($img, 0, 0, $transparent);
    }

    /**
     * Mild unsharp mask (centre 3, edges -0.5, divisor 1) — sharpens downscaled
     * detail without the haloing a stronger kernel produces.
     */
    private function unsharp(\GdImage $img): void
    {
        $kernel = [
            [0.0, -0.5, 0.0],
            [-0.5, 3.0, -0.5],
            [0.0, -0.5, 0.0],
        ];
        @imageconvolution($img, $kernel, 1.0, 0.0);
    }

    private function encode(\GdImage $img, string $ext): string
    {
        ob_start();
        match ($ext) {
            'png'  => imagepng($img, null, self::PNG_COMPRESSION),
            'webp' => imagewebp($img, null, self::WEBP_QUALITY),
            'gif'  => imagegif($img),
            default => imagejpeg($img, null, self::JPEG_QUALITY), // jpg / jpeg
        };

        return (string) ob_get_clean();
    }
}
