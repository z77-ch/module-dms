<?php

namespace Z77\Module\Dms\Images;

/**
 * One named image size within an {@see ImageProfile} (OPEN-8). Immutable value object,
 * built from a module's `imageProfilesConfig.inc.php`. The {@see $name} doubles as the
 * blob variant name, so it is constrained to the same charset the blob store accepts
 * ({@see \Z77\Module\Dms\Blob\BlobStorage}) — a profile typo can never produce an
 * unstorable variant.
 *
 * `width` is mandatory (the responsive `srcset` width). `height` is optional: with only
 * a width the image is scaled proportionally; with both, {@see $fit} decides between
 * letterbox-fit (`contain`) and crop-to-fill (`cover`). `format` is null in v1 (= keep
 * the source format); a later opt-in sets `webp`/`avif` per variant.
 */
final class VariantSpec
{
    public const FIT_CONTAIN = 'contain';
    public const FIT_COVER   = 'cover';

    public function __construct(
        public readonly string $name,
        public readonly int $width,
        public readonly ?int $height = null,
        public readonly string $fit = self::FIT_CONTAIN,
        public readonly ?string $format = null,
    ) {
        if (!preg_match('/^[a-z0-9_-]+$/i', $name)) {
            throw new \InvalidArgumentException("VariantSpec: invalid variant name '{$name}'.");
        }
        if ($width <= 0) {
            throw new \InvalidArgumentException("VariantSpec '{$name}': width must be positive, got {$width}.");
        }
        if ($height !== null && $height <= 0) {
            throw new \InvalidArgumentException("VariantSpec '{$name}': height must be positive or absent, got {$height}.");
        }
        if ($fit !== self::FIT_CONTAIN && $fit !== self::FIT_COVER) {
            throw new \InvalidArgumentException("VariantSpec '{$name}': fit must be 'contain' or 'cover', got '{$fit}'.");
        }
    }

    /**
     * Build a spec from one config entry, e.g. `fromConfig('thumb', ['w' => 200, 'h' => 200, 'fit' => 'cover'])`.
     */
    public static function fromConfig(string $name, array $cfg): self
    {
        if (!isset($cfg['w'])) {
            throw new \InvalidArgumentException("VariantSpec '{$name}': config is missing required width 'w'.");
        }

        return new self(
            name: $name,
            width: (int) $cfg['w'],
            height: isset($cfg['h']) ? (int) $cfg['h'] : null,
            fit: (string) ($cfg['fit'] ?? self::FIT_CONTAIN),
            format: isset($cfg['format']) ? (string) $cfg['format'] : null,
        );
    }
}
