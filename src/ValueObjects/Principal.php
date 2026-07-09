<?php

namespace Z77\Module\Dms\ValueObjects;

use Z77\Core\Config\AuthRole;
use Z77\Shared\Auth\AuthUser;

/**
 * The acting subject for an authorization check (DMS, ADR-017 / R2). A flat, testable
 * snapshot of who is asking: a user id (0 = not authenticated / guest) and the role
 * names that user holds. Built from the session-backed {@see AuthUser} in real requests
 * ({@see fromAuthUser}), or constructed directly in tests — so the policy
 * ({@see \Z77\Module\Dms\Services\AclService}) never needs a live session.
 */
final class Principal
{
    /** @param string[] $roles */
    public function __construct(
        public readonly int $userId,
        public readonly array $roles,
    ) {}

    public static function fromAuthUser(AuthUser $user): self
    {
        return new self($user->getId(), $user->getRoles());
    }

    /**
     * SUPER_USER → ACL bypass (ADR-021). Only the top hierarchy level (100) bypasses —
     * an `admin` (80) is a normal principal managed via grants (D3a).
     */
    public function isSuperUser(): bool
    {
        $hierarchy  = AuthRole::getRoleHierarchy();
        $superLevel = $hierarchy[AuthRole::SUPER_USER] ?? PHP_INT_MAX;
        $max        = 0;
        foreach ($this->roles as $role) {
            $max = max($max, $hierarchy[$role] ?? 0);
        }
        return $max >= $superLevel;
    }

    /** A real, logged-in user (owner/user-ACE checks only apply when true). */
    public function isAuthenticated(): bool
    {
        return $this->userId > 0;
    }
}
