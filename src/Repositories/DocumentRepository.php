<?php

namespace Z77\Module\Dms\Repositories;

use Z77\Persistence\File\Repository\FileRepository;
use Z77\Module\Dms\Entities\Document;

/**
 * Document metadata access. Soft-delete and access gating are policy and live in the
 * service layer (`DocumentService` / `AclService`) — these methods return raw matches;
 * the caller decides whether to exclude `deletedAt`-marked rows.
 */
class DocumentRepository extends FileRepository
{
    /**
     * @return Document[]
     */
    public function findByFolder(?int $folderId): array
    {
        return $this->findBy(['folder_id' => $folderId]);
    }
}
