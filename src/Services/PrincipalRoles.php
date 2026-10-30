<?php

namespace Z77\Module\Dms\Services;

use Z77\Core\Config\AuthRole;
use Z77\Core\DI;
use Z77\Shared\Auth\AuthUser;

/**
 * Extra DMS roles a PROJECT grants to a non-backend identity (F1, z77-rudaz.ch
 * 2026-10-30).
 *
 * The DMS sees a member only through its framework roles (`customer`), which every
 * member shares — so a role ACE can only ever open a folder to ALL members. A project
 * that separates members by its own unit (a tenant, a project) answers here which
 * additional role names THIS identity holds, e.g. `rudaz-p12-write`; the folder of
 * that unit carries ACEs for exactly those names. The decision stays in the DMS
 * (Authz / AclService / the /media gate) — the project only names roles.
 *
 * The seam: `dmsConfig['principalRolesHook']` = FQCN of an invokable
 *   `__invoke(AuthUser $user): list<string>`
 * called for NON-backend identities only (backend users act through user ACEs and
 * their own roles). No hook = no extra roles = the DMS behaves exactly as before.
 *
 * ⚠️ What a hook can NOT do — enforced here, not trusted to the project:
 *  - name a FRAMEWORK role (`admin`, `superUser`, `member`, …): every name in the
 *    AuthRole hierarchy is dropped, so a hook can never lift a member to a level;
 *  - inject odd names: only `[a-z0-9][a-z0-9._-]{0,99}` passes;
 *  - fail open: a hook that throws or returns garbage yields NO extra roles.
 *
 * Memoized per request and identity: Authz::current() builds a principal per check,
 * and a list filter checks once per row.
 */
final class PrincipalRoles
{
    private const NAME = '/^[a-z0-9][a-z0-9._-]{0,99}$/';

    /** @var array<string, list<string>> */
    private static array $memo = [];

    /**
     * The extra roles for this identity — [] for a backend user, for an
     * identity without id, and when the installation sets no hook.
     *
     * @return list<string>
     */
    public static function for(AuthUser $user): array
    {
        if ($user->isBackendRealm()) {
            return [];
        }
        $id = (string)$user->getId();
        if ($id === '' || $id === '0') {
            return [];
        }

        $key = $user->getRealm() . ':' . $id;
        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }

        return self::$memo[$key] = self::fromHook(self::hookClass(), $user);
    }

    /**
     * Calls the hook and cleans its answer. Public for the harness: the call and
     * the filter are tested without DI.
     *
     * @return list<string>
     */
    public static function fromHook(string $fqcn, AuthUser $user): array
    {
        if ($fqcn === '' || !class_exists($fqcn)) {
            return [];
        }

        try {
            $raw = (new $fqcn())($user);
        } catch (\Throwable $e) {
            error_log('DMS principalRolesHook failed (' . $fqcn . '): ' . $e->getMessage());
            return [];
        }

        return self::sanitize(is_array($raw) ? $raw : [], array_keys(AuthRole::getRoleHierarchy()));
    }

    /**
     * Keeps well-formed, non-reserved, unique names in the hook's order.
     *
     * @param array<mixed>  $raw
     * @param list<string>  $reserved framework role names — never grantable here
     * @return list<string>
     */
    public static function sanitize(array $raw, array $reserved): array
    {
        // Compared case-insensitively: `superuser` is not `superUser` to the ACL,
        // but a name that only differs in case from a framework role has no
        // business in a project's vocabulary either.
        $reserved = array_map('strtolower', $reserved);
        $out      = [];
        foreach ($raw as $name) {
            if (!is_string($name)) {
                continue;
            }
            $name = trim($name);
            if (preg_match(self::NAME, $name) !== 1 || in_array(strtolower($name), $reserved, true) || in_array($name, $out, true)) {
                continue;
            }
            $out[] = $name;
        }
        return $out;
    }

    /** Forget memoized answers — for the harness and long-running CLI processes. */
    public static function reset(): void
    {
        self::$memo = [];
    }

    private static function hookClass(): string
    {
        return (string)DI::getConfigManager()
            ->getArrayConfig('App/Config/dmsConfig', 'Z77\\Module\\Dms')
            ->get('principalRolesHook', '');
    }
}
