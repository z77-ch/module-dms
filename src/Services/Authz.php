<?php

namespace Z77\Module\Dms\Services;

use Z77\Core\DI;
use Z77\Core\Exception\NotFoundException;
use Z77\Module\Dms\ValueObjects\Principal;

/**
 * Domain-enforced authorization for DMS management operations (ADR-017 model, R7).
 *
 * The guard lives here in the domain, NOT in the UI: PHP trait precedence lets a host
 * controller silently override a trait method (even a `final` one), and a host can mount a
 * controller under a public route — so a UI-layer gate is bypassable. A gate on the
 * `DocumentService`/`FolderService` mutation, using the principal read from the **session**
 * (never a caller-supplied one), cannot be bypassed by any controller wiring.
 *
 * GUEST → {@see AuthService::getCurrentUser} yields a guest `AuthUser` (`userId 0`, no admin
 * role) → matches no `ownerId` (real ids > 0) and no member/visitor ACE → `effectiveRight`
 * `none` → rejected. GUEST never owns anything (creation is authenticated).
 *
 * Denials throw {@see NotFoundException} (404) — existence is never leaked, consistent with
 * the delivery path ({@see \Z77\Module\Dms\Ui\Controllers\Media\OutputController}).
 */
final class Authz
{
    public function __construct(private AclService $acl) {}

    public static function create(): self
    {
        return new self(AclService::create());
    }

    /** The current request's principal, derived from the session (never from a caller). */
    public function current(): Principal
    {
        return Principal::fromAuthUser(DI::getAuthService()->getCurrentUser());
    }

    /**
     * Require at least `$level` (`read|write|manage`) on the resource for the current
     * principal (super-user bypass built into {@see AclService::effectiveRight}); 404 otherwise.
     */
    public function require(string $resourceType, int $resourceId, string $level): void
    {
        if (!$this->allows($resourceType, $resourceId, $level)) {
            throw new NotFoundException('Nicht gefunden.');
        }
    }

    /**
     * No-throw check: whether the current principal has at least `$level` on the
     * resource. For read-scoping lists/views (RF-4a) — deny-by-default filtering in the
     * domain, not the UI.
     */
    public function allows(string $resourceType, int $resourceId, string $level): bool
    {
        return $this->acl->hasAccess($this->current(), $resourceType, $resourceId, $level);
    }

    /**
     * Require the current principal to be a SUPER_USER (ADR-021: root/partition-level
     * acts — partition lifecycle + grants on the drive root or a partition).
     */
    public function requireSuperUser(): void
    {
        if (!$this->isSuperUser()) {
            throw new NotFoundException('Nicht gefunden.');
        }
    }

    /** No-throw check: whether the current principal is a SUPER_USER (ADR-021). */
    public function isSuperUser(): bool
    {
        return $this->current()->isSuperUser();
    }
}
