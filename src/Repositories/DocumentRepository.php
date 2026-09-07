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

    /**
     * Next free `sortKey` among the folder's LIVE documents — a new or moved document
     * lands last (append semantics). Shared by `SaveService::save` and
     * `DocumentService::move` so there is exactly one definition of "the end".
     */
    public function nextSortKey(?int $folderId): int
    {
        $max = -1;
        foreach ($this->findByFolder($folderId) as $sibling) {
            if (!$sibling->isDeleted()) {
                $max = max($max, $sibling->getSortKey());
            }
        }

        return $max + 1;
    }
}
