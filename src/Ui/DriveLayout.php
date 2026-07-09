<?php
namespace Z77\Module\Dms\Ui;

/**
 * Layout config for a host that mounts the DMS Drive fragment (ADR-018 / ADR-019).
 *
 * A host's `Ui/Config/Documents/driveControllerConfig.inc.php` delegates to
 * {@see config()} (one line: `return \Z77\Module\Dms\Ui\DriveLayout::config();`). It pins
 * the page body to the Drive's `listAction` template in `module-dms` — REQUIRED, because
 * `LayoutManager::initialize()` would otherwise look for the action template in the host
 * namespace (where it no longer lives) and fail. CSS/JS are registered imperatively in
 * {@see DriveControllerTrait::listAction} (host-agnostic already), so they are not repeated
 * here; the host keeps its own skeleton (no `documentTpl` override).
 */
final class DriveLayout
{
    /** Namespace that owns the fragment's templates (ADR-018). */
    public const NS = 'Z77\\Module\\Dms';

    /** @return array<string, mixed> */
    public static function config(): array
    {
        return [
            'levelElements' => [
                'body' => [
                    'main' => [[
                        'nameSpace' => self::NS,
                        'path'      => 'Documents/DriveController',
                        'name'      => 'listAction',
                    ]],
                ],
            ],
        ];
    }
}
