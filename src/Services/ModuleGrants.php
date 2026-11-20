<?php

namespace Z77\Module\Dms\Services;

use Z77\Core\DI;
use Z77\Persistence\File\Storage\FileStorage;

/**
 * The grants a module DECLARES on its DMS partition, applied ONCE per installation
 * (documents.md DMS-MODULE-ACL-001, owner 2026-11-20). A module names them in its config — the
 * DMS names no consumer:
 *
 *     'dmsPartitionGrants' => [self::MODULE_KEY => [AuthRole::ADMIN => 'read']],
 *
 * — DMS module key (the key it passes to {@see DocumentService::forModule()}, remapped by
 * `dmsConfig['moduleFolders']` like every write) → role → `read|write|manage`. A module DECLARES,
 * the DMS applies, nothing is scanned (the `entryDocuments` pattern).
 *
 * When:
 *   - {@see ensureCreated()}: the moment `forModule()` CREATES the partition — a new partition
 *     gets its grants at once;
 *   - {@see ensure()}: on the first DMS use of a process ({@see DocumentService::create()}) — an
 *     EXISTING partition gets a declaration that is new to it, so an installation that existed
 *     before the declaration receives it with the next deploy, no setup step. A partition that
 *     does not exist yet is left for the create.
 *
 * Once: every applied grant is recorded in `data/documents/module_grants.json`
 * (`<partition key>|<role>|<right>`). A recorded grant is never applied again — a person who
 * lowers or removes it in the Drive keeps that decision (the rule of
 * {@see ModuleDrive::grantRole()}: a module sets up, it never re-decides). A HIGHER right
 * declared later is a new entry and is applied (grantRole never lowers). A partition created
 * anew is set up anew, whatever the record says.
 *
 * Trusted system path (no session principal), the same as `ModuleDrive::grantRole()`. Never
 * throws: a failure is logged (`dms module grants: …`) and the DMS goes on — the grant is then
 * tried again by the next process.
 */
final class ModuleGrants
{
    public const CONFIG_KEY = 'dmsPartitionGrants';

    /** The record of applied grants, below `data/`. */
    public const RECORD = 'documents/module_grants.json';

    private const RIGHTS = ['read', 'write', 'manage'];

    private static bool $checked = false;

    /** Apply every declared grant not yet recorded — once per process. */
    public static function ensure(DocumentService $documents): void
    {
        if (self::$checked) {
            return;
        }
        self::$checked = true;  // first: the apply builds DocumentService itself again
        try {
            $declared = self::declared();
            if ($declared === []) {
                return;
            }
            $storage = new FileStorage();
            if (self::open($declared, $storage->load(self::RECORD), $documents) === []) {
                return;  // the usual case: everything recorded (or no partition yet) — no lock, no write
            }
            $storage->withExclusiveLock(self::RECORD, static function () use ($storage, $declared, $documents): void {
                $record = $storage->load(self::RECORD);
                $open   = self::open($declared, $record, $documents);
                foreach ($open as $key => [$drive, $moduleKey, $role, $rights]) {
                    $drive->grantRole($role, $rights);
                    $record[$key] = ['module' => $moduleKey, 'at' => gmdate('c')];
                }
                if ($open !== []) {
                    $storage->save(self::RECORD, $record);
                }
            });
        } catch (\Throwable $e) {
            error_log('dms module grants: ' . get_class($e) . ': ' . $e->getMessage());
        }
    }

    /** A partition `forModule()` created just now: its module's declared grants, recorded or not. */
    public static function ensureCreated(string $moduleKey, ModuleDrive $drive): void
    {
        try {
            $grants = self::declared()[$moduleKey] ?? [];
            if ($grants === []) {
                return;
            }
            $partition = (string) $drive->root()->getKey();
            $storage   = new FileStorage();
            $storage->withExclusiveLock(self::RECORD, static function () use ($storage, $grants, $drive, $moduleKey, $partition): void {
                $record = $storage->load(self::RECORD);
                foreach ($grants as $role => $rights) {
                    $drive->grantRole($role, $rights);
                    $record[self::key($partition, $role, $rights)] = ['module' => $moduleKey, 'at' => gmdate('c')];
                }
                $storage->save(self::RECORD, $record);
            });
        } catch (\Throwable $e) {
            error_log('dms module grants: ' . get_class($e) . ': ' . $e->getMessage());
        }
    }

    /**
     * Every module's declaration, merged — DMS module key → role → right. A malformed entry
     * fails here, naming its module (logged by the caller, nothing applied).
     *
     * @return array<string, array<string, string>>
     */
    public static function declared(): array
    {
        $modules = DI::getModuleManager();
        $out     = [];
        foreach ($modules->getModuleKeys() as $module) {
            $declared = $modules->getModuleConfig($module)?->get(self::CONFIG_KEY, []) ?? [];
            if (!is_array($declared)) {
                throw new \RuntimeException(self::CONFIG_KEY . " of module '{$module}' must be an array.");
            }
            foreach ($declared as $moduleKey => $grants) {
                if (!is_string($moduleKey) || !is_array($grants)) {
                    throw new \RuntimeException(self::CONFIG_KEY . " of module '{$module}' must map a DMS module key to [role => right].");
                }
                foreach ($grants as $role => $rights) {
                    if (!is_string($role) || !in_array($rights, self::RIGHTS, true)) {
                        throw new \RuntimeException(self::CONFIG_KEY . " of module '{$module}': '{$moduleKey}' needs role => read|write|manage.");
                    }
                    $out[$moduleKey][$role] = $rights;
                }
            }
        }

        return $out;
    }

    /**
     * The declared grants not yet recorded, on partitions that EXIST (one that does not is left
     * for {@see ensureCreated()}).
     *
     * @param array<string, array<string, string>> $declared
     * @return array<string, array{0: ModuleDrive, 1: string, 2: string, 3: string}> record key → [drive, module key, role, right]
     */
    private static function open(array $declared, array $record, DocumentService $documents): array
    {
        $open = [];
        foreach ($declared as $moduleKey => $grants) {
            $partition = $documents->moduleTargetKey($moduleKey);
            $drive     = null;
            foreach ($grants as $role => $rights) {
                $key = self::key($partition, $role, $rights);
                if (isset($record[$key])) {
                    continue;
                }
                $drive ??= $documents->findModule($moduleKey);
                if ($drive === null) {
                    continue 2;
                }
                $open[$key] = [$drive, $moduleKey, $role, $rights];
            }
        }

        return $open;
    }

    private static function key(string $partition, string $role, string $rights): string
    {
        return $partition . '|' . $role . '|' . $rights;
    }
}
