<?php

namespace Z77\Module\Dms\Images;

/**
 * A named set of image sizes (OPEN-8) — e.g. `slider` → {mobile, tablet, desktop}.
 * Immutable value object assembled from a module's `imageProfilesConfig.inc.php`.
 *
 * `preserveOriginal` is the profile-level counterpart of the per-document
 * `showOriginal` flag: when true, images saved under this profile are NOT reprocessed
 * through GD — the original bytes are served untouched (only the framework `admin`
 * thumbnail is generated for the management tool). Use it when a profile's images are
 * always hand-prepared (sharp hero/slider artwork).
 */
final class ImageProfile
{
    /** Reserved config key that flags the profile, not a variant. */
    private const KEY_PRESERVE_ORIGINAL = 'preserveOriginal';

    /**
     * @param array<string, VariantSpec> $variants keyed by variant name
     */
    public function __construct(
        public readonly string $name,
        public readonly array $variants,
        public readonly bool $preserveOriginal = false,
    ) {
        if ($variants === [] && !$preserveOriginal) {
            throw new \InvalidArgumentException(
                "ImageProfile '{$name}': has no variants and does not preserve the original — it would generate nothing."
            );
        }
    }

    /**
     * Build a profile from its config block, e.g.
     * `fromConfig('slider', ['mobile' => ['w' => 768], 'desktop' => ['w' => 1920], 'preserveOriginal' => true])`.
     * The reserved `preserveOriginal` key is separated from the variant entries.
     */
    public static function fromConfig(string $name, array $cfg): self
    {
        $preserveOriginal = (bool) ($cfg[self::KEY_PRESERVE_ORIGINAL] ?? false);
        unset($cfg[self::KEY_PRESERVE_ORIGINAL]);

        $variants = [];
        foreach ($cfg as $variantName => $variantCfg) {
            if (!is_array($variantCfg)) {
                throw new \InvalidArgumentException(
                    "ImageProfile '{$name}': variant '{$variantName}' must be a config array."
                );
            }
            $variants[$variantName] = VariantSpec::fromConfig((string) $variantName, $variantCfg);
        }

        return new self($name, $variants, $preserveOriginal);
    }

    public function variant(string $name): ?VariantSpec
    {
        return $this->variants[$name] ?? null;
    }

    /**
     * @return list<string>
     */
    public function variantNames(): array
    {
        return array_keys($this->variants);
    }
}
