<?php

namespace Z77\Module\Dms\Images;

use Z77\Core\DI;

/**
 * Resolves image profiles (OPEN-8) **partition-scoped** from ONE project-owned config:
 * the DMS `App/Config/imageProfilesConfig.inc.php`, provided by the PROJECT via the
 * override tree (`override/z77/module/dms/...` — the framework package ships none).
 * Structure is two-level: `partitionIdent => profileName => variantSpecs`, where the
 * partition ident is the root folder's `key ?? slug` (ADR-020 rev.: profiles are
 * project-specific DMS management data, not module code — a keyless, human-created
 * partition like `front` is addressed by its slug, the same pattern as the Drive's
 * `?key=` mount resolution). The per-partition namespace keeps the collision safety of
 * the former module-owned files (`front`'s `logo` ≠ `back`'s `logo`) in one file.
 *
 * The profile name `default` is the per-partition fallback: the save path uses it when
 * neither the target folder nor an ancestor carries a `Folder::$profile` assignment.
 *
 * The `admin` profile is **framework-fixed**, not project config: it provides the
 * management tool's list-thumbnail + preview-pane sizes and is generated for every image
 * so the tool is universally browsable. A project cannot remove or override it.
 *
 * Not a DI singleton (placement decision B): the consumer (save path / Drive UI) builds
 * it via {@see fromConfig()} at the boundary. The plain constructor takes already
 * resolved profiles and stays dependency-free for isolated testing.
 */
final class ImageProfileRegistry
{
    /** Framework-fixed profile name for the management tool's previews. */
    public const ADMIN = 'admin';

    /** Per-partition fallback profile name (used when no folder carries an assignment). */
    public const DEFAULT = 'default';

    /** The single small thumbnail always produced, even when the original is preserved. */
    public const ADMIN_THUMB = 's';

    /** The Drive preview-pane size — kept alongside the thumb when a project profile applies. */
    public const ADMIN_PREVIEW = 'm';

    /** Built-in `admin` profile widths (framework-fixed, OPEN-8). */
    private const ADMIN_VARIANTS = [
        's'  => ['w' => 160],
        'm'  => ['w' => 480],
        'l'  => ['w' => 1024],
        'xl' => ['w' => 2048],
    ];

    private ImageProfile $admin;

    /**
     * @param array<string, array<string, ImageProfile>> $byPartition partitionIdent => (name => profile)
     */
    public function __construct(private array $byPartition = [])
    {
        $this->admin = ImageProfile::fromConfig(self::ADMIN, self::ADMIN_VARIANTS);
    }

    /**
     * Build the registry from the project's DMS profile config
     * (`App/Config/imageProfilesConfig.inc.php` in the `Z77\Module\Dms` namespace — the
     * project override wins per FileFinder source order). A missing file simply means
     * "no project profiles" (only `admin` resolves).
     */
    public static function fromConfig(): self
    {
        $config = DI::getConfigManager()->getArrayConfig(
            configName: 'App/Config/imageProfilesConfig',
            nameSpace: 'Z77\\Module\\Dms\\',
            throwError: false,
        );

        $byPartition = [];
        foreach ($config->getAll() as $partitionIdent => $profilesCfg) {
            if (!is_array($profilesCfg)) {
                throw new \InvalidArgumentException(
                    "imageProfilesConfig: partition '{$partitionIdent}' must be an array of profiles."
                );
            }
            $profiles = [];
            foreach ($profilesCfg as $profileName => $profileCfg) {
                if (!is_array($profileCfg)) {
                    throw new \InvalidArgumentException(
                        "imageProfilesConfig ({$partitionIdent}): profile '{$profileName}' must be a config array."
                    );
                }
                if ($profileName === self::ADMIN) {
                    throw new \InvalidArgumentException(
                        "imageProfilesConfig ({$partitionIdent}): 'admin' is a framework-fixed profile and cannot be redefined."
                    );
                }
                $profiles[$profileName] = ImageProfile::fromConfig((string) $profileName, $profileCfg);
            }
            if ($profiles !== []) {
                $byPartition[(string) $partitionIdent] = $profiles;
            }
        }

        return new self($byPartition);
    }

    /**
     * The framework-fixed management-tool profile.
     */
    public function admin(): ImageProfile
    {
        return $this->admin;
    }

    /**
     * Resolve a profile by name within a partition (root `key ?? slug`, ADR-020 rev.).
     * `admin` resolves globally; any other name is looked up under the partition ident.
     * Returns null if unknown.
     */
    public function get(string $partitionIdent, string $name): ?ImageProfile
    {
        if ($name === self::ADMIN) {
            return $this->admin;
        }

        return $this->byPartition[$partitionIdent][$name] ?? null;
    }

    public function has(string $partitionIdent, string $name): bool
    {
        return $this->get($partitionIdent, $name) !== null;
    }

    /**
     * The project profile names available in a partition (for the Drive's folder-edit
     * dropdown). Empty when the partition has no config block.
     *
     * @return list<string>
     */
    public function names(string $partitionIdent): array
    {
        return array_keys($this->byPartition[$partitionIdent] ?? []);
    }
}
