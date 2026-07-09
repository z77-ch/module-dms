<?php

namespace Z77\Module\Dms\Entities;

use Z77\Shared\Attributes\Entity;
use Z77\Shared\Traits\ArrayMappable;

/**
 * One access-control entry (ACE) for the DMS (ADR-017 / R1). Grants ONE right level
 * to ONE subject on ONE resource. The effective permission of a principal is the union
 * of all ACEs on the document plus every ancestor folder whose subject matches the
 * principal's user id or one of its roles — that union logic lives in `AclService`
 * (R2), not here. ACEs are only consulted for `deliveryMode = protected`; `public` is a
 * delivery mode, not an ACE (no `guest` subject — ADR-017).
 *
 * Collection-mode entity (auto-increment `id`). All fields are server-controlled — an
 * ACE is created via the `grant()` façade (R5), never from an edit form, so none carry
 * `#[Clean]`. The blob/byte world is untouched; this is metadata only.
 */
#[Entity('file', 'documents/access_control.json', invalidatesCache: true)]
class AccessControlEntry
{
    use ArrayMappable;

    public function __construct(array $data = [])
    {
        if ($data) {
            $this->mapFromArray($data);
        }
    }

    private ?int $id = null;

    /** `folder` | `document` — which kind of resource this ACE is attached to. */
    private string $resourceType = '';

    /** Id of the folder or document the ACE applies to. */
    private int $resourceId = 0;

    /** `user` | `role` — the principal dimension this ACE grants to. */
    private string $subjectType = '';

    /** User id (stringified) or role name, depending on `subjectType`. */
    private string $subjectId = '';

    /** Granted level: `read` | `write` | `manage` (ladder; union/implication is AclService policy). */
    private string $rights = 'read';

    private ?int $createdBy = null;
    private ?string $createdAt = null;

    public function getId(): ?int { return $this->id; }
    public function getResourceType(): string { return $this->resourceType; }
    public function getResourceId(): int { return $this->resourceId; }
    public function getSubjectType(): string { return $this->subjectType; }
    public function getSubjectId(): string { return $this->subjectId; }
    public function getRights(): string { return $this->rights; }
    public function getCreatedBy(): ?int { return $this->createdBy; }
    public function getCreatedAt(): ?string { return $this->createdAt; }

    public function setResourceType(string $resourceType): void
    {
        $this->resourceType = in_array($resourceType, ['folder', 'document'], true) ? $resourceType : '';
    }
    public function setResourceId(int $resourceId): void { $this->resourceId = max(0, $resourceId); }
    public function setSubjectType(string $subjectType): void
    {
        $this->subjectType = in_array($subjectType, ['user', 'role'], true) ? $subjectType : '';
    }
    public function setSubjectId(string $subjectId): void { $this->subjectId = $subjectId; }
    public function setRights(string $rights): void
    {
        $this->rights = in_array($rights, ['read', 'write', 'manage'], true) ? $rights : 'read';
    }
    public function setCreatedBy(?int $createdBy): void
    {
        $this->createdBy = ($createdBy !== null && $createdBy > 0) ? $createdBy : null;
    }
    public function setCreatedAt(?string $createdAt): void { $this->createdAt = $createdAt ?: null; }
}
