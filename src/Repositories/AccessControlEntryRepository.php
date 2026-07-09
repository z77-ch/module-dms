<?php

namespace Z77\Module\Dms\Repositories;

use Z77\Persistence\File\Repository\FileRepository;
use Z77\Module\Dms\Entities\AccessControlEntry;

/**
 * ACE metadata access (DMS, ADR-017 / R1). Returns raw matches — the right-union,
 * ancestor walk, owner/admin bypass, and `active` gate are policy and live in
 * `AclService` (R2). Auto-wired by name (`{Entity}Repository`) via the entity manager.
 */
class AccessControlEntryRepository extends FileRepository
{
    /**
     * All ACEs attached to one resource.
     *
     * @return AccessControlEntry[]
     */
    public function findByResource(string $resourceType, int $resourceId): array
    {
        return $this->findBy(['resource_type' => $resourceType, 'resource_id' => $resourceId]);
    }

    /**
     * All ACEs granted to one subject (a user id or a role name).
     *
     * @return AccessControlEntry[]
     */
    public function findForSubject(string $subjectType, string $subjectId): array
    {
        return $this->findBy(['subject_type' => $subjectType, 'subject_id' => $subjectId]);
    }
}
