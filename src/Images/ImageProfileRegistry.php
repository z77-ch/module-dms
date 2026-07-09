<?php

namespace Z77\Module\Dms\Images;

use Z77\Core\DI;

/**
 * Resolves image profiles (OPEN-8) **module-scoped**: every module that needs images ships
 * its own `App/Config/imageProfilesConfig.inc.php`, and a profile is looked up under the
 * module's key — which is the partition root's `key` (ADR-020): a document's module is
 * the `key` of the root folder its chain leads to. This honours the rule "config lives in
 * the module that needs it" and structurally prevents cross-module name collisions
 * (frontend's `logo` ≠ backend's `logo`). Documents under a keyless (human-created) root
 * have no module profiles — only the framework-fixed `admin` profile resolves (S7).
 *
 * The `admin` profile is **framework-fixed**, not project config: it provides the
 * management tool's list-thumbnail + preview-pane sizes and is generated for every image
 * so the tool is universally browsable. A project cannot remove or override it.
 *
 * Not a DI singleton (placement decision B): the consumer (save path, Phase 3) builds it
 * once via {@see fromModules()} at the boundary. The plain constructor takes already
 * resolved profiles and stays dependency-free for isolated testing.
 */
final class ImageProfileRegistry
{
    /** Framework-fixed profile name for the management tool's previews. */
    public const ADMIN = 'admin';

    /** The single small thumbnail always produced, even when the original is preserved. */
    public const ADMIN_THUMB = 's';

    /** Built-in `admin` profile widths (framework-fixed, OPEN-8). */
    private const ADMIN_VARIANTS = [
        's'  => ['w' => 160],
        'm'  => ['w' => 480],
        'l'  => ['w' => 1024],
        'xl' => ['w' => 2048],
    ];

    private ImageProfile $admin;

    /**
     * @param array<string, array<string, ImageProfile>> $byModule moduleKey => (name => profile)
     */
    public function __construct(private array $byModule = [])
    {
        $this->admin = ImageProfile::fromConfig(self::ADMIN, self::ADMIN_VARIANTS);
    }

    /**
     * Build the registry by reading each module's optional
     * `App/Config/imageProfilesConfig.inc.php`. A module without the file simply
     * contributes no project profiles.
     */
    public static function fromModules(): self
    {
        $moduleManager = DI::getModuleManager();
        $configManager = DI::getConfigManager();

        $byModule = [];
        foreach ($moduleManager->getModuleKeys() as $moduleKey) {
            $nameSpace = $moduleManager->getNamespacePrefix($moduleKey);

            $config = $configManager->getArrayConfig(
                configName: 'App/Config/imageProfilesConfig',
                nameSpace: $nameSpace,
                throwError: false,
            );

            $profiles = [];
            foreach ($config->getAll() as $profileName => $profileCfg) {
                if (!is_array($profileCfg)) {
                    throw new \InvalidArgumentException(
                        "imageProfilesConfig ({$moduleKey}): profile '{$profileName}' must be a config array."
                    );
                }
                if ($profileName === self::ADMIN) {
                    throw new \InvalidArgumentException(
                        "imageProfilesConfig ({$moduleKey}): 'admin' is a framework-fixed profile and cannot be redefined."
                    );
                }
                $profiles[$profileName] = ImageProfile::fromConfig((string) $profileName, $profileCfg);
            }

            if ($profiles !== []) {
                $byModule[$moduleKey] = $profiles;
            }
        }

        return new self($byModule);
    }

    /**
     * The framework-fixed management-tool profile.
     */
    public function admin(): ImageProfile
    {
        return $this->admin;
    }

    /**
     * Resolve a profile by name within a module (= partition-root `key`, ADR-020).
     * `admin` resolves globally; any other name is looked up under the given module key.
     * Returns null if unknown.
     */
    public function get(string $moduleKey, string $name): ?ImageProfile
    {
        if ($name === self::ADMIN) {
            return $this->admin;
        }

        return $this->byModule[$moduleKey][$name] ?? null;
    }

    public function has(string $moduleKey, string $name): bool
    {
        return $this->get($moduleKey, $name) !== null;
    }
}
